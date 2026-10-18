export function editableContractService(service) {
  return {
    service_id: Number(service.service_id ?? service.id),
    service_name: service.service_name ?? service.name,
    billing_method: service.billing_method,
    unit: service.unit,
    base_price: service.base_price ?? '',
    vat_rate: service.vat_rate ?? 0,
    pricing_snapshot: (service.pricing_snapshot ?? service.price_tiers ?? []).map(tier => ({ ...tier })),
  }
}

export function contractServicePricingPayload(value) {
  return {
    base_price: value.billing_method === 'TIERED' || value.base_price === '' || value.base_price == null ? null : Number(value.base_price),
    vat_rate: value.vat_rate === '' || value.vat_rate == null ? 0 : Number(value.vat_rate),
    pricing_snapshot: value.billing_method !== 'TIERED' ? null : (value.pricing_snapshot ?? []).map(tier => ({
      from_quantity: Number(tier.from_quantity),
      to_quantity: tier.to_quantity === '' || tier.to_quantity == null ? null : Number(tier.to_quantity),
      unit_price: Number(tier.unit_price),
    })),
  }
}

export const contractServicesPayload = rows => rows.map(row => ({ service_id: Number(row.service_id), ...contractServicePricingPayload(row) }))

export function contractServicePricingError(value) {
  const vat = Number(value.vat_rate ?? 0)
  if (!Number.isFinite(vat) || vat < 0 || vat > 100) return 'VAT phải từ 0 đến 100%.'
  if (value.billing_method !== 'TIERED') {
    if (value.base_price === '' || value.base_price == null || !Number.isFinite(Number(value.base_price)) || Number(value.base_price) < 0) return 'Vui lòng nhập mức giá dịch vụ không âm.'
    return ''
  }
  const tiers = value.pricing_snapshot ?? []
  if (!tiers.length) return 'Vui lòng thiết lập bảng giá bậc thang.'
  let next = 0
  for (let index = 0; index < tiers.length; index += 1) {
    const tier = tiers[index]
    if (tier.from_quantity === '' || !Number.isFinite(Number(tier.from_quantity)) || Number(tier.from_quantity) !== next) return 'Các bậc giá phải liên tiếp từ 0, không chồng lấn hoặc bỏ khoảng.'
    if (tier.unit_price === '' || tier.unit_price == null || !Number.isFinite(Number(tier.unit_price)) || Number(tier.unit_price) < 0) return 'Đơn giá mỗi bậc phải là số không âm.'
    if (tier.to_quantity === '' || tier.to_quantity == null) {
      if (index !== tiers.length - 1) return 'Bậc không giới hạn phải là bậc cuối cùng.'
    } else {
      next = Number(tier.to_quantity)
      if (!Number.isFinite(next) || next <= Number(tier.from_quantity)) return 'Giới hạn trên phải lớn hơn giới hạn dưới.'
      if (index === tiers.length - 1) return 'Bậc cuối cùng phải không giới hạn.'
    }
  }
  return ''
}

export const termAppliesOn = (term, date) => Boolean(term.is_active && term.effective_from && term.effective_from <= date && (!term.effective_to || term.effective_to >= date))
