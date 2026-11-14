const timezone = 'Asia/Ho_Chi_Minh'

export const dashboardPresets = [
  ['today', 'Hôm nay'], ['yesterday', 'Hôm qua'], ['last7', '7 ngày'], ['last30', '30 ngày'],
  ['day', 'Theo ngày'], ['week', 'Theo tuần'], ['month', 'Theo tháng'], ['quarter', 'Theo quý'], ['year', 'Theo năm'], ['custom', 'Tùy chọn'],
]

export function vietnamDate(date = new Date()) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date)
}

function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T12:00:00Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
}

function shift(date, days) { const value = new Date(`${date}T12:00:00Z`); value.setUTCDate(value.getUTCDate() + days); return value.toISOString().slice(0, 10) }
function monthBounds(date) { return [date.slice(0, 7) + '-01', new Date(Date.UTC(+date.slice(0, 4), +date.slice(5, 7), 0)).toISOString().slice(0, 10)] }

export function filterFromSearch(search, today = vietnamDate()) {
  const params = new URLSearchParams(search)
  const preset = dashboardPresets.some(([key]) => key === params.get('period')) ? params.get('period') : 'month'
  let [start, end] = preset === 'month' ? monthBounds(today) : [today, today]
  if (preset === 'yesterday') [start, end] = [shift(today, -1), shift(today, -1)]
  if (preset === 'last7') [start, end] = [shift(today, -6), today]
  if (preset === 'last30') [start, end] = [shift(today, -29), today]
  if (preset === 'week') { const weekday = new Date(`${today}T12:00:00Z`).getUTCDay() || 7; start = shift(today, 1 - weekday); end = shift(start, 6) }
  if (preset === 'quarter') { const month = +today.slice(5, 7); const first = Math.floor((month - 1) / 3) * 3 + 1; start = `${today.slice(0, 4)}-${String(first).padStart(2, '0')}-01`; end = new Date(Date.UTC(+today.slice(0, 4), first + 2, 0)).toISOString().slice(0, 10) }
  if (preset === 'year') { start = `${today.slice(0, 4)}-01-01`; end = `${today.slice(0, 4)}-12-31` }
  if (preset === 'day' && validDate(params.get('date'))) start = end = params.get('date')
  if (preset === 'custom' && validDate(params.get('start')) && validDate(params.get('end')) && params.get('start') <= params.get('end')) [start, end] = [params.get('start'), params.get('end')]
  return { preset, start, end, houseId: params.get('house') || '', compare: params.get('compare') === '1' }
}

export function filterLabel(filter) {
  const label = dashboardPresets.find(([key]) => key === filter.preset)?.[1] || 'Theo tháng'
  return `${label}: ${new Intl.DateTimeFormat('vi-VN', { timeZone: timezone }).format(new Date(`${filter.start}T12:00:00Z`))}${filter.start === filter.end ? '' : ` – ${new Intl.DateTimeFormat('vi-VN', { timeZone: timezone }).format(new Date(`${filter.end}T12:00:00Z`))}`}`
}
