import { useState } from 'react'
import { Link, Navigate, useParams } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { useArchiveContactMutation, useGetContactQuery, useRestoreContactMutation, useUpdateContactMutation } from '@/api/contactsApi'
import { toApiError } from '@/api/baseApi'
import { AddInteractionForm } from '@/components/AddInteractionForm'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { ErrorBanner } from '@/components/ErrorBanner'
import { InteractionTimeline } from '@/components/InteractionTimeline'
import { ReassignControl } from '@/components/ReassignControl'
import { Spinner } from '@/components/Spinner'
import { StatusBadge } from '@/components/StatusBadge'
import { useToast } from '@/components/useToast'
import { formatDateTime } from '@/lib/format'
import type { ContactStatus } from '@/types/models'

const STATUSES: ContactStatus[] = ['new', 'active', 'pending', 'resolved']

export function ContactDetailPage() {
  const { id } = useParams<{ id: string }>()
  const contactId = Number(id)
  const user = useAppSelector((state) => state.auth.user)
  const isTeamLead = user?.role === 'team_lead'
  const { show } = useToast()

  const { data: contact, isLoading, isError, error, refetch } = useGetContactQuery(contactId)
  const [updateContact, { isLoading: isSavingStatus, error: statusError }] = useUpdateContactMutation()
  const [archiveContact, { isLoading: isArchiving }] = useArchiveContactMutation()
  const [restoreContact, { isLoading: isRestoring }] = useRestoreContactMutation()
  const [confirmingArchive, setConfirmingArchive] = useState(false)

  if (!id || Number.isNaN(contactId)) {
    return <Navigate to="/contacts" replace />
  }

  const apiError = toApiError(error)
  const statusApiError = toApiError(statusError)
  const isVersionConflict = statusApiError?.code === 'version_conflict'

  async function handleStatusChange(newStatus: ContactStatus) {
    if (!contact) return
    try {
      await updateContact({ id: contact.id, status: newStatus, version: contact.version }).unwrap()
      show('Status updated.', 'success')
    } catch {
      // statusApiError below (including the version_conflict banner) surfaces the failure
    }
  }

  async function handleArchive() {
    if (!contact) return
    try {
      await archiveContact(contact.id).unwrap()
      show('Contact archived.', 'success')
      setConfirmingArchive(false)
    } catch {
      show('Could not archive this contact.', 'error')
    }
  }

  async function handleRestore() {
    if (!contact) return
    try {
      await restoreContact(contact.id).unwrap()
      show('Contact restored.', 'success')
    } catch {
      show('Could not restore this contact.', 'error')
    }
  }

  if (isLoading) return <Spinner label="Loading contact…" />

  if (isError) {
    if (apiError?.code === 'forbidden') {
      return <ErrorBanner message="You don't have access to this contact." />
    }
    if (apiError?.code === 'not_found') {
      return <ErrorBanner message="This contact could not be found." />
    }
    return <ErrorBanner message={apiError?.message ?? 'Failed to load this contact.'} onRetry={refetch} />
  }

  if (!contact) return null

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div className="space-y-6 lg:col-span-2">
        <div className="rounded-md border border-slate-200 bg-white p-4">
          <div className="mb-3 flex items-start justify-between">
            <div>
              <h1 className="text-lg font-semibold text-slate-900">{contact.name}</h1>
              <p className="text-sm text-slate-500">{contact.company ?? 'No company'}</p>
            </div>
            <div className="flex items-center gap-2">
              <StatusBadge status={contact.status} />
              {contact.archived_at && <span className="text-xs text-slate-400">Archived</span>}
            </div>
          </div>

          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">Email</dt>
              <dd className="text-slate-700">{contact.email}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">Phone</dt>
              <dd className="text-slate-700">{contact.phone}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">Assigned agent</dt>
              <dd className="text-slate-700">{contact.assigned_agent?.name ?? 'Unassigned'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">Created</dt>
              <dd className="text-slate-700">{formatDateTime(contact.created_at)}</dd>
            </div>
          </dl>

          <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
            <Link to={`/contacts/${contact.id}/edit`}>
              <Button variant="secondary">Edit details</Button>
            </Link>

            <label className="flex items-center gap-2 text-sm text-slate-600">
              Status:
              <select
                value={contact.status}
                disabled={isSavingStatus}
                onChange={(e) => handleStatusChange(e.target.value as ContactStatus)}
                className="rounded-md border border-slate-300 px-2 py-1 text-sm"
              >
                {STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {s[0].toUpperCase() + s.slice(1)}
                  </option>
                ))}
              </select>
            </label>

            {isTeamLead && !contact.archived_at && (
              <Button variant="danger" onClick={() => setConfirmingArchive(true)}>
                Archive
              </Button>
            )}
            {isTeamLead && contact.archived_at && (
              <Button variant="secondary" loading={isRestoring} onClick={handleRestore}>
                Restore
              </Button>
            )}
          </div>

          {statusApiError && (
            <div className="mt-3">
              <ErrorBanner
                message={
                  isVersionConflict
                    ? 'This contact was changed by someone else since you loaded it. Reload to see the latest version.'
                    : statusApiError.message
                }
                onRetry={isVersionConflict ? refetch : undefined}
              />
            </div>
          )}
        </div>

        <AddInteractionForm contactId={contact.id} />
        <InteractionTimeline contactId={contact.id} />
      </div>

      <div className="space-y-6">
        {isTeamLead && (
          <div className="rounded-md border border-slate-200 bg-white p-4">
            <ReassignControl contact={contact} />
          </div>
        )}
      </div>

      <ConfirmDialog
        open={confirmingArchive}
        title="Archive contact"
        message={`Archive ${contact.name}? They'll be hidden from the default contacts list, but can be restored later.`}
        confirmLabel="Archive"
        danger
        loading={isArchiving}
        onConfirm={handleArchive}
        onCancel={() => setConfirmingArchive(false)}
      />
    </div>
  )
}
