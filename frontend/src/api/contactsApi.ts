import { baseApi } from '@/api/baseApi'
import type { Contact, ContactStatus, CursorPage, SingleResource } from '@/types/models'

export interface ListContactsParams {
  q?: string
  status?: ContactStatus
  assigned_agent_id?: number
  unassigned?: boolean
  archived?: 'without' | 'only' | 'with'
  sort?: 'created_at' | 'last_activity_at'
  direction?: 'asc' | 'desc'
  cursor?: string
  per_page?: number
}

export interface CreateContactPayload {
  name: string
  email: string
  phone: string
  company?: string | null
  status?: ContactStatus
  assigned_agent_id?: number | null
}

export interface UpdateContactPayload {
  id: number
  name?: string
  email?: string
  phone?: string
  company?: string | null
  status?: ContactStatus
  version: number
}

export interface AssignContactPayload {
  id: number
  assigned_agent_id: number | null
  version: number
}

const LIST_TAG = { type: 'ContactList' as const, id: 'LIST' }

export const contactsApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    listContacts: builder.query<CursorPage<Contact>, ListContactsParams>({
      query: (params) => ({ url: '/contacts', params }),
      providesTags: (result) =>
        result
          ? [...result.data.map((c) => ({ type: 'Contact' as const, id: c.id })), LIST_TAG]
          : [LIST_TAG],
    }),
    getContact: builder.query<Contact, number>({
      query: (id) => `/contacts/${id}`,
      transformResponse: (response: SingleResource<Contact>) => response.data,
      providesTags: (_result, _error, id) => [{ type: 'Contact', id }],
    }),
    createContact: builder.mutation<Contact, CreateContactPayload>({
      query: (body) => ({ url: '/contacts', method: 'POST', body }),
      transformResponse: (response: SingleResource<Contact>) => response.data,
      invalidatesTags: [LIST_TAG],
    }),
    updateContact: builder.mutation<Contact, UpdateContactPayload>({
      query: ({ id, ...body }) => ({ url: `/contacts/${id}`, method: 'PATCH', body }),
      transformResponse: (response: SingleResource<Contact>) => response.data,
      invalidatesTags: (_result, _error, arg) => [{ type: 'Contact', id: arg.id }, LIST_TAG],
    }),
    assignContact: builder.mutation<Contact, AssignContactPayload>({
      query: ({ id, ...body }) => ({ url: `/contacts/${id}/assign`, method: 'POST', body }),
      transformResponse: (response: SingleResource<Contact>) => response.data,
      invalidatesTags: (_result, _error, arg) => [{ type: 'Contact', id: arg.id }, LIST_TAG],
    }),
    archiveContact: builder.mutation<void, number>({
      query: (id) => ({ url: `/contacts/${id}`, method: 'DELETE' }),
      invalidatesTags: (_result, _error, id) => [{ type: 'Contact', id }, LIST_TAG],
    }),
    restoreContact: builder.mutation<Contact, number>({
      query: (id) => ({ url: `/contacts/${id}/restore`, method: 'POST' }),
      transformResponse: (response: SingleResource<Contact>) => response.data,
      invalidatesTags: (_result, _error, id) => [{ type: 'Contact', id }, LIST_TAG],
    }),
  }),
})

export const {
  useListContactsQuery,
  useGetContactQuery,
  useCreateContactMutation,
  useUpdateContactMutation,
  useAssignContactMutation,
  useArchiveContactMutation,
  useRestoreContactMutation,
} = contactsApi
