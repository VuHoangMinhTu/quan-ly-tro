import { formatDateForDisplay } from './date'

export function formatVnd(value) {
  const numericValue = Number(value)

  return new Intl.NumberFormat('vi-VN', {
    maximumFractionDigits: 0,
  }).format(Number.isFinite(numericValue) ? numericValue : 0)
}

export function parseVnd(value) {
  const digits = String(value ?? '').replace(/\D/g, '')

  return digits === '' ? '' : Number(digits)
}

export function formatCurrency(value) {
  return `${formatVnd(value)} đ`
}

export function formatArea(value) {
  return value === null || value === undefined ? '—' : `${Number(value)} m²`
}

export function formatUtilityValue(value) {
  const numericValue = Number(value)

  if (!Number.isFinite(numericValue)) return ''

  return new Intl.NumberFormat('vi-VN', {
    maximumFractionDigits: 2,
  }).format(numericValue)
}

export function formatDate(value) {
  return formatDateForDisplay(value) || 'Chưa cập nhật'
}

export const genderLabels = { MALE: 'Nam', FEMALE: 'Nữ', OTHER: 'Khác' }
