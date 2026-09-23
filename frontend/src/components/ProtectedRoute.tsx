import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAppSelector } from '@/app/hooks'
import { Spinner } from '@/components/Spinner'

/** Route guarding here is a UX convenience only — the backend is the real
 * enforcement (see DECISIONS.md "Roles"). A logged-out or wrong-role user
 * who bypasses this would just get a 401/403 from the API. */
export function ProtectedRoute() {
  const { user, bootstrapped } = useAppSelector((state) => state.auth)
  const location = useLocation()

  if (!bootstrapped) {
    return <Spinner label="Checking your session…" />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  return <Outlet />
}
