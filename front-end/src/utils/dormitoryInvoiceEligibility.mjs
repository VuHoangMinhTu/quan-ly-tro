// Interpret fixed-term contract dates with the same half-open cycle boundary
// as DormitoryBedBilling::cycle(). Backend validation remains authoritative.
export const isoDay = value => {
  const date = String(value ?? '').slice(0, 10)
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : ''
}

export const formatDay = value => {
  const day = isoDay(value)
  return day ? day.split('-').reverse().join('/') : 'chưa xác định'
}

const previousDay = date => {
  const day = isoDay(date)
  if (!day) return ''
  const stamp = Date.parse(`${day}T00:00:00Z`)
  return Number.isFinite(stamp) ? new Date(stamp - 86_400_000).toISOString().slice(0, 10) : ''
}

// A lease ending on the start boundary does not cover the next cycle.
// A lease ending on the cycle's final occupied day is a full cycle, not partial.
export function cycleEndCoverage(contractEndDate, cycle) {
  const endDate = isoDay(contractEndDate)
  const start = isoDay(cycle?.start)
  const end = isoDay(cycle?.end)
  const lastDay = previousDay(end)
  if (!endDate || !start || !end || end <= start || !lastDay) {
    return { endDate, expired: false, shortened: false }
  }
  return {
    endDate,
    expired: endDate <= start,
    shortened: endDate > start && endDate < lastDay,
  }
}
