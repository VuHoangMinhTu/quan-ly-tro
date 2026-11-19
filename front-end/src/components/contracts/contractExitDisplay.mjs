// Billing V2 accepts local wall-clock timestamps. Never pass these through the browser timezone.
export function formatExitTime(value) {
  if (!value) return 'Chưa có thông tin'
  if (/(Z|[+-]\d\d:\d\d)$/.test(value)) {
    const date = new Date(value)
    if (!Number.isFinite(date.getTime())) return 'Chưa có thông tin'
    const parts = Object.fromEntries(new Intl.DateTimeFormat('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date).map(part => [part.type, part.value]))
    return `${parts.day}/${parts.month}/${parts.year} ${parts.hour}:${parts.minute}`
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(value)
  return match ? `${match[3]}/${match[2]}/${match[1]}${match[4] ? ` ${match[4]}:${match[5]}` : ''}` : 'Chưa có thông tin'
}

export function checkoutMeasurement(checkpoints, meterId, contractId, endedAt, reading) {
  const end = endedAt.replace('T', ' ').padEnd(19, ':00')
  const points = checkpoints.filter(point => String(point.utility_meter_id) === String(meterId) && point.confirmed_by != null)
  const opening = points.find(point => point.kind === 'CHECK_IN' && String(point.contract_id) === String(contractId))
  if (!opening || opening.observed_at >= end) return { previous: null, usage: null }
  const previous = points.filter(point => point.observed_at >= opening.observed_at && point.observed_at < end &&
    (point.id === opening.id || point.kind === 'PERIOD_BOUNDARY'))
    .sort((a, b) => b.observed_at.localeCompare(a.observed_at) || Number(b.revision) - Number(a.revision))[0]
  if (previous.reading_value == null || previous.reading_value === '' || !Number.isFinite(Number(previous.reading_value)) || Number(previous.reading_value) < 0) return { previous: null, usage: null }
  const valid = reading !== '' && reading != null && Number.isFinite(Number(reading)) && Number(reading) >= Number(previous.reading_value)
  return { previous, usage: valid ? Math.round((Number(reading) - Number(previous.reading_value)) * 100) / 100 : null }
}

export function contractDebt(invoices, contractId) {
  const rows = invoices.filter(row => String(row.contract_id) === String(contractId) && ['UNPAID', 'PARTIALLY_PAID'].includes(row.status))
  const valid = rows.every(row => row.total_amount != null && row.total_amount !== '' && row.paid_amount != null && row.paid_amount !== '' && Number.isFinite(Number(row.total_amount)) && Number.isFinite(Number(row.paid_amount)) && Number(row.paid_amount) >= 0 && Number(row.total_amount) >= Number(row.paid_amount))
  return { rows, total: valid ? rows.reduce((sum, row) => sum + Math.round((Number(row.total_amount) - Number(row.paid_amount)) * 100), 0) / 100 : null,
    unassigned: invoices.some(row => row.contract_id == null && ['UNPAID', 'PARTIALLY_PAID'].includes(row.status)),
    drafts: invoices.filter(row => String(row.contract_id) === String(contractId) && row.status === 'DRAFT').length }
}
