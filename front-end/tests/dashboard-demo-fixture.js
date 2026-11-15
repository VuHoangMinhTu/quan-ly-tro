const houses = [
  { id: 1, name: 'BeeHouse An Phú', total_rooms: 42, occupied_rooms: 38, outstanding_amount: '6400000' },
  { id: 2, name: 'BeeHouse Riverside', total_rooms: 36, occupied_rooms: 27, outstanding_amount: '3800000' },
  { id: 3, name: 'BeeHouse Bình An', total_rooms: 28, occupied_rooms: 17, outstanding_amount: '2250000' },
  { id: 4, name: 'BeeHouse Garden', total_rooms: 24, occupied_rooms: 20, outstanding_amount: '1800000' },
].map(house => ({ ...house, operational_role: 'MANAGER', occupancy_rate: Number((house.occupied_rooms / house.total_rooms * 100).toFixed(2)) }))

const amounts = [34.4, 40.8, 36.2, 48.9, 43.6, 60.1, 56.3, 51.7, 65.8, 61.4, 77.2, 69.6]
const revenue = amounts.map((amount, index) => {
  const month = String(index + 1).padStart(2, '0')
  return { month: '2026-' + month, label: month + '/2026', amount: Math.round(amount * 1000000) }
})
const invoices = [
  { label: '09/2026', billed: 73600000, paid: 68100000, due: 5500000 },
  { label: '10/2026', billed: 70900000, paid: 64900000, due: 6000000 },
  { label: '11/2026', billed: 85100000, paid: 74000000, due: 11100000 },
  { label: '12/2026', billed: 84200000, paid: 69950000, due: 14250000 },
]
const expenses = [28600000, 33900000, 31200000, 36200000]
const contracts = [
  { label: '0–7 ngày', count: 3 },
  { label: '8–14 ngày', count: 5 },
  { label: '15–21 ngày', count: 2 },
  { label: '22–30 ngày', count: 4 },
]

export const demoSeries = {
  invoices,
  cash: revenue.slice(-4).map((row, index) => ({ label: row.label, received: row.amount, expense: expenses[index] })),
  contracts,
}

export const demoDashboard = {
  viewer: { can_create_boarding_house: false },
  period: { month: '2026-12', label: '12/2026' },
  summary: { total_boarding_houses: 4, total_rooms: 130, active_tenants: 142, expiring_soon_contracts: 14, expiring_window_days: 30 },
  boarding_houses: houses,
  revenue_chart: revenue,
  invoice_summary: { invoice_total: '84200000', paid_amount: '69950000', outstanding_amount: '14250000', overdue_invoice_count: 4, unpaid_invoice_count: 11 },
  expiring_contracts: [
    { id: 'demo-contract-1', contract_code: 'MH-024', room_code: 'A204', end_date: '2026-12-05', days_remaining: 4 },
    { id: 'demo-contract-2', contract_code: 'MH-053', room_code: 'B308', end_date: '2026-12-12', days_remaining: 11 },
  ],
  recent_unpaid_invoices: [
    { id: 'demo-invoice-1', invoice_code: 'MH-1024', boarding_house_name: houses[0].name, room_code: 'A204', remaining_amount: '3200000', due_date: '2026-12-05', status: 'UNPAID', is_overdue: true },
    { id: 'demo-invoice-2', invoice_code: 'MH-1025', boarding_house_name: houses[1].name, room_code: 'B308', remaining_amount: '1400000', due_date: '2026-12-10', status: 'PARTIALLY_PAID' },
  ],
}
