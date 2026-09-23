import { createContext } from 'react'

export interface ToastContextValue {
  show: (message: string, variant?: 'success' | 'error' | 'info') => void
}

export const ToastContext = createContext<ToastContextValue | null>(null)
