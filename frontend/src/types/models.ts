export type UserRole = 'agent' | 'team_lead'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  is_active: boolean
}

export type ContactStatus = 'new' | 'active' | 'pending' | 'resolved'

export interface Contact {
  id: number
  name: string
  email: string
  phone: string
  company: string | null
  status: ContactStatus
  assigned_agent_id: number | null
  assigned_agent: User | null
  last_activity_at: string
  version: number
  archived_at: string | null
  created_at: string
  updated_at: string
  recent_interactions?: Interaction[]
}

export type InteractionType = 'phone' | 'email' | 'sms' | 'chat'

export interface Interaction {
  id: number
  contact_id: number
  type: InteractionType
  timestamp: string
  note: string
  created_by: User
  created_at: string
}

export interface CursorPageMeta {
  path: string
  per_page: number
  next_cursor: string | null
  prev_cursor: string | null
}

export interface CursorPageLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface CursorPage<T> {
  data: T[]
  meta: CursorPageMeta
  links: CursorPageLinks
}

export interface SingleResource<T> {
  data: T
}

/** The API's one error envelope shape — see backend DECISIONS.md "API conventions". */
export interface ApiErrorBody {
  message: string
  code: string
  errors?: Record<string, string[]>
  current?: Contact
}
