import { baseApi } from '@/api/baseApi'
import { loggedOut, setUser } from '@/features/auth/authSlice'
import type { SingleResource, User } from '@/types/models'

export const authApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    login: builder.mutation<User, { email: string; password: string }>({
      query: (credentials) => ({ url: '/auth/login', method: 'POST', body: credentials }),
      transformResponse: (response: SingleResource<User>) => response.data,
      onQueryStarted: async (_arg, { dispatch, queryFulfilled }) => {
        const { data: user } = await queryFulfilled
        dispatch(setUser(user))
      },
      invalidatesTags: ['CurrentUser'],
    }),
    logout: builder.mutation<void, void>({
      query: () => ({ url: '/auth/logout', method: 'POST' }),
      onQueryStarted: async (_arg, { dispatch, queryFulfilled }) => {
        await queryFulfilled
        dispatch(loggedOut())
        dispatch(baseApi.util.resetApiState())
      },
    }),
    me: builder.query<User, void>({
      query: () => '/auth/me',
      transformResponse: (response: SingleResource<User>) => response.data,
      providesTags: ['CurrentUser'],
    }),
  }),
})

export const { useLoginMutation, useLogoutMutation, useLazyMeQuery } = authApi
