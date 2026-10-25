import { useEffect, useSyncExternalStore } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { getSession, replaceSession, subscribeSession } from './session'

export default function SessionQueryProvider({ children }) {
  const session = useSyncExternalStore(subscribeSession, getSession, getSession)
  useEffect(() => {
    const changed = event => {
      if (event.key === 'access_token' || event.key === null) {
        const token = localStorage.getItem('access_token')
        if (token !== getSession().token) replaceSession(token)
      }
    }
    window.addEventListener('storage', changed)
    return () => window.removeEventListener('storage', changed)
  }, [])
  return <QueryClientProvider client={session.queryClient}>{children}</QueryClientProvider>
}
