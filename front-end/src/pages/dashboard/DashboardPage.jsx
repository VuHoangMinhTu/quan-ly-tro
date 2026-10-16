import { useQuery } from '@tanstack/react-query'
import { dashboardKey, getDashboard } from '../../api/dashboardApi'
import DashboardContent from '../../components/dashboard/DashboardContent'

export default function DashboardPage() {
  const query = useQuery({ queryKey: dashboardKey, queryFn: getDashboard, staleTime: 0, refetchOnWindowFocus: true, refetchInterval: 30000 })
  if (query.isPending) return <section aria-label="Đang tải tổng quan" role="status" className="min-w-0 space-y-5"><p className="text-sm text-slate-500">Đang tải dữ liệu tổng quan...</p><div aria-hidden="true" className="grid animate-pulse grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 8 }, (_, index) => <div key={index} className="h-32 rounded-xl bg-slate-200" />)}</div><div aria-hidden="true" className="h-72 animate-pulse rounded-xl bg-slate-200" /></section>
  if (query.isError) return <section role="alert" className="space-y-4 rounded-xl bg-white p-5 shadow-sm"><p className="text-sm text-slate-700">Không thể tải dữ liệu tổng quan. Vui lòng thử lại.</p><button type="button" disabled={query.isFetching} onClick={() => query.refetch()} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">Thử lại</button></section>
  return <section className="min-w-0"><DashboardContent data={query.data.data.data} /></section>
}
