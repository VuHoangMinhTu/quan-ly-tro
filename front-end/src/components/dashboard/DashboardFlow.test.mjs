import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { transformWithOxc } from 'vite'
import { formatDateForDisplay } from '../../utils/date.js'
import { invoiceStatusLabels } from '../../utils/invoiceStatus.js'
import { dashboardRefetchInterval } from '../../api/dashboardPolicy.js'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

const Icon = () => React.createElement('svg', { 'aria-hidden': true })
const Link = ({ to, children, ...props }) => React.createElement('a', { href: to, ...props }, children)
const bindings = { formatCurrency: value => `${Number(value).toLocaleString('vi-VN')} đ`, formatDate: formatDateForDisplay, invoiceStatusLabels, Link,
  Building2: Icon, DoorOpen: Icon, Users: Icon, FileText: Icon, Receipt: Icon, WalletCards: Icon, CircleDollarSign: Icon, ChartNoAxesCombined: Icon, AlertCircle: Icon,
  InvoiceStatusBadge: ({ status }) => React.createElement('span', null, invoiceStatusLabels[status]),
  RoomStatusChart: () => React.createElement('div'), OccupancyByHouse: () => React.createElement('div'),
}
async function load(path, name, extra = {}) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  const scope = { ...bindings, ...extra }
  return new Function('React', ...Object.keys(scope), `${code}\nreturn ${name}`)(React, ...Object.values(scope))
}
const Revenue = await load('./RevenueChart.jsx', 'RevenueChart', {
  Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis,
  useId: React.useId, ChartTooltip: () => null, moneyTick: String,
})
const Content = await load('./DashboardContent.jsx', 'DashboardContent', { RevenueChart: Revenue })

test('revenue renders only amounts returned for each month, preserving zeros and gaps', () => {
  const months = [
    { month: '2026-06', label: '06/2026', amount: '987654' },
    { month: '2026-07', label: '07/2026', amount: '0' },
    { month: '2026-08', label: '08/2026', amount: null },
    { month: '2026-09', label: '09/2026', amount: '31000' },
  ]
  const html = renderToStaticMarkup(React.createElement(Revenue, { months }))
  assert.match(html, /06\/2026: 987\.654 đ/)
  assert.match(html, /07\/2026: 0 đ/)
  assert.match(html, /09\/2026: 31\.000 đ/)
  assert.doesNotMatch(html, /08\/2026/)
})
const fixture = {
  viewer: { name: 'Minh Tú', can_create_boarding_house: true, owned_boarding_houses: 1, managed_boarding_houses: 0 },
  period: { label: '10/2026', month: '2026-10' },
  summary: { total_boarding_houses: 1, total_rooms: 20, occupied_rooms: 14, available_rooms: 6, occupancy_rate: '70.00', active_tenants: 28, active_contracts: 14, expiring_soon_contracts: 1, expiring_window_days: 30, occupancy_status_mismatches: 1 },
  invoice_summary: { invoice_count: 20, invoice_total: '35000000.00', paid_amount: '27000000.00', outstanding_amount: '8000000.00', unpaid_invoice_count: 5, overdue_invoice_count: 3, overdue_amount: '4200000.00', statuses: { UNPAID: 4, PARTIALLY_PAID: 1, PAID: 15 } },
  boarding_houses: [{ id: 1, name: 'BeeHouse Dĩ An', operational_role: 'OWNER', total_rooms: 20, occupied_rooms: 14, available_rooms: 6, occupancy_rate: '70.00', outstanding_amount: '8000000.00' }],
  revenue_chart: ['05', '06', '07', '08', '09', '10'].map((month, index) => ({ month: `2026-${month}`, label: `${month}/2026`, amount: String(index * 1000000) })),
  recent_unpaid_invoices: [{ id: 4, invoice_code: 'HD-10', room_code: '101', boarding_house_name: 'BeeHouse Dĩ An', tenant_name: 'Nguyễn Văn A', remaining_amount: '3020000.00', due_date: '2026-10-15', is_overdue: true, status: 'PARTIALLY_PAID' }],
  expiring_contracts: [{ id: 7, contract_code: 'HĐ-101', room_code: '101', tenant_name: 'Nguyễn Văn A', end_date: '2026-10-31', days_remaining: 15 }],
}

