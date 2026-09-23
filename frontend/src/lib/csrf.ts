export const API_BASE_URL: string = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

/** Laravel's Sanctum SPA cookie auth needs this header on every mutating
 * request; fetchBaseQuery doesn't read cookies automatically. */
export function getXsrfTokenHeader(): Record<string, string> {
  const token = readCookie('XSRF-TOKEN')
  return token ? { 'X-XSRF-TOKEN': token } : {}
}

/**
 * Must be called before the first login attempt (and after logout, before
 * the next login) so a valid XSRF-TOKEN cookie exists to read — see
 * DECISIONS.md "Auth" and backend AuthController.
 */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}
