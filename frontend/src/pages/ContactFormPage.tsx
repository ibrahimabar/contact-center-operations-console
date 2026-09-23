import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { useListAgentsQuery } from '@/api/agentsApi'
import { useCreateContactMutation, useGetContactQuery, useUpdateContactMutation } from '@/api/contactsApi'
import { toApiError } from '@/api/baseApi'
import { Button } from '@/components/Button'
import { ErrorBanner } from '@/components/ErrorBanner'
import { Spinner } from '@/components/Spinner'
import { useToast } from '@/components/useToast'
import type { ContactStatus } from '@/types/models'

const STATUSES: ContactStatus[] = ['new', 'active', 'pending', 'resolved']

export function ContactFormPage() {
  const { id } = useParams<{ id: string }>()
  const isEditing = Boolean(id)
  const contactId = id ? Number(id) : undefined
  const navigate = useNavigate()
  const { show } = useToast()
  const user = useAppSelector((state) => state.auth.user)
  const isTeamLead = user?.role === 'team_lead'

  const { data: existing, isLoading: isLoadingExisting } = useGetContactQuery(contactId!, { skip: !isEditing })
  const { data: agents } = useListAgentsQuery(undefined, { skip: !isTeamLead })
  const [createContact, { isLoading: isCreating, error: createError }] = useCreateContactMutation()
  const [updateContact, { isLoading: isUpdating, error: updateError }] = useUpdateContactMutation()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [company, setCompany] = useState('')
  const [status, setStatus] = useState<ContactStatus>('new')
  const [assignedAgentId, setAssignedAgentId] = useState<string>('')

  // Synchronizing local form state with an external, asynchronously-fetched
  // resource (the contact isn't available at first render) is exactly what
  // effects are for — there's no synchronous initial value to derive this
  // from instead.
  useEffect(() => {
    if (!existing) return
    setName(existing.name)
    setEmail(existing.email)
    setPhone(existing.phone)
    setCompany(existing.company ?? '')
    setStatus(existing.status)
    setAssignedAgentId(existing.assigned_agent_id ? String(existing.assigned_agent_id) : '')
  }, [existing])

  const error = isEditing ? updateError : createError
  const apiError = toApiError(error)
  const isSaving = isCreating || isUpdating

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()

    try {
      if (isEditing && existing) {
        const updated = await updateContact({
          id: existing.id,
          name,
          email,
          phone,
          company: company || null,
          status,
          version: existing.version,
        }).unwrap()
        show('Contact updated.', 'success')
        navigate(`/contacts/${updated.id}`)
      } else {
        const created = await createContact({
          name,
          email,
          phone,
          company: company || null,
          status,
          assigned_agent_id: isTeamLead && assignedAgentId ? Number(assignedAgentId) : undefined,
        }).unwrap()
        show('Contact created.', 'success')
        navigate(`/contacts/${created.id}`)
      }
    } catch {
      // apiError below (field-level via fieldError()) surfaces the failure
    }
  }

  function fieldError(field: string): string | undefined {
    return apiError?.errors?.[field]?.[0]
  }

  if (isEditing && isLoadingExisting) return <Spinner label="Loading contact…" />

  return (
    <div className="mx-auto max-w-lg">
      <h1 className="mb-6 text-lg font-semibold text-slate-900">{isEditing ? 'Edit contact' : 'Add contact'}</h1>

      <form onSubmit={handleSubmit} className="space-y-4 rounded-md border border-slate-200 bg-white p-6">
        {apiError && !apiError.errors && <ErrorBanner message={apiError.message} />}
        {apiError?.code === 'version_conflict' && (
          <ErrorBanner message="This contact changed since you loaded it. Go back and reopen it to see the latest version." />
        )}

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Name</span>
          <input
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          {fieldError('name') && <p className="mt-1 text-xs text-red-600">{fieldError('name')}</p>}
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Email</span>
          <input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          {fieldError('email') && <p className="mt-1 text-xs text-red-600">{fieldError('email')}</p>}
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Phone</span>
          <input
            required
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          {fieldError('phone') && <p className="mt-1 text-xs text-red-600">{fieldError('phone')}</p>}
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Company</span>
          <input
            value={company}
            onChange={(e) => setCompany(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Status</span>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as ContactStatus)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {s[0].toUpperCase() + s.slice(1)}
              </option>
            ))}
          </select>
        </label>

        {!isEditing && isTeamLead && (
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700">Assign to</span>
            <select
              value={assignedAgentId}
              onChange={(e) => setAssignedAgentId(e.target.value)}
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">Leave unassigned</option>
              {agents?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </select>
          </label>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
            Cancel
          </Button>
          <Button type="submit" loading={isSaving}>
            {isEditing ? 'Save changes' : 'Create contact'}
          </Button>
        </div>
      </form>
    </div>
  )
}
