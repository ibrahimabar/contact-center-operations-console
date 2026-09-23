import { http, HttpResponse } from 'msw'
import type { User } from '@/types/models'

const API_BASE = 'http://localhost:8000'

export const teamLead: User = {
  id: 1,
  name: 'Taylor Lead',
  email: 'teamlead@example.com',
  role: 'team_lead',
  is_active: true,
}

/** Default happy-path handlers every test starts with; individual tests
 * layer on top via server.use() for the specific scenario they cover. */
export const handlers = [
  http.get(`${API_BASE}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
  http.get(`${API_BASE}/api/v1/auth/me`, () => HttpResponse.json({ data: teamLead })),
  http.get(`${API_BASE}/api/v1/agents`, () =>
    HttpResponse.json({
      data: [
        { id: 2, name: 'Agent One', email: 'agent1@example.com', role: 'agent', is_active: true },
        { id: 3, name: 'Agent Two', email: 'agent2@example.com', role: 'agent', is_active: true },
      ],
    }),
  ),
]
