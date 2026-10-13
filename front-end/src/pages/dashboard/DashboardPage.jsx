import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { Building2, UserRound } from 'lucide-react'
import { Link } from 'react-router-dom'
import { getMe } from '../../api/authApi'
import { APP_NAME } from '../../utils/brand'

export default function DashboardPage() {
  const { data, isError, error } = useQuery({ queryKey: ['me'], queryFn: getMe })
  if (isError) return <p className="text-red-600">{getApiErrorMessage(error)}</p>
  const profile = data?.data?.data

  return <section className="min-w-0">
    <h2 className="text-xl font-bold sm:text-2xl">Dashboard</h2>
    <p className="mt-2 text-sm text-slate-600 sm:text-base">Chào mừng quay lại {APP_NAME}</p>
    <div className="mt-5 grid gap-4 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:p-5">
      <div className="flex min-w-0 items-start gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700"><UserRound size={21} /></span>
        <div className="min-w-0"><p className="text-sm text-slate-500">Người dùng</p><p className="mt-1 font-semibold [overflow-wrap:anywhere]">{profile?.user?.name || 'Chưa cập nhật'}</p>{profile?.user?.email && <p className="mt-1 text-sm text-slate-600 [overflow-wrap:anywhere]">{profile.user.email}</p>}</div>
      </div>
      <div className="flex min-w-0 items-start gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700"><Building2 size={21} /></span>
        <div className="min-w-0"><p className="text-sm text-slate-500">Chủ nhà</p><p className="mt-1 font-semibold [overflow-wrap:anywhere]">{profile?.landlord?.full_name || 'Chưa cập nhật'}</p>{profile?.landlord?.phone && <p className="mt-1 text-sm text-slate-600">{profile.landlord.phone}</p>}</div>
      </div>
    </div>
    <div className="mt-5 flex flex-col gap-3 sm:flex-row">
      <Link to="/boarding-houses" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white"><Building2 size={18} />Quản lý nhà trọ</Link>
      <Link to="/tenants" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700"><UserRound size={18} />Danh sách người thuê</Link>
    </div>
  </section>
}
