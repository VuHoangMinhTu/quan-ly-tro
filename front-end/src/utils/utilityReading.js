import { formatDateForApi, formatDateForDisplay } from './date'
import { formatCurrency } from './formatters'

export function formatMeterPrice(service) {
  if (service?.billing_method === 'TIERED') return 'Theo bảng giá bậc thang'
  if (service?.base_price == null) return 'Chưa cập nhật'

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
