import { baseApi } from '@/api/baseApi'
import type { SingleResource, User } from '@/types/models'

export const agentsApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    // Team-lead only (see UserPolicy on the backend); the frontend never
    // calls this for an agent, but the backend enforces it regardless.
    listAgents: builder.query<User[], void>({
      query: () => '/agents',
      transformResponse: (response: SingleResource<User[]>) => response.data,
      providesTags: [{ type: 'Agent', id: 'LIST' }],
    }),
  }),
})

export const { useListAgentsQuery } = agentsApi
