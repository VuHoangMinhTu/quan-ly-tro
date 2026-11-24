import test from 'node:test'
import assert from 'node:assert/strict'
import { additiveServiceIds, apiRows, assignedServiceIds, mergeDraftServiceSelections } from '../src/utils/contractRoomServicePicker.mjs'

test('understands standard and paginated API lists', () => {
  assert.deepEqual(apiRows({ data: { data: [{ id: 1 }] } }), [{ id: 1 }])
  assert.deepEqual(apiRows({ data: { data: { data: [{ id: 2 }] } } }), [{ id: 2 }])
  assert.deepEqual(apiRows({ data: { data: { services: [{ id: 3 }] } } }), [{ id: 3 }])
})
test('rejects an unknown response to avoid wiping room service assignments', () => {
  assert.throws(() => apiRows({ data: { data: {} } }), /không trả về/)
  assert.throws(() => assignedServiceIds([{ name: 'Unknown' }]), /Không xác định/)
})
test('keeps all previously assigned services and de-duplicates new additions', () => {
  assert.deepEqual(assignedServiceIds([{ service_id: 2 }, { service: { id: 3 } }, { id: 2 }]), [2, 3])
  assert.deepEqual(additiveServiceIds([2, 3], [3, 4, 4]), [2, 3, 4])
})
test('preserves agreed draft prices and adds newly chosen services', () => {
  const old = [{ service_id: 2, base_price: '12345', service_name: 'Internet' }]
  const all = [{ id: 2, base_price: 99999 }, { id: 4, base_price: 5000 }]
  const result = mergeDraftServiceSelections(old, all, [2, 4], row => ({ service_id: row.id, base_price: row.base_price }))
  assert.deepEqual(result, [old[0], { service_id: 4, base_price: 5000 }])
})
test('does not auto-select house services the owner did not pick', () => {
  assert.deepEqual(mergeDraftServiceSelections([], [{ id: 4 }, { id: 5 }], [4], r => ({ service_id: r.id })), [{ service_id: 4 }])
})
