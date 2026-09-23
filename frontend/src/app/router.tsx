import { createBrowserRouter } from 'react-router-dom'
import { AppLayout } from '@/components/AppLayout'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { ContactDetailPage } from '@/pages/ContactDetailPage'
import { ContactFormPage } from '@/pages/ContactFormPage'
import { ContactsListPage } from '@/pages/ContactsListPage'
import { LoginPage } from '@/pages/LoginPage'
import { NotFoundPage } from '@/pages/NotFoundPage'

// Every route below /contacts is reachable by both roles — an agent can
// create/view/edit contacts too (see backend DECISIONS.md "Domain rules").
// Team-lead-only actions (reassign, archive/restore, the agent picker) are
// controls *within* ContactDetailPage/ContactFormPage gated on the current
// user's role, not separate routes — the backend is what actually enforces
// them either way.
export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <ContactsListPage /> },
          { path: '/contacts', element: <ContactsListPage /> },
          { path: '/contacts/new', element: <ContactFormPage /> },
          { path: '/contacts/:id', element: <ContactDetailPage /> },
          { path: '/contacts/:id/edit', element: <ContactFormPage /> },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
])
