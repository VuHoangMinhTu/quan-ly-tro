const SYSTEM_ERROR = 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.'
const NETWORK_ERROR = 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra kết nối và thử lại.'
const fieldLabels = { name: 'tên thiết bị', quantity: 'số lượng', condition: 'tình trạng', note: 'ghi chú', key_count: 'số lượng chìa', remote_count: 'số lượng remote', room_code: 'mã phòng', room_type: 'loại phòng', private_room_type: 'phân loại phòng', full_name: 'họ tên', phone: 'số điện thoại', email: 'email', identity_number: 'CCCD/CMND', contract_code: 'mã hợp đồng', billing_period: 'kỳ hóa đơn', monthly_rent: 'giá thuê', max_tenants: 'số người tối đa', area: 'diện tích', status: 'trạng thái', date_of_birth: 'ngày sinh', start_date: 'ngày bắt đầu', end_date: 'ngày kết thúc', amount: 'số tiền', bed_code: 'mã giường' }

export function normalizeValidationMessage(message, field = '') {
  if (typeof message !== 'string') return ''
  const nested = field.match(/^(items|rooms|beds|occupants)\.(\d+)\.([a-z_]+)$/)
    || message.match(/\b(items|rooms|beds|occupants)\.(\d+)\.([a-z_]+)\b/)
  let text = message
  if (nested) {
    const [, group, index, property] = nested
    const key = `${group}.${index}.${property}`
    const label = fieldLabels[property] || 'thông tin'
    if (text.startsWith(`${key}:`)) text = text.slice(key.length + 1).trim()
    text = text.replaceAll(key, label)
    const prefix = `${{ items: 'Hạng mục', rooms: 'Phòng dòng', beds: 'Giường dòng', occupants: 'Người ở dòng' }[group]} ${Number(index) + 1}: `
    if (!text.startsWith(prefix)) text = prefix + text
  }
  for (const [key, label] of Object.entries(fieldLabels)) text = text.replace(new RegExp(`\\b${key}\\b`, 'g'), label)
  if (/\b(?:validation\.[a-z_.]+|[a-z_]+\.\d+(?:\.[a-z_]+)?)\b/i.test(text)) return 'Dữ liệu chưa hợp lệ. Vui lòng kiểm tra các trường được đánh dấu.'
  return text
}
const fallbackMessages = {
  400: 'Yêu cầu không hợp lệ.',
  401: 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.',
  403: 'Bạn không có quyền thực hiện thao tác này.',
  404: 'Không tìm thấy dữ liệu.',
  405: 'Phương thức yêu cầu không được hỗ trợ.',
  409: 'Yêu cầu bị xung đột với dữ liệu hiện tại. Vui lòng tải lại và thử lại.',
  419: 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.',
  422: 'Dữ liệu không hợp lệ.',
  429: 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.',
}

function usableMessage(value) {
  if (typeof value !== 'string' || !value.trim()) return ''
  const message = value.trim()
  // Framework/transport messages and internal diagnostics aren't user-facing.
  if (/^(the given data was invalid\.?|validation failed\.?|dữ liệu không hợp lệ\.?|error\.?|server error\.?|unauthenticated\.?|forbidden\.?|not found\.?|network error\.?|request failed with status code.*)$/i.test(message)) return ''
  if (/SQLSTATE|stack trace|integrity constraint|call to a member function|undefined (property|variable)|(?:[A-Z]:\\|\/(?:var|home|srv)\/)|(?:APP_KEY|DB_PASSWORD|client_secret|checksum_key)\s*[:=]/i.test(message)) return ''
  return message
}

export function getApiFieldErrors(error) {
  if (error?.response?.status >= 500) return {}
  const errors = error?.response?.data?.errors
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) return {}
  return Object.fromEntries(Object.entries(errors).flatMap(([field, messages]) => {
    const message = (Array.isArray(messages) ? messages : [messages]).map(usableMessage).filter(Boolean).map((message) => normalizeValidationMessage(message, field)).find(Boolean)
    return message ? [[field, message]] : []
  }))
}

// Shared adapter for React Hook Form; nested API keys may map to one UI field.
export function applyApiFieldErrors(error, setError, mapField = (field) => field) {
  const applied = new Set()
  for (const [field, message] of Object.entries(getApiFieldErrors(error))) {
    const target = mapField(field)
    if (!target || applied.has(target)) continue
    setError(target, { type: 'server', message })
    applied.add(target)
  }
  return applied.size
}

export function getApiErrorMessage(error) {
  if (!error?.response) return NETWORK_ERROR
  const status = error.response.status
  // Even a verbose development response must never leak a server exception.
  if (status >= 500) return SYSTEM_ERROR
  return (status === 422 ? Object.values(getApiFieldErrors(error))[0] : '')
    || normalizeValidationMessage(usableMessage(error.response.data?.message))
    || Object.values(getApiFieldErrors(error))[0]
    || fallbackMessages[status]
    || 'Không thể thực hiện yêu cầu. Vui lòng thử lại.'
}
