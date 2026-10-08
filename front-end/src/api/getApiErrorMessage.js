export function getApiErrorMessage(error) {
  const status = error?.response?.status

  if (status === 403) return 'Bạn không có quyền thực hiện thao tác này.'
  if (status === 404) return 'Không tìm thấy nhà trọ hoặc bạn không có quyền truy cập.'
  if (status >= 500) return 'Đã có lỗi xảy ra. Vui lòng thử lại.'

  return error?.response?.data?.message || 'Đã có lỗi xảy ra. Vui lòng thử lại.'
}
