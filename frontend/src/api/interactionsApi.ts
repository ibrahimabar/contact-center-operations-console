import { baseApi } from '@/api/baseApi'
import type { CursorPage, Interaction, InteractionType, SingleResource } from '@/types/models'

export interface ListInteractionsParams {
  contactId: number
  cursor?: string
  per_page?: number
}

export interface CreateInteractionPayload {
  contactId: number
  idempotencyKey: string
  type: InteractionType
  note: string
  timestamp?: string
}

export const interactionsApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    listInteractions: builder.query<CursorPage<Interaction>, ListInteractionsParams>({
      query: ({ contactId, ...params }) => ({ url: `/contacts/${contactId}/interactions`, params }),
      providesTags: (result, _error, arg) =>
        result
          ? [
              ...result.data.map((i) => ({ type: 'Interaction' as const, id: i.id })),
              { type: 'Interaction' as const, id: `LIST-${arg.contactId}` },
            ]
          : [{ type: 'Interaction' as const, id: `LIST-${arg.contactId}` }],
    }),
    createInteraction: builder.mutation<Interaction, CreateInteractionPayload>({
      query: ({ contactId, idempotencyKey, ...body }) => ({
        url: `/contacts/${contactId}/interactions`,
        method: 'POST',
        body,
        headers: { 'Idempotency-Key': idempotencyKey },
      }),
      transformResponse: (response: SingleResource<Interaction>) => response.data,
      // A successful add also moves the contact's last_activity_at (and,
      // for a "sort by recent activity" list, its position) — invalidate
      // both, not just the interaction list.
      invalidatesTags: (_result, _error, arg) => [
        { type: 'Interaction', id: `LIST-${arg.contactId}` },
        { type: 'Contact', id: arg.contactId },
        { type: 'ContactList', id: 'LIST' },
      ],
    }),
  }),
})

export const { useListInteractionsQuery, useCreateInteractionMutation } = interactionsApi
