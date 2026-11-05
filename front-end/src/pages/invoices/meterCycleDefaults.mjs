// Read-only selection of confirmed meter boundaries for the selected billing month.
// This does not edit historical checkpoints or any billed period.
const timestamp = value => String(value || '').replace('T', ' ').slice(0, 19)
const localInput = value => String(value || '').replace(' ', 'T')

export function resolveMeterCycleDefault({ meter, period, usageWindow, data }) {
  const all = data?.checkpoints || []
  const meterId = Number(meter.id)
  const points = all.filter(point => Number(point.utility_meter_id) === meterId && point.kind === 'PERIOD_BOUNDARY')
  const byId = id => points.find(point => Number(point.id) === Number(id))
  const corrections = (data?.checkpoint_corrections || [])
    .filter(row => Number(row.utility_meter_id) === meterId)
    .slice().sort((a, b) => Number(a.id) - Number(b.id))
  const selected = (data?.periods || []).find(row => Number(row.utility_meter_id) === meterId)
  const [year, month] = period.split('-').map(Number)
  const nextNextMonth = new Date(year, month + 1, 1)
  const limit = `${nextNextMonth.getFullYear()}-${String(nextNextMonth.getMonth() + 1).padStart(2, '0')}-01 00:00:00`
  const fitsSelectedPeriod = (start, end) => Boolean(start && end &&
    timestamp(start.observed_at).slice(0, 7) === period &&
    timestamp(end.observed_at) > timestamp(start.observed_at) &&
    timestamp(end.observed_at) < limit)
  const pair = (start, end) => ({ start: localInput(start.observed_at), end: localInput(end.observed_at) })

  if (selected) {
    let startId = selected.start_checkpoint_id
    let endId = selected.end_checkpoint_id
    for (const correction of corrections) {
      if (Number(correction.old_start_checkpoint_id) === Number(startId) && Number(correction.old_end_checkpoint_id) === Number(endId)) {
        startId = correction.new_start_checkpoint_id
        endId = correction.new_end_checkpoint_id
      }
    }
    const start = byId(startId), end = byId(endId)
    // A calculated cycle must not silently be replaced by an inferred range.
    return {
      start: start ? localInput(start.observed_at) : localInput(selected.usage_start_at || usageWindow.start),
      end: end ? localInput(end.observed_at) : localInput(selected.usage_end_at || usageWindow.end),
    }
  }

  // Corrections store explicit old/new pairs. Prefer the newest valid corrected
  // pair rather than independently taking the first/last points from history.
  for (const correction of corrections.slice().reverse()) {
    const start = byId(correction.new_start_checkpoint_id)
    const end = byId(correction.new_end_checkpoint_id)
    if (fitsSelectedPeriod(start, end)) return pair(start, end)
  }

  // Ignore superseded points, but do not discard an unchanged endpoint if a
  // correction reused the same checkpoint ID.
  const superseded = new Set()
  for (const correction of corrections) {
    if (Number(correction.old_start_checkpoint_id) !== Number(correction.new_start_checkpoint_id)) superseded.add(Number(correction.old_start_checkpoint_id))
    if (Number(correction.old_end_checkpoint_id) !== Number(correction.new_end_checkpoint_id)) superseded.add(Number(correction.old_end_checkpoint_id))
  }
  const boundaries = points.filter(point => !superseded.has(Number(point.id)))
    .sort((a, b) => timestamp(a.observed_at).localeCompare(timestamp(b.observed_at)) || Number(a.id) - Number(b.id))
  const monthStarts = boundaries.filter(point => timestamp(point.observed_at).slice(0, 7) === period)
  const possiblePairs = []
  for (const start of monthStarts) {
    const next = boundaries.find(point => timestamp(point.observed_at) > timestamp(start.observed_at) && timestamp(point.observed_at) < limit)
    if (next) possiblePairs.push([start, next])
  }
  // If more than one candidate exists, don't silently guess a billing window.
  if (possiblePairs.length === 1) return pair(...possiblePairs[0])
  if (monthStarts.length === 1) return { start: localInput(monthStarts[0].observed_at), end: usageWindow.end }
  return { start: usageWindow.start, end: usageWindow.end }
}

/** Proposed window only; confirmed meter boundaries remain authoritative. */
export function suggestedMeterUsageWindow(period, contracts = []) {
  const [year, month] = String(period).slice(0, 7).split('-').map(Number)
  const local = (y, m, day) => {
    const last = new Date(y, m, 0).getDate()
    const date = new Date(y, m - 1, Math.min(last, day))
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T00:00`
  }
  const monthStart = local(year, month, 1).slice(0, 10)
  const nextMonthStart = local(year, month + 1, 1).slice(0, 10)
  const relevant = contracts.filter(contract => {
    const start = String(contract.start_date || '').slice(0, 10)
    const end = String(contract.end_date || '').slice(0, 10)
    return start && start < nextMonthStart && (!end || end >= monthStart)
  })
  const days = [...new Set(relevant.map(contract => Number(String(contract.start_date).slice(8, 10))).filter(day => day >= 1 && day <= 31))]
  const ambiguous = days.length > 1
  const day = days.length === 1 ? days[0] : 1
  return { start: local(year, month, day), end: local(year, month + 1, day), ambiguous }
}