// Keep existing room assignments when adding services from the house catalog.
// Never delete/reprice a draft service as a side-effect of adding another one.
export const serviceId = row => Number(row?.service_id ?? row?.service?.id ?? row?.id)

export function apiRows(response) {
  const data = response?.data?.data
  if (Array.isArray(data)) return data
  if (Array.isArray(data?.data)) return data.data
  if (Array.isArray(data?.services)) return data.services
  if (Array.isArray(data?.room_services)) return data.room_services
  throw new Error('API không trả về danh sách dịch vụ hợp lệ. Không thay đổi dịch vụ của phòng.')
}

export function assignedServiceIds(rows) {
  if (!Array.isArray(rows)) throw new Error('Danh sách dịch vụ phòng không hợp lệ.')
  const ids = rows.map(serviceId)
  if (ids.some(id => !Number.isSafeInteger(id) || id <= 0)) {
    throw new Error('Không xác định được dịch vụ đã áp dụng ở phòng. Đã hủy lưu để tránh mất dữ liệu.')
  }
  return [...new Set(ids)]
}

export function additiveServiceIds(existingIds, selectedIds) {
  const ids = [...existingIds, ...selectedIds].map(Number)
  if (ids.some(id => !Number.isSafeInteger(id) || id <= 0)) throw new Error('Mã dịch vụ không hợp lệ.')
  return [...new Set(ids)]
}

// editableContractService is passed from the wizard to avoid changing the
// business-specific price editor's data contract here.
export function mergeDraftServiceSelections(previous, available, newlyAddedIds, convert) {
  const known = new Set(previous.map(row => Number(row.service_id)))
  const additions = available.filter(row => newlyAddedIds.map(Number).includes(Number(row.id)) && !known.has(Number(row.id)))
  return [...previous, ...additions.map(convert)]
}
