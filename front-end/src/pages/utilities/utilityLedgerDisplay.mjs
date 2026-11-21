export const display = value => Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })

export function formatLedgerDate(value) {
  if (!value) return '—'
  return value.slice(0, 10).split('-').reverse().join('/')
}

export function replaceLedgerDate(value, date) {
  return date ? date + value.slice(10) : ''
}

export function readingWindowError(start, end) {
  if (!start || !end) return 'Chọn đủ ngày đầu kỳ và ngày cuối kỳ.'
  const normalize = value => value.replace('T', ' ').padEnd(19, ':00')
  return normalize(end) <= normalize(start) ? 'Mốc cuối kỳ phải sau mốc đầu kỳ.' : ''
}

export function readingError(value, previous) {
  if (value == null || value === '') return ''
  if (!/^\d+(?:\.\d{1,2})?$/.test(String(value)) || Number(value) > 9999999999999.99) return 'Nhập chỉ số hợp lệ, tối đa 2 chữ số thập phân.'
  return Number(value) < Number(previous) ? 'Chỉ số mới không được nhỏ hơn chỉ số cũ.' : ''
}

export function usageStatus(entries, meterCount) {
  if (entries.some(row => row.needsReview)) return 'Cần đối soát'
  if (!entries.length) return 'Chưa ghi'
  return entries.length < meterCount ? 'Ghi một phần' : 'Đã ghi'
}
