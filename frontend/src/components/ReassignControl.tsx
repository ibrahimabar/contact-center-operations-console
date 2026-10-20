import { useState } from 'react'
import { useListAgentsQuery } from '@/api/agentsApi'
import { useAssignContactMutation } from '@/api/contactsApi'
import { toApiError } from '@/api/baseApi'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { ErrorBanner } from '@/components/ErrorBanner'
import { useToast } from '@/components/useToast'
import type { Contact } from '@/types/models'

export function ReassignControl({ contact }: { contact: Contact }) {
  const { data: agents } = useListAgentsQuery()
  const [assign, { isLoading, error }] = useAssignContactMutation()
  const { show } = useToast()
  const [pendingAgentId, setPendingAgentId] = useState<number | null | undefined>(undefined)

  const apiError = toApiError(error)

  async function confirmReassign() {
    try {
      await assign({ id: contact.id, assigned_agent_id: pendingAgentId ?? null, version: contact.version }).unwrap()
      show('Contact reassigned.', 'success')
      setPendingAgentId(undefined)
    } catch {
      // apiError below surfaces the failure; dialog stays open so the user can retry or cancel
    }
  }

  return (
    <div>
      <label className="block text-sm">
        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Assigned agent</span>
        <select
          value={contact.assigned_agent_id ?? ''}
          onChange={(e) => setPendingAgentId(e.target.value ? Number(e.target.value) : null)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
          <option value="">Unassigned</option>
          {agents?.map((a) => (
            <option key={a.id} value={a.id}>
              {a.name}
            </option>
          ))}
        </select>
      </label>

      {apiError && <div className="mt-2"><ErrorBanner message={apiError.message} /></div>}

      <ConfirmDialog
        open={pendingAgentId !== undefined}
        title="Reassign contact"
        message={
          pendingAgentId === null
            ? `Unassign ${contact.name}? They will move to the unassigned queue.`
            : `Reassign ${contact.name} to ${agents?.find((a) => a.id === pendingAgentId)?.name ?? 'this agent'}?`
        }
        confirmLabel="Reassign"
        loading={isLoading}
        onConfirm={confirmReassign}
        onCancel={() => setPendingAgentId(undefined)}
      />
    </div>
  )
}
