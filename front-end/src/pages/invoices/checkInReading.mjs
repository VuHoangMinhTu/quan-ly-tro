// Pure frontend preflight: server remains authoritative for chronology, price and financial locks.
export function inspectCheckInReading(checkpoints, meterId, observedAt, value) {
  const time = observedAt ? (observedAt.replace('T', ' ') + (observedAt.length === 16 ? ':00' : '')) : ''
  const points = checkpoints.filter(p => Number(p.utility_meter_id) === Number(meterId))
    .filter(p => p.kind === 'PERIOD_BOUNDARY' && p.observed_at <= time)
    .sort((a, b) => a.observed_at.localeCompare(b.observed_at) || Number(a.id) - Number(b.id))
  const reference = points.at(-1) || null
  if (!meterId || !time || value === '' || value == null || !Number.isFinite(Number(value)) || Number(value) < 0) {
    return { allowed: false, reason: 'INCOMPLETE', reference, message: 'Vui lòng chọn công tơ, thời điểm và nhập số đo thực tế hợp lệ.' }
  }
  if (reference && reference.observed_at === time && Number(reference.reading_value) !== Number(value)) {
    return { allowed: false, reason: 'SAME_TIME_CONFLICT', reference, message: 'Chỉ số thực tế khác mốc đã xác nhận cùng thời điểm. Phải đối soát và đính chính mốc cũ trước.' }
  }
  if (reference && Number(value) < Number(reference.reading_value)) {
    return { allowed: false, reason: 'DECREASE', reference, message: 'Chỉ số thực tế thấp hơn số đã xác nhận trước đó. Kiểm tra nhập sai, thay đồng hồ hoặc vòng quay đồng hồ.' }
  }
  return { allowed: true, reason: 'OK', reference, message: reference ? 'Có thể xác nhận nếu đã kiểm tra công tơ thực tế. Backend sẽ đối soát lại các mốc và hợp đồng.' : 'Chưa có mốc đối chiếu; chỉ xác nhận khi có bằng chứng đọc số thực tế. Backend sẽ kiểm tra lại.' }
}
