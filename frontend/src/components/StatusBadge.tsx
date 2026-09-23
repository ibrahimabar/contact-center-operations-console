import type { ContactStatus } from '@/types/models'

const styles: Record<ContactStatus, string> = {
  new: 'bg-blue-100 text-blue-800',
  active: 'bg-emerald-100 text-emerald-800',
  pending: 'bg-amber-100 text-amber-800',
  resolved: 'bg-slate-200 text-slate-700',
}

const labels: Record<ContactStatus, string> = {
  new: 'New',
  active: 'Active',
  pending: 'Pending',
  resolved: 'Resolved',
}

export function StatusBadge({ status }: { status: ContactStatus }) {
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${styles[status]}`}>
      {labels[status]}
    </span>
  )
}
