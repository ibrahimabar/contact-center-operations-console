import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Button } from '@/components/Button'

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
}

/** Catches render-time crashes anywhere below it so one broken page can't
 * blank the whole app — React error boundaries must be class components. */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false }

  static getDerivedStateFromError(): State {
    return { hasError: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Unhandled UI error', error, info)
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-8 text-center">
          <h1 className="text-lg font-semibold text-slate-900">Something went wrong</h1>
          <p className="max-w-sm text-sm text-slate-500">
            An unexpected error occurred while rendering this page. Reloading usually fixes it.
          </p>
          <Button onClick={() => window.location.reload()}>Reload page</Button>
        </div>
      )
    }

    return this.props.children
  }
}
