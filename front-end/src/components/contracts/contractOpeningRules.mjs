export function openingDateFromContract(startDate) {
  return /^\d{4}-\d{2}-\d{2}$/.test(startDate || '') ? startDate : null
}

// Selected services decide which inputs are shown, never the presence of a meter.
export function selectedUnitOpenings(selectedServices, availableServices, openings) {
  return selectedServices.flatMap(selected => {
    const service = availableServices.find(item => Number(item.id) === Number(selected.service_id))
    if (!service || !['ELECTRICITY', 'WATER'].includes(service.type) || selected.billing_method !== 'PER_UNIT') return []
    const previous = openings.find(item => Number(item.service_id) === Number(selected.service_id))
    return [{ service_id: service.id, service_name: service.name, unit: service.unit, utility_meter_id: previous?.utility_meter_id ?? null, opening_reading: previous?.opening_reading ?? '' }]
  })
}
