import { createSlice, type PayloadAction } from '@reduxjs/toolkit'
import type { User } from '@/types/models'

interface AuthState {
  user: User | null
  /** True once the initial GET /auth/me check (see App.tsx) has resolved,
   * either way — lets the router avoid a login-page flash while that's in flight. */
  bootstrapped: boolean
}

const initialState: AuthState = {
  user: null,
  bootstrapped: false,
}

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    setUser(state, action: PayloadAction<User>) {
      state.user = action.payload
      state.bootstrapped = true
    },
    loggedOut(state) {
      state.user = null
      state.bootstrapped = true
    },
    /** Same effect as loggedOut, kept as a distinct action so the UI can
     * tell "you chose to log out" apart from "your session expired" if it
     * ever wants to (e.g. a toast) — currently both just clear the user. */
    sessionExpired(state) {
      state.user = null
      state.bootstrapped = true
    },
    bootstrapFinished(state) {
      state.bootstrapped = true
    },
  },
})

export const { setUser, loggedOut, sessionExpired, bootstrapFinished } = authSlice.actions
export default authSlice.reducer
