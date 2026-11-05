import { test } from 'node:test'
import assert from 'node:assert/strict'
import { selectedUnitOpenings, openingDateFromContract } from './contractOpeningRules.mjs'
const available = [
  { id: 7, name: 'Điện', type: 'ELECTRICITY', unit: 'kWh' },
  { id: 8, name: 'Nước', type: 'WATER', unit: 'm³' },
  { id: 9, name: 'Mạng', type: 'INTERNET', unit: 'tháng' },
]
test('điện PER_UNIT vẫn hiện khi chưa đăng ký công tơ', () => {
  const a = selectedUnitOpenings([{ service_id: 7, billing_method: 'PER_UNIT' }], available, [])
  assert.equal(a.length, 1)
  assert.equal(a[0].utility_meter_id, null)
  assert.equal(a[0].opening_reading, '')
})
test('chọn cả điện và nước PER_UNIT hiện hai ô độc lập', () => {
  assert.deepEqual(selectedUnitOpenings([{ service_id: 7, billing_method: 'PER_UNIT' }, { service_id: 8, billing_method: 'PER_UNIT' }], available, []).map(x => x.service_id), [7, 8])
})
test('FIXED và dịch vụ không chọn không cần chỉ số', () => {
  assert.deepEqual(selectedUnitOpenings([{ service_id: 7, billing_method: 'FIXED' }, { service_id: 9, billing_method: 'PER_UNIT' }], available, []), [])
})
test('công tơ hiện có được liên kết, không tự lấy chỉ số mới nhất', () => {
  const a = selectedUnitOpenings([{ service_id: 7, billing_method: 'PER_UNIT' }], available, [{ service_id: 7, utility_meter_id: 42, opening_reading: '123' }])
  assert.equal(a[0].utility_meter_id, 42)
  assert.equal(a[0].opening_reading, '123')
  assert.equal(openingDateFromContract('2026-11-04'), '2026-11-04')
})
