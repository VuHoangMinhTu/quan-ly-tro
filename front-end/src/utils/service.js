export const SERVICE_TYPE_LABELS = {
  ELECTRICITY: 'Điện',
  WATER: 'Nước',
  INTERNET: 'Internet',
  PARKING: 'Giữ xe',
  TRASH: 'Rác',
  CLEANING: 'Vệ sinh',
  OTHER: 'Khác',
}

export const BILLING_METHOD_LABELS = {
  FIXED: 'Cố định',
  PER_UNIT: 'Theo đơn vị',
  PER_PERSON: 'Theo người',
  TIERED: 'Bậc thang',
}

export const SERVICE_TYPES = Object.keys(SERVICE_TYPE_LABELS)
export const BILLING_METHODS = Object.keys(BILLING_METHOD_LABELS)

export function getSuggestedServiceUnit(type, billingMethod) {
  if (billingMethod === 'PER_PERSON') return 'người'
  if (billingMethod === 'FIXED') return 'tháng'
  if (type === 'ELECTRICITY') return 'kWh'
  if (type === 'WATER') return 'm³'
  return ''
}

export function getBasePriceLabel(billingMethod) {
  if (billingMethod === 'PER_PERSON') return 'Đơn giá / người'
  return billingMethod === 'FIXED' ? 'Phí cố định' : 'Đơn giá'
}
