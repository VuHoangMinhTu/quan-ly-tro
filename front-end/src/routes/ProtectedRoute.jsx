import { getApiErrorMessage } from '../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { Navigate, Outlet } from 'react-router-dom'
import { getMe } from '../api/authApi'
import { useEffect, useSyncExternalStore } from 'react'
import { endSession, getSession, subscribeSession } from '../api/session'
export default function ProtectedRoute() {
  const session = useSyncExternalStore(subscribeSession, getSession, getSession)
  // Remount every private page/form when identity changes, even on the same URL.
  return <SessionProtectedRoute key={session.id} session={session} />
}

function SessionProtectedRoute({ session }) {
  const token = session.token
  const query = useQuery({ queryKey: ['me'], queryFn: getMe, enabled: Boolean(token) })
  const errorMessage = session.errorMessage || (query.isError ? getApiErrorMessage(query.error) : '')
  const errorState = errorMessage ? { error: errorMessage } : undefined
  useEffect(() => { if (query.isError) endSession(session, getApiErrorMessage(query.error)) }, [query.isError, query.error, session])

  // The Axios interceptor may already have cleared an expired token.
  // Preserve the error feedback without changing the existing login redirect.
  if (!token) return <Navigate to="/login" replace state={errorState} />
  if (query.isPending) return <div className="p-8">Đang kiểm tra đăng nhập...</div>
  if (query.isError) {
    return <Navigate to="/login" replace state={errorState} />
  }
  return <Outlet />
}
