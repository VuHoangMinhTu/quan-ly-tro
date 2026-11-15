const presets = { last7: 'last_7_days', last30: 'last_30_days' }
const calendar = ['day', 'week', 'month', 'quarter', 'year']
const epoch = value => Date.parse(value + 'T00:00:00Z')

export function visibleDashboardHouses(houses, houseId, ...errors) {
  return houseId && errors.some(error => error?.response?.status === 404)
    ? houses.filter(house => String(house.id) !== String(houseId))
    : houses
}
const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '') &&
  Number.isFinite(epoch(value)) && new Date(epoch(value)).toISOString().slice(0, 10) === value

export function analyticsParams(filter, search) {
  const params = Object.fromEntries(search)
  if (filter.houseId && !/^[1-9]\d*$/.test(filter.houseId)) return null
  const query = {
    preset: presets[filter.preset] ?? filter.preset,
    ...(filter.houseId && { boarding_house_id: filter.houseId }),
    ...(filter.compare && { compare: 1 }),
  }
  if (params.group) {
    if (!calendar.includes(params.group)) return null
    query.group_by = params.group
  }
  if (filter.preset === 'custom') {
    const from = params.start ?? filter.start
    const to = params.end ?? filter.end
    if (!validDate(from) || !validDate(to) || from < '1001-01-01' || to >= '9999-12-31' ||
      from > to || epoch(to) - epoch(from) >= 366 * 86400000) return null
    Object.assign(query, { from, to })
  }
  if (calendar.includes(filter.preset) && (params.date !== undefined || params.anchor !== undefined)) {
    const anchor = filter.preset === 'day' ? params.date : params.anchor
    if (!validDate(anchor) || anchor < '1001-01-01' || anchor >= '9999-01-01') return null
    query.anchor_date = anchor
  }
  return query
}

export function periodLabel({ from, to_exclusive: end }) {
  const last = new Date(epoch(end) - 86400000).toISOString().slice(0, 10)
  const display = value => value.split('-').reverse().join('/')
  return from === last ? display(from) : display(from) + ' – ' + display(last)
}

export function adaptDashboard(legacy, snapshot, analytics) {
  const label = periodLabel(analytics.period)
  const rows = analytics.series.map(bucket => ({
    ...bucket,
    label: bucket.from.split('-').reverse().join('/'),
  }))
  return {
    ...legacy,
    period: { label },
    summary: {
      ...legacy.summary,
      total_boarding_houses: snapshot.houses.length,
      total_rooms: snapshot.rooms.total,
      active_tenants: snapshot.tenants.active,
      active_contracts: snapshot.contracts.active,
      expiring_soon_contracts: snapshot.contracts.expiring_30d,
      expiring_window_days: 30,
    },
    boarding_houses: snapshot.houses.map(house => ({ ...house, occupancy_rate: house.occupancy_pct })),
    invoice_summary: {
      ...legacy.invoice_summary,
      invoice_total: analytics.totals.invoice_value,
      paid_amount: analytics.totals.invoice_paid_current,
      outstanding_amount: analytics.totals.invoice_due_current,
    },
    revenue_chart: rows.map(row => ({ month: row.from, label: row.label, amount: row.cash_received })),
    analytics: { ...analytics, rows },
    expiry_buckets: snapshot.contracts.expiry_buckets,
  }
}
