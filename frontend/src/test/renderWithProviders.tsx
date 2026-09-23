import { configureStore } from '@reduxjs/toolkit'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { Provider } from 'react-redux'
import { MemoryRouter } from 'react-router-dom'
import { baseApi } from '@/api/baseApi'
import { ToastProvider } from '@/components/ToastProvider'
import authReducer from '@/features/auth/authSlice'
import type { User } from '@/types/models'

interface Options {
  route?: string
  user?: User | null
}

/** A fresh store per call (test isolation) with the given user already
 * "logged in" — pages read auth state directly rather than going through
 * App.tsx's bootstrap flow, which is tested separately. */
export function renderWithProviders(ui: ReactElement, { route = '/', user = null }: Options = {}) {
  const store = configureStore({
    reducer: { auth: authReducer, [baseApi.reducerPath]: baseApi.reducer },
    middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(baseApi.middleware),
    preloadedState: {
      auth: { user, bootstrapped: true },
    },
  })

  return {
    store,
    ...render(
      <Provider store={store}>
        <ToastProvider>
          <MemoryRouter initialEntries={[route]}>{ui}</MemoryRouter>
        </ToastProvider>
      </Provider>,
    ),
  }
}
