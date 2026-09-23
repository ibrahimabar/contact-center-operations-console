import { Link, Outlet } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { useLogoutMutation } from '@/api/authApi'
import { Button } from '@/components/Button'

export function AppLayout() {
  const user = useAppSelector((state) => state.auth.user)
  const [logout, { isLoading }] = useLogoutMutation()

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <Link to="/contacts" className="text-sm font-semibold text-slate-900">
            Contact Center Operations Console
          </Link>
          {user && (
            <div className="flex items-center gap-4 text-sm text-slate-600">
              <span>
                {user.name} <span className="text-slate-400">· {user.role === 'team_lead' ? 'Team Lead' : 'Agent'}</span>
              </span>
              <Button variant="ghost" loading={isLoading} onClick={() => logout()}>
                Log out
              </Button>
            </div>
          )}
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
