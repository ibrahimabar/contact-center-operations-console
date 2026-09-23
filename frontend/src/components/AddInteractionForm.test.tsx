import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { AddInteractionForm } from '@/components/AddInteractionForm'
import { teamLead } from '@/test/mocks/handlers'
import { renderWithProviders } from '@/test/renderWithProviders'
import { server } from '@/test/mocks/server'

const API_BASE = 'http://localhost:8000'

describe('AddInteractionForm', () => {
  it('reuses the same Idempotency-Key when a failed submit is retried, and clears the form only once it succeeds', async () => {
    const user = userEvent.setup()
    const seenKeys: (string | null)[] = []
    let attempt = 0

    server.use(
      http.post(`${API_BASE}/api/v1/contacts/1/interactions`, async ({ request }) => {
        attempt += 1
        seenKeys.push(request.headers.get('Idempotency-Key'))

        if (attempt === 1) {
          // Simulates the network dropping the response after the server
          // may or may not have received it — exactly the scenario
          // idempotency exists for (see DECISIONS.md #2).
          return HttpResponse.error()
        }

        return HttpResponse.json(
          {
            data: {
              id: 99,
              contact_id: 1,
              type: 'phone',
              timestamp: '2026-01-01T00:00:00Z',
              note: 'Called about renewal',
              created_by: teamLead,
              created_at: '2026-01-01T00:00:00Z',
            },
          },
          { status: 201 },
        )
      }),
    )

    renderWithProviders(<AddInteractionForm contactId={1} />, { user: teamLead })

    const noteBox = screen.getByPlaceholderText(/what happened/i)
    await user.type(noteBox, 'Called about renewal')
    await user.click(screen.getByRole('button', { name: /add interaction/i }))

    await screen.findByText(/could not reach the server/i)
    expect(noteBox).toHaveValue('Called about renewal') // failed submit must not clear the form

    await user.click(screen.getByRole('button', { name: /add interaction/i }))

    await waitFor(() => expect(noteBox).toHaveValue(''))

    expect(attempt).toBe(2)
    expect(seenKeys).toHaveLength(2)
    expect(seenKeys[0]).toBeTruthy()
    expect(seenKeys[0]).toBe(seenKeys[1])
  })
})
