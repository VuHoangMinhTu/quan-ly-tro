const SYSTEM_ERROR = 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.'
const NETWORK_ERROR = 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra kết nối và thử lại.'
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
    const message = (Array.isArray(messages) ? messages : [messages]).map(usableMessage).find(Boolean)
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
  return usableMessage(error.response.data?.message)
    || Object.values(getApiFieldErrors(error))[0]
    || fallbackMessages[status]
    || 'Không thể thực hiện yêu cầu. Vui lòng thử lại.'
}
