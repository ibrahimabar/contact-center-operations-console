import { useEffect } from 'react'
import { RouterProvider } from 'react-router-dom'
import { useLazyMeQuery } from '@/api/authApi'
import { useAppDispatch } from '@/app/hooks'
import { router } from '@/app/router'
import { bootstrapFinished, setUser } from '@/features/auth/authSlice'

export function App() {
  const dispatch = useAppDispatch()
  const [triggerMe] = useLazyMeQuery()

  useEffect(() => {
    // On a fresh page load, the session cookie (if any) is the only signal
    // we have — GET /auth/me tells us whether it's still valid. A 401 here
    // is handled globally by baseApi (see baseQueryWithAuthHandling) and
    // already marks bootstrapped; the .catch() below is just the fallback
    // for a non-401 failure (e.g. the API being unreachable), so the app
    // doesn't spin on "checking your session" forever.
    triggerMe()
      .unwrap()
      .then((user) => dispatch(setUser(user)))
      .catch(() => dispatch(bootstrapFinished()))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return <RouterProvider router={router} />
}
