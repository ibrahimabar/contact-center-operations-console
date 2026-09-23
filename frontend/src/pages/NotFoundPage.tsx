import { Link } from 'react-router-dom'

export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center gap-3 py-16 text-center">
      <h1 className="text-lg font-semibold text-slate-900">Page not found</h1>
      <p className="text-sm text-slate-500">The page you're looking for doesn't exist.</p>
      <Link to="/contacts" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
        Back to contacts
      </Link>
    </div>
  )
}
