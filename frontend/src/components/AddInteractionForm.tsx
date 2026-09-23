import { useRef, useState, type FormEvent } from 'react'
import { useCreateInteractionMutation } from '@/api/interactionsApi'
import { toApiError } from '@/api/baseApi'
import { Button } from '@/components/Button'
import { ErrorBanner } from '@/components/ErrorBanner'
import { useToast } from '@/components/useToast'
import type { InteractionType } from '@/types/models'

const TYPES: InteractionType[] = ['phone', 'email', 'sms', 'chat']

export function AddInteractionForm({ contactId }: { contactId: number }) {
  const [type, setType] = useState<InteractionType>('phone')
  const [note, setNote] = useState('')
  const [createInteraction, { isLoading, error }] = useCreateInteractionMutation()
  const { show } = useToast()

  // Generated once per "attempt" and reused across retries of that same
  // attempt (e.g. a network error and the user clicking submit again),
  // regenerated only after a successful submit — this is what makes a
  // retried request idempotent end-to-end. See DECISIONS.md #2.
  const idempotencyKeyRef = useRef(crypto.randomUUID())

  const apiError = toApiError(error)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    try {
      await createInteraction({
        contactId,
        idempotencyKey: idempotencyKeyRef.current,
        type,
        note,
      }).unwrap()

      idempotencyKeyRef.current = crypto.randomUUID()
      setNote('')
      setType('phone')
      show('Interaction added.', 'success')
    } catch {
      // apiError below reflects the failure; idempotencyKeyRef is deliberately NOT
      // rotated here, so a resubmit reuses the same key.
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3 rounded-md border border-slate-200 bg-white p-4">
      <h3 className="text-sm font-semibold text-slate-900">Log an interaction</h3>

      {apiError && <ErrorBanner message={apiError.message} />}

      <div className="flex gap-3">
        <select
          value={type}
          onChange={(e) => setType(e.target.value as InteractionType)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t[0].toUpperCase() + t.slice(1)}
            </option>
          ))}
        </select>
      </div>

      <textarea
        required
        rows={3}
        placeholder="What happened?"
        value={note}
        onChange={(e) => setNote(e.target.value)}
        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      />

      <Button type="submit" loading={isLoading}>
        Add interaction
      </Button>
    </form>
  )
}
