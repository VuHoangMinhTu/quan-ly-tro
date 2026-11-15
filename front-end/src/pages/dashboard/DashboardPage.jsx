import { useQuery, useQueries } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { dashboardKey, getDashboard, getDashboardSummary, getDashboardAnalytics } from '../../api/dashboardApi'
import { dashboardRefetchInterval } from '../../api/dashboardPolicy'
import DashboardContent from '../../components/dashboard/DashboardContent'
import DashboardFilters from '../../components/dashboard/DashboardFilters.jsx'
import { filterFromSearch } from '../../components/dashboard/dashboardFilters'
import { adaptDashboard, analyticsParams, visibleDashboardHouses } from '../../components/dashboard/analyticsAdapter'
import { ContractExpiryChart, InvoiceHistoryChart, ContractPeriodChart } from '../../components/dashboard/DashboardSeriesCharts'
import './dashboard-premium.css'

export default function DashboardPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filter = filterFromSearch(searchParams.toString())
  const query = useQuery({ queryKey: dashboardKey, queryFn: getDashboard, refetchOnWindowFocus: true, refetchInterval: dashboardRefetchInterval })
  if (query.isPending) return <section aria-label="Đang tải tổng quan" role="status" className="min-w-0 space-y-5"><p className="text-sm text-slate-500">Đang tải dữ liệu tổng quan...</p><div aria-hidden="true" className="grid animate-pulse grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 8 }, (_, index) => <div key={index} className="h-32 rounded-xl bg-slate-200" />)}</div><div aria-hidden="true" className="h-72 animate-pulse rounded-xl bg-slate-200" /></section>
  if (query.isError) return <section role="alert" className="space-y-4 rounded-xl bg-white p-5 shadow-sm"><p className="text-sm text-slate-700">Không thể tải dữ liệu tổng quan. Vui lòng thử lại.</p><button type="button" disabled={query.isFetching} onClick={() => query.refetch()} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">Thử lại</button></section>
  return <DashboardAnalyticsView legacy={query.data.data.data} filter={filter} searchParams={searchParams} setSearchParams={setSearchParams} />
}

function DashboardAnalyticsView({ legacy, filter, searchParams, setSearchParams }) {
  const params = analyticsParams(filter, searchParams)
  const scope = filter.houseId ? { boarding_house_id: filter.houseId } : {}
  const [summary, analytics] = useQueries({ queries: [
    { queryKey: ['dashboard', 'summary', scope], queryFn: getDashboardSummary, enabled: !filter.houseId || /^[1-9]\d*$/.test(filter.houseId), refetchOnWindowFocus: 'always' },
    { queryKey: ['dashboard', 'analytics', params], queryFn: getDashboardAnalytics, enabled: params !== null, refetchOnWindowFocus: 'always' },
  ] })
  const updateFilter = next => { const params = new URLSearchParams(); params.set('period', next.preset); if (next.houseId) params.set('house', next.houseId); if (next.anchor) params.set('anchor', next.anchor); if (next.group) params.set('group', next.group); if (next.compare) params.set('compare', '1'); if (next.preset === 'day') params.set('date', next.start); if (next.preset === 'custom') { params.set('start', next.start); params.set('end', next.end) } setSearchParams(params, { replace: true }) }
  const controls = <DashboardFilters filter={{ ...filter,
    start: searchParams.get(filter.preset === 'day' ? 'date' : 'start') ?? filter.start,
    end: searchParams.get('end') ?? filter.end,
    anchor: searchParams.get('anchor') ?? '', group: searchParams.get('group') ?? '',
  }}
    houses={visibleDashboardHouses(legacy.boarding_houses, filter.houseId, summary.error, analytics.error)}
    onChange={updateFilter} onReset={() => setSearchParams({}, { replace: true })} />
  const frame = children => <section className="dashboard-screen bh-premium min-w-0">{children}</section>
  if (!params) return frame(<>{controls}<p role="alert">Bộ lọc không hợp lệ. Hãy kiểm tra ngày và khoảng tối đa 366 ngày.</p></>)
  if (summary.isError || analytics.isError) return frame(<>{controls}<p role="alert">Không thể tải thống kê đã chọn.</p>
    <button type="button" onClick={() => { if (summary.isError) summary.refetch(); if (analytics.isError) analytics.refetch() }}>Thử lại</button></>)
  if (summary.isPending || analytics.isPending) return frame(<>{controls}<p role="status">Đang tải thống kê…</p></>)
  const data = adaptDashboard(legacy, summary.data.data.data, analytics.data.data.data)
  const rows = data.analytics.rows
  return frame(<DashboardContent data={data} filter={filter} controls={controls}
    invoiceChart={<InvoiceHistoryChart rows={rows.map(row => ({ label: row.label, billed: Number(row.invoice_value), paid: Number(row.invoice_paid_current), due: Number(row.invoice_due_current) }))} />}
    contractChart={<ContractExpiryChart rows={data.expiry_buckets.map(row => ({ label: row.from_day + '–' + row.to_day + ' ngày', count: row.count }))} />}
    contractPeriodChart={<ContractPeriodChart rows={rows} />} />)
}
