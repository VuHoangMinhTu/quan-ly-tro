import { getApiErrorMessage } from '../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { Navigate, Outlet } from 'react-router-dom'
import { getMe } from '../api/authApi'
export default function ProtectedRoute() {
  const token = localStorage.getItem('access_token')
  const query = useQuery({ queryKey: ['me'], queryFn: getMe, enabled: Boolean(token) })
  const errorState = query.isError ? { error: getApiErrorMessage(query.error) } : undefined

  // The Axios interceptor may already have cleared an expired token.
  // Preserve the error feedback without changing the existing login redirect.
  if (!token) return <Navigate to="/login" replace state={errorState} />
  if (query.isPending) return <div className="p-8">Đang kiểm tra đăng nhập...</div>
  if (query.isError) {
    localStorage.removeItem('access_token')
    return <Navigate to="/login" replace state={errorState} />
  }
  return <Outlet />
}
