export const display = value => Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })

export function formatTime(value) {
  if (!value) return '—'
  const [date, time] = value.replace('T', ' ').split(' ')
  return `${date.split('-').reverse().join('/')} ${(time || '').slice(0, 5)}`.trim()
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
