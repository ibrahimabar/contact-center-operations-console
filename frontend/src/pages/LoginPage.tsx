import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { useLoginMutation } from '@/api/authApi'
import { toApiError } from '@/api/baseApi'
import { Button } from '@/components/Button'
import { ErrorBanner } from '@/components/ErrorBanner'

export function LoginPage() {
  const user = useAppSelector((state) => state.auth.user)
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [login, { isLoading, error }] = useLoginMutation()

  if (user) {
    const from = (location.state as { from?: Location })?.from?.pathname ?? '/contacts'
    return <Navigate to={from} replace />
  }

  const apiError = toApiError(error)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    try {
      await login({ email, password }).unwrap()
      navigate('/contacts', { replace: true })
    } catch {
      // apiError below already reflects the failure via the mutation's error state
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <form onSubmit={handleSubmit} className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h1 className="mb-1 text-lg font-semibold text-slate-900">Contact Center Operations Console</h1>
        <p className="mb-6 text-sm text-slate-500">Sign in to manage your contacts.</p>

        {apiError && <div className="mb-4"><ErrorBanner message={apiError.message} /></div>}

        <label className="mb-3 block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Email</span>
          <input
            type="email"
            required
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </label>

        <label className="mb-6 block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Password</span>
          <input
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </label>

        <Button type="submit" loading={isLoading} className="w-full justify-center">
          Sign in
        </Button>
      </form>
    </div>
  )
}
