import { useEffect, useState } from 'react'
import { useListInteractionsQuery } from '@/api/interactionsApi'
import { toApiError } from '@/api/baseApi'
import { Button } from '@/components/Button'
import { ErrorBanner } from '@/components/ErrorBanner'
import { Spinner } from '@/components/Spinner'
import { formatDateTime } from '@/lib/format'
import type { Interaction } from '@/types/models'

const TYPE_LABELS: Record<Interaction['type'], string> = {
  phone: '📞 Phone',
  email: '✉️ Email',
  sms: '💬 SMS',
  chat: '💭 Chat',
}

export function InteractionTimeline({ contactId }: { contactId: number }) {
  const [cursor, setCursor] = useState<string | undefined>(undefined)
  const [items, setItems] = useState<Interaction[]>([])

  const { data, isFetching, isError, error, refetch } = useListInteractionsQuery({ contactId, cursor, per_page: 15 })

  // A fresh (first-page) fetch replaces the list — e.g. after adding a new
  // interaction, whose invalidatesTags refetches this query at cursor
  // undefined. A "load more" fetch (cursor set) appends instead.
  useEffect(() => {
    if (!data) return
    setItems((prev) => (cursor === undefined ? data.data : [...prev, ...data.data]))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  useEffect(() => {
    setCursor(undefined)
    setItems([])
  }, [contactId])

  if (isError) {
    const apiError = toApiError(error)
    return <ErrorBanner message={apiError?.message ?? 'Failed to load the interaction history.'} onRetry={refetch} />
  }

  return (
    <div>
      <h3 className="mb-3 text-sm font-semibold text-slate-900">Interaction history</h3>

      {isFetching && items.length === 0 && <Spinner label="Loading interactions…" />}

      {!isFetching && items.length === 0 && <p className="text-sm text-slate-500">No interactions logged yet.</p>}

      <ol className="space-y-3">
        {items.map((interaction) => (
          <li key={interaction.id} className="rounded-md border border-slate-200 bg-white p-3">
            <div className="flex items-center justify-between text-xs text-slate-500">
              <span>{TYPE_LABELS[interaction.type]}</span>
              <span>{formatDateTime(interaction.timestamp)}</span>
            </div>
            <p className="mt-1 text-sm text-slate-800">{interaction.note}</p>
            <p className="mt-1 text-xs text-slate-400">Logged by {interaction.created_by.name}</p>
          </li>
        ))}
      </ol>

      {data?.meta.next_cursor && (
        <div className="mt-3 text-center">
          <Button variant="secondary" onClick={() => setCursor(data.meta.next_cursor ?? undefined)} loading={isFetching}>
            Load more
          </Button>
        </div>
      )}
    </div>
  )
}
