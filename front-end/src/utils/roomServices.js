import { formatCurrency } from './formatters'
import { getSuggestedServiceUnit } from './service'

export function isActiveFlag(value) {
  return value === true || value === 1 || value === '1'
}

export function isAppliedRoomService(service) {
  return isActiveFlag(service.is_active) && isActiveFlag(service.pivot?.is_active)
}

export function getMeterServices(services) {
  return services.filter((service) => isAppliedRoomService(service)
    && ['PER_UNIT', 'TIERED'].includes(service.billing_method))
}

export function getSelectedRoomServiceIds(services) {
  return services.filter(isAppliedRoomService).map((service) => Number(service.id))
}

export function toggleRoomService(selectedIds, service, catalog) {
  const serviceId = Number(service.id)
  if (selectedIds.includes(serviceId)) return selectedIds.filter((id) => id !== serviceId)

  // Only electricity/water are mutually exclusive. Other service types may
  // contain several independent charges, so never remove those by type.
  const exclusive = ['ELECTRICITY', 'WATER'].includes(service.type)
  const previousIds = exclusive
    ? selectedIds.filter((id) => !catalog.some((item) => Number(item.id) === id && item.type === service.type))
    : selectedIds

  return [...previousIds, serviceId]
}

export function formatRoomServicePrice(service) {
  if (service.billing_method === 'TIERED') return 'Theo bảng giá bậc thang'
  const unit = service.unit || getSuggestedServiceUnit(service.type, service.billing_method)
  return `${formatCurrency(service.base_price)}${unit ? ` / ${unit}` : ''}`
}
