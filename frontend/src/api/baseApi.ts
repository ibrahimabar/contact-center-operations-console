import { createApi, fetchBaseQuery, type BaseQueryFn, type FetchArgs, type FetchBaseQueryError } from '@reduxjs/toolkit/query/react'
import { API_BASE_URL, ensureCsrfCookie, getXsrfTokenHeader } from '@/lib/csrf'
import { sessionExpired } from '@/features/auth/authSlice'
import type { ApiErrorBody } from '@/types/models'

const rawBaseQuery = fetchBaseQuery({
  baseUrl: `${API_BASE_URL}/api/v1`,
  credentials: 'include',
  prepareHeaders: (headers) => {
    headers.set('Accept', 'application/json')
    const xsrf = getXsrfTokenHeader()['X-XSRF-TOKEN']
    if (xsrf) headers.set('X-XSRF-TOKEN', xsrf)
    return headers
  },
})

/**
 * Wraps the plain fetchBaseQuery so:
 *  - a mutating request (POST/PATCH/DELETE) always has a fresh CSRF cookie
 *    first — the SPA never calls /sanctum/csrf-cookie manually elsewhere.
 *  - a 401 from ANY endpoint clears the client's auth state once, globally,
 *    instead of every feature having to handle it — the router's
 *    ProtectedRoute then redirects to /login. See DECISIONS.md "Auth".
 */
const baseQueryWithAuthHandling: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = async (
  args,
  api,
  extraOptions,
) => {
  const method = typeof args === 'string' ? 'GET' : (args.method ?? 'GET')
  if (method !== 'GET' && method !== 'HEAD') {
    try {
      await ensureCsrfCookie()
    } catch (err) {
      // A network-level failure here (the backend unreachable, not just an
      // HTTP error status) throws instead of resolving — if left uncaught,
      // that bypasses fetchBaseQuery entirely and RTK Query's error state
      // ends up in a shape toApiError() doesn't recognize, so no banner
      // ever renders and the user gets no feedback at all. Reported the
      // same way fetchBaseQuery itself reports an unreachable server.
      return { error: { status: 'FETCH_ERROR', error: String(err) } }
    }
  }

  const result = await rawBaseQuery(args, api, extraOptions)

  if (result.error?.status === 401) {
    api.dispatch(sessionExpired())
  }

  return result
}

export const baseApi = createApi({
  reducerPath: 'api',
  baseQuery: baseQueryWithAuthHandling,
  tagTypes: ['Contact', 'ContactList', 'Interaction', 'Agent', 'CurrentUser'],
  endpoints: () => ({}),
})

/** Normalizes RTK Query's FetchBaseQueryError into the API's own envelope
 * shape so components only ever deal with one error type. */
export function toApiError(error: unknown): ApiErrorBody | null {
  if (error && typeof error === 'object' && 'status' in error) {
    const fetchError = error as FetchBaseQueryError
    const data = fetchError.data
    if (data && typeof data === 'object' && 'message' in data && 'code' in data) {
      return data as ApiErrorBody
    }
    if (fetchError.status === 'FETCH_ERROR' || fetchError.status === 'TIMEOUT_ERROR') {
      return { message: 'Could not reach the server. Check your connection and try again.', code: 'network_error' }
    }
  }
  return null
}
