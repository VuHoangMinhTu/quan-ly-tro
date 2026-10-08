const displayPattern = /^(\d{2})\/(\d{2})\/(\d{4})$/

export function daysInMonth(year, month) {
  if (month === 2) return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28
  return [4, 6, 9, 11].includes(month) ? 30 : 31
}

export function parseDisplayDate(value) {
  const match = displayPattern.exec(value)
  if (!match) return null

  const [, dayText, monthText, yearText] = match
  const day = Number(dayText)
  const month = Number(monthText)
  const year = Number(yearText)
  if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
    return null
  }

  return { dayText, monthText, yearText, day, month, year }
}

function addDaysToApiDate(dateOnly, days) {
  const [yearText, monthText, dayText] = dateOnly.split('-')
  let year = Number(yearText)
  let month = Number(monthText)
  let day = Number(dayText) + days

  while (day > daysInMonth(year, month)) {
    day -= daysInMonth(year, month)
    month += 1
    if (month > 12) { month = 1; year += 1 }
  }
  while (day < 1) {
    month -= 1
    if (month < 1) { month = 12; year -= 1 }
    day += daysInMonth(year, month)
  }

  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function apiDateInBusinessTimezone(value) {
  const text = String(value)
  const dateOnly = text.split('T')[0]
  const timeMatch = text.match(/T(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/)
  if (!timeMatch) return dateOnly

  const [, hoursText, minutesText, timezone] = timeMatch
  const sourceOffset = timezone === 'Z' ? 0 : (timezone.startsWith('+') ? 1 : -1) * (Number(timezone.slice(1, 3)) * 60 + Number(timezone.slice(4, 6)))
  const vietnamOffset = 7 * 60
  const localMinutes = Number(hoursText) * 60 + Number(minutesText) + vietnamOffset - sourceOffset
  return addDaysToApiDate(dateOnly, Math.floor(localMinutes / (24 * 60)))
}

export function formatDateForDisplay(value) {
  if (!value) return ''
  if (displayPattern.test(value)) return value

  const [year, month, day] = apiDateInBusinessTimezone(value).split('-')
  return year && month && day ? `${day}/${month}/${year}` : ''
}

export function formatDateForApi(value) {
  const parsed = parseDisplayDate(value)
  return parsed ? `${parsed.yearText}-${parsed.monthText}-${parsed.dayText}` : null
}

export function isValidDisplayDate(value) {
  return Boolean(parseDisplayDate(value))
}

export function isNotFutureDisplayDate(value) {
  const parsed = parseDisplayDate(value)
  if (!parsed) return false

  return `${parsed.yearText}-${parsed.monthText}-${parsed.dayText}` <= formatDateForApi(getTodayForDisplay())
}

export function getTodayForDisplay() {
  const today = new Date()
  const day = String(today.getDate()).padStart(2, '0')
  const month = String(today.getMonth() + 1).padStart(2, '0')
  return `${day}/${month}/${today.getFullYear()}`
}

export function formatBillingPeriodForDisplay(value) {
  if (!value) return ''
  const [year, month] = value.slice(0, 7).split('-')
  return year && month ? `${month}/${year}` : ''
}

export function formatBillingPeriodForApi(value) {
  const match = /^(0[1-9]|1[0-2])\/(\d{4})$/.exec(value)
  return match ? `${match[2]}-${match[1]}-01` : null
}

export function isValidBillingPeriod(value) {
  return /^(0[1-9]|1[0-2])\/\d{4}$/.test(value)
}
