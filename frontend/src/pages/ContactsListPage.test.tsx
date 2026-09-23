import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { ContactsListPage } from '@/pages/ContactsListPage'
import { teamLead } from '@/test/mocks/handlers'
import { renderWithProviders } from '@/test/renderWithProviders'
import { server } from '@/test/mocks/server'
import type { Contact, CursorPage } from '@/types/models'

const API_BASE = 'http://localhost:8000'

function contact(overrides: Partial<Contact>): Contact {
  return {
    id: 1,
    name: 'Placeholder',
    email: 'placeholder@example.com',
    phone: '+15550000000',
    company: null,
    status: 'new',
    assigned_agent_id: null,
    assigned_agent: null,
    last_activity_at: '2026-01-01T00:00:00Z',
    version: 1,
    archived_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): CursorPage<T> {
  return {
    data,
    meta: { path: '', per_page: 20, next_cursor: null, prev_cursor: null },
    links: { first: null, last: null, prev: null, next: null },
  }
}

describe('ContactsListPage', () => {
  it('sends the search text and selected status as query params, and renders only the matching contact the server returns', async () => {
    const user = userEvent.setup()
    const zephyr = contact({ id: 1, name: 'Zephyr Quibble', status: 'active' })
    const unrelated = contact({ id: 2, name: 'Someone Else', status: 'new' })

    let capturedUrl: URL | undefined

    server.use(
      http.get(`${API_BASE}/api/v1/contacts`, ({ request }) => {
        capturedUrl = new URL(request.url)
        // The real API would filter server-side; this mock simulates that
        // by only returning the matching contact once both params arrive —
        // it exists to prove the CLIENT sent the right params, not to
        // re-test the backend's own filtering (that's covered by the Pest
        // suite).
        if (capturedUrl.searchParams.get('q') === 'Zephyr' && capturedUrl.searchParams.get('status') === 'active') {
          return HttpResponse.json(page([zephyr]))
        }
        return HttpResponse.json(page([zephyr, unrelated]))
      }),
    )

    renderWithProviders(<ContactsListPage />, { user: teamLead })

    // Both contacts visible before any filter is applied.
    await screen.findByText('Someone Else')

    await user.type(screen.getByPlaceholderText(/search name, email, phone, company/i), 'Zephyr')
    await user.selectOptions(screen.getByDisplayValue('All statuses'), 'active')

    await waitFor(() => expect(screen.queryByText('Someone Else')).not.toBeInTheDocument())
    expect(screen.getByText('Zephyr Quibble')).toBeInTheDocument()

    expect(capturedUrl?.searchParams.get('q')).toBe('Zephyr')
    expect(capturedUrl?.searchParams.get('status')).toBe('active')
  })

  it('shows an error banner with a working retry when the request fails', async () => {
    const user = userEvent.setup()
    let attempt = 0

    server.use(
      http.get(`${API_BASE}/api/v1/contacts`, () => {
        attempt += 1
        if (attempt === 1) {
          return HttpResponse.json({ message: 'Something broke.', code: 'server_error' }, { status: 500 })
        }
        return HttpResponse.json(page([contact({ id: 1, name: 'Recovered Contact' })]))
      }),
    )

    renderWithProviders(<ContactsListPage />, { user: teamLead })

    await screen.findByText('Something broke.')

    await user.click(screen.getByRole('button', { name: /retry/i }))

    await screen.findByText('Recovered Contact')
    expect(screen.queryByText('Something broke.')).not.toBeInTheDocument()
  })
})
