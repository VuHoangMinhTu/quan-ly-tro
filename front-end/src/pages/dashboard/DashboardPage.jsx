import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { dashboardKey, getDashboard } from '../../api/dashboardApi'
import { dashboardRefetchInterval } from '../../api/dashboardPolicy'
import DashboardContent from '../../components/dashboard/DashboardContent'
import DashboardFilters from '../../components/dashboard/DashboardFilters.jsx'
import { filterFromSearch } from '../../components/dashboard/dashboardFilters'

export default function DashboardPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filter = filterFromSearch(searchParams.toString())
  const query = useQuery({ queryKey: dashboardKey(filter), queryFn: getDashboard, refetchOnWindowFocus: true, refetchInterval: dashboardRefetchInterval })
  if (query.isPending) return <section aria-label="Đang tải tổng quan" role="status" className="min-w-0 space-y-5"><p className="text-sm text-slate-500">Đang tải dữ liệu tổng quan...</p><div aria-hidden="true" className="grid animate-pulse grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 8 }, (_, index) => <div key={index} className="h-32 rounded-xl bg-slate-200" />)}</div><div aria-hidden="true" className="h-72 animate-pulse rounded-xl bg-slate-200" /></section>
  if (query.isError) return <section role="alert" className="space-y-4 rounded-xl bg-white p-5 shadow-sm"><p className="text-sm text-slate-700">Không thể tải dữ liệu tổng quan. Vui lòng thử lại.</p><button type="button" disabled={query.isFetching} onClick={() => query.refetch()} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">Thử lại</button></section>
  const data = query.data.data.data
  const updateFilter = next => { const params = new URLSearchParams(); params.set('period', next.preset); if (next.houseId) params.set('house', next.houseId); if (next.compare) params.set('compare', '1'); if (next.preset === 'day') params.set('date', next.start); if (next.preset === 'custom') { params.set('start', next.start); params.set('end', next.end) } setSearchParams(params, { replace: true }) }
  return <section className="dashboard-screen min-w-0 space-y-5"><DashboardFilters filter={filter} houses={data.boarding_houses} onChange={updateFilter} onReset={() => setSearchParams({}, { replace: true })}/><DashboardContent data={data} filter={filter} /></section>
}
