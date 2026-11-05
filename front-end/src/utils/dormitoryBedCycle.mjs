/** One billing month is only the accounting label. Each bed contract has its own anniversary. */
export function dormitoryBedCycle(contractStart, period) {
  const origin = String(contractStart || '').slice(0, 10)
  const label = String(period || '').slice(0, 7)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(origin) || !/^\d{4}-\d{2}$/.test(label)) return null
  const [year, month] = label.split('-').map(Number)
  const day = Number(origin.slice(-2))
  if (month < 1 || month > 12 || day < 1 || day > 31) return null
  const make = (yearValue, monthValue) => {
    const last = new Date(yearValue, monthValue, 0).getDate()
    const date = new Date(yearValue, monthValue - 1, Math.min(day, last))
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
  }
  const start = make(year, month)
  const end = make(year, month + 1)
  return start < origin ? null : { start, end, fullCycle: true }
}