test('dashboard renders KPI/house figures, Vietnamese dates, cash chart and correct navigation', () => {
  const html = renderToStaticMarkup(React.createElement(Content, { data: fixture }))
  for (const value of ['27.000.000 đ', '8.000.000 đ', '70%', '15/10/2026', '31/10/2026', 'Thực thu theo thời gian', 'Thanh toán một phần']) assert.ok(html.includes(value), value)
  assert.match(html, /href="\/invoices\/4"/)
  assert.match(html, /href="\/contracts\/7"/)
  assert.match(html, /bh-kpi-grid/)
  assert.doesNotMatch(html, /<table|min-w-\[|overflow-x-auto/)
  assert.doesNotMatch(html, />PARTIALLY_PAID</)
})

test('new account shows onboarding rather than a wall of zero cards; unassigned manager cannot create houses', () => {
  const data = { ...fixture, summary: { ...fixture.summary, total_boarding_houses: 0 } }
  const html = renderToStaticMarkup(React.createElement(Content, { data }))
  assert.match(html, /Bạn chưa có nhà trọ/)
  assert.match(html, /href="\/boarding-houses\/new"/)
  assert.doesNotMatch(html, /Tổng hóa đơn kỳ/)
  const manager = renderToStaticMarkup(React.createElement(Content, { data: { ...data, viewer: { ...data.viewer, can_create_boarding_house: false } } }))
  assert.doesNotMatch(manager, /href="\/boarding-houses\/new"/)
})

test('house without rooms gets a useful CTA and an empty payment chart has no invalid bar width', () => {
  const data = { ...fixture, summary: { ...fixture.summary, total_rooms: 0, occupied_rooms: 0, occupancy_rate: '0.00' }, revenue_chart: fixture.revenue_chart.map(month => ({ ...month, amount: '0.00' })) }
  const html = renderToStaticMarkup(React.createElement(Content, { data }))
  assert.match(html, /Nhà trọ chưa có phòng/)
  assert.match(html, /href="\/boarding-houses\/1\/rooms\/new"/)
  assert.match(html, /Thống kê thực thu theo thời gian đang được hoàn thiện/)
  assert.doesNotMatch(html, /NaN|Infinity/)
})

test('dashboard loading does not flash zero and API failure is friendly with retry', async () => {
  let state = { isPending: true }
  let queryOptions
  let retried = false
  const Page = await load('../../pages/dashboard/DashboardPage.jsx', 'DashboardPage', { DashboardContent: Content, DashboardFilters: () => React.createElement('div'), filterFromSearch: () => ({ preset: 'month', start: '2026-10-01', end: '2026-10-31', houseId: '', compare: false }), dashboardRefetchInterval, dashboardKey: ['dashboard'], getDashboard: () => {}, useSearchParams: () => [new URLSearchParams(), () => {}], useQuery: options => { queryOptions = options; return state } })
  const loading = renderToStaticMarkup(React.createElement(Page))
  assert.match(loading, /role="status"/)
  assert.doesNotMatch(loading, />0</)
  assert.equal(queryOptions.queryKey[0], 'dashboard')
  assert.equal(queryOptions.refetchOnWindowFocus, true)
  assert.equal(queryOptions.refetchInterval, dashboardRefetchInterval)
  state = { isError: true, error: new Error('AxiosError SQL secret'), refetch: () => { retried = true } }
  const error = renderToStaticMarkup(React.createElement(Page))
  assert.match(error, /Không thể tải dữ liệu tổng quan/)
  assert.doesNotMatch(error, /AxiosError|SQL|secret|stack/)
  const tree = Page()
  tree.props.children.find(node => React.isValidElement(node) && node.type === 'button').props.onClick()
  assert.equal(retried, true)
})

test('manager house cards do not link to owner-only house detail and free text is escaped', () => {
  const html = renderToStaticMarkup(React.createElement(Content, { data: { ...fixture, viewer: { ...fixture.viewer, name: '<script>alert(1)</script>' }, boarding_houses: [{ ...fixture.boarding_houses[0], operational_role: 'MANAGER' }] } }))
  assert.doesNotMatch(html, /href="\/boarding-houses\/1"|<script>/)
  assert.match(html, /Được phân công quản lý/)
})

test('house selection scopes room KPIs and keeps received payments distinct from invoice totals', () => {
  const other = { ...fixture.boarding_houses[0], id: 2, name: 'Cơ sở thứ hai', total_rooms: 4, occupied_rooms: 1, occupancy_rate: '25.00' }
  const data = { ...fixture, boarding_houses: [...fixture.boarding_houses, other] }
  const html = renderToStaticMarkup(React.createElement(Content, { data, filter: { houseId: '2' } }))
  assert.match(html, /Tổng số phòng.*?<p>4<\/p>/)
  assert.doesNotMatch(html, />5\.000\.000 đ<|Thực thu trong kỳ|Thực thu tháng/)
  assert.match(html, /Giá trị hóa đơn<\/dt><dd>35\.000\.000 đ/)
  assert.doesNotMatch(html, /BeeHouse Dĩ An.*Xem nhà trọ/)
})

test('unsupported cash hides current and previous metrics even with cached cash values', () => {
  const data = { ...fixture, analytics: { availability: { cash: 'unsupported' }, totals: { cash_received: '0.00' },
    previous: { from: '2026-09-01', to_exclusive: '2026-10-01', totals: {
      cash_received: '1234567.00', invoice_value: '35000000.00', invoice_paid_current: '27000000.00', invoice_due_current: '8000000.00',
    } },
  } }
  const html = renderToStaticMarkup(React.createElement(Content, { data }))
  assert.match(html, /Thống kê thực thu theo thời gian đang được hoàn thiện để đảm bảo độ chính xác của dữ liệu\./)
  assert.match(html, /Kỳ trước/)
  assert.match(html, /27\.000\.000 đ/)
  assert.doesNotMatch(html, />1\.234\.567 đ<|>5\.000\.000 đ<|<dd>0 đ<\/dd>|Thực thu trong kỳ|Thực thu tháng/)
})
