import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { useListAgentsQuery } from '@/api/agentsApi'
import { useListContactsQuery } from '@/api/contactsApi'
import { toApiError } from '@/api/baseApi'
import { Button } from '@/components/Button'
import { ErrorBanner } from '@/components/ErrorBanner'
import { Spinner } from '@/components/Spinner'
import { StatusBadge } from '@/components/StatusBadge'
import { formatRelative } from '@/lib/format'
import { useDebouncedValue } from '@/lib/useDebouncedValue'
import type { ContactStatus } from '@/types/models'

const STATUSES: ContactStatus[] = ['new', 'active', 'pending', 'resolved']

export function ContactsListPage() {
  const user = useAppSelector((state) => state.auth.user)
  const isTeamLead = user?.role === 'team_lead'

  const [searchParams, setSearchParams] = useSearchParams()
  // The URL is the source of truth for every filter (shareable, survives a
  // refresh, back-button friendly) — see DECISIONS.md "Frontend state". The
  // search box keeps its own immediate-feedback value so typing doesn't
  // refetch on every keystroke, then debounces into the URL.
  const [searchInput, setSearchInput] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchInput, 300)

  useEffect(() => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (debouncedSearch) next.set('q', debouncedSearch)
        else next.delete('q')
        next.delete('cursor')
        return next
      },
      { replace: true },
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const status = searchParams.get('status') as ContactStatus | null
  const assignedAgentId = searchParams.get('assigned_agent_id')
  const unassigned = searchParams.get('unassigned') === '1'
  const archived = (searchParams.get('archived') as 'without' | 'only' | 'with' | null) ?? 'without'
  const sort = (searchParams.get('sort') as 'created_at' | 'last_activity_at' | null) ?? 'last_activity_at'
  const direction = (searchParams.get('direction') as 'asc' | 'desc' | null) ?? 'desc'
  const cursor = searchParams.get('cursor') ?? undefined

  function updateParam(updates: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(updates)) {
        if (value === null) next.delete(key)
        else next.set(key, value)
      }
      next.delete('cursor') // any filter/sort change invalidates the current page position
      return next
    })
  }

  const { data, isFetching, isError, error, refetch } = useListContactsQuery({
    q: debouncedSearch || undefined,
    status: status ?? undefined,
    assigned_agent_id: assignedAgentId ? Number(assignedAgentId) : undefined,
    unassigned: unassigned || undefined,
    archived: isTeamLead ? archived : undefined,
    sort,
    direction,
    cursor,
    per_page: 20,
  })

  const { data: agents } = useListAgentsQuery(undefined, { skip: !isTeamLead })

  const apiError = toApiError(error)

  function goToCursorLink(link: string | null) {
    if (!link) return
    const nextCursor = new URL(link).searchParams.get('cursor')
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      if (nextCursor) next.set('cursor', nextCursor)
      else next.delete('cursor')
      return next
    })
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-xl font-semibold text-slate-900">Contacts</h1>
        <Link to="/contacts/new">
          <Button>Add contact</Button>
        </Link>
      </div>

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input
          type="search"
          placeholder="Search name, email, phone, company…"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 lg:col-span-2"
        />

        <select
          value={status ?? ''}
          onChange={(e) => updateParam({ status: e.target.value || null })}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
          <option value="">All statuses</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {s[0].toUpperCase() + s.slice(1)}
            </option>
          ))}
        </select>

        <select
          value={`${sort}:${direction}`}
          onChange={(e) => {
            const [newSort, newDirection] = e.target.value.split(':')
            updateParam({ sort: newSort, direction: newDirection })
          }}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
          <option value="last_activity_at:desc">Most recent activity</option>
          <option value="last_activity_at:asc">Least recent activity</option>
          <option value="created_at:desc">Newest first</option>
          <option value="created_at:asc">Oldest first</option>
        </select>

        {isTeamLead && (
          <>
            <select
              value={assignedAgentId ?? ''}
              onChange={(e) => updateParam({ assigned_agent_id: e.target.value || null, unassigned: null })}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">All agents</option>
              {agents?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </select>

            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input
                type="checkbox"
                checked={unassigned}
                onChange={(e) => updateParam({ unassigned: e.target.checked ? '1' : null, assigned_agent_id: null })}
              />
              Unassigned only
            </label>

            <select
              value={archived}
              onChange={(e) => updateParam({ archived: e.target.value })}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="without">Active contacts</option>
              <option value="only">Archived only</option>
              <option value="with">Active + archived</option>
            </select>
          </>
        )}
      </div>

      {isError && <ErrorBanner message={apiError?.message ?? 'Failed to load contacts.'} onRetry={refetch} />}

      {!isError && isFetching && !data && <Spinner label="Loading contacts…" />}

      {!isError && data && data.data.length === 0 && (
        <p className="rounded-md border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
          No contacts match these filters.
        </p>
      )}

      {!isError && data && data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-2">Name</th>
                <th className="px-4 py-2">Company</th>
                <th className="px-4 py-2">Status</th>
                {isTeamLead && <th className="px-4 py-2">Agent</th>}
                <th className="px-4 py-2">Last activity</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((contact) => (
                <tr key={contact.id} className={isFetching ? 'opacity-50' : undefined}>
                  <td className="px-4 py-2">
                    <Link to={`/contacts/${contact.id}`} className="font-medium text-indigo-600 hover:text-indigo-500">
                      {contact.name}
                    </Link>
                    <div className="text-xs text-slate-500">{contact.email}</div>
                  </td>
                  <td className="px-4 py-2 text-slate-600">{contact.company ?? '—'}</td>
                  <td className="px-4 py-2">
                    <StatusBadge status={contact.status} />
                    {contact.archived_at && <span className="ml-2 text-xs text-slate-400">Archived</span>}
                  </td>
                  {isTeamLead && (
                    <td className="px-4 py-2 text-slate-600">{contact.assigned_agent?.name ?? 'Unassigned'}</td>
                  )}
                  <td className="px-4 py-2 text-slate-600">{formatRelative(contact.last_activity_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
            <Button
              variant="secondary"
              disabled={!data.links.prev}
              onClick={() => goToCursorLink(data.links.prev)}
            >
              Previous
            </Button>
            <Button
              variant="secondary"
              disabled={!data.links.next}
              onClick={() => goToCursorLink(data.links.next)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
