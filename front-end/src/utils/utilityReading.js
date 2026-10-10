import { formatDateForApi, formatDateForDisplay } from './date'
import { formatCurrency } from './formatters'

export function formatMeterPrice(service) {
  if (service?.billing_method === 'TIERED') return 'Theo b\u1ea3ng gi\u00e1 b\u1eadc thang'
  if (service?.base_price == null) return 'Ch\u01b0a c\u1eadp nh\u1eadt'

  return `${formatCurrency(service.base_price)}${service.unit ? ` / ${service.unit}` : ''}`
}

// Preview only. The API remains the authority for duplicate dates and sequence rules.
export function getReadingPreview(readings, initialReading, displayDate, value) {
  const date = formatDateForApi(displayDate)
  if (!date || value == null || String(value).trim() === '' || !Number.isFinite(Number(value))) return null

  let previous = null
  for (const reading of readings) {
    const readingDate = formatDateForApi(formatDateForDisplay(reading.reading_date))
    if (readingDate && readingDate < date && (!previous || readingDate > previous.date)) {
      previous = { date: readingDate, value: Number(reading.reading_value) }
    }
  }

  const baseline = previous ? previous.value : Number(initialReading)
  if (!Number.isFinite(baseline)) return null

  return { baseline, consumption: Number(value) - baseline }
}
