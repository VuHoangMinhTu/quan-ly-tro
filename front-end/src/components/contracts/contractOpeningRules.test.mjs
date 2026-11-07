import test from 'node:test'
import assert from 'node:assert/strict'
import { selectedUnitOpenings, openingDateFromContract } from './contractOpeningRules.mjs'
const available = [
  { id: 1, type: 'ELECTRICITY' }, { id: 2, type: 'WATER' },
  { id: 3, type: 'WATER' }, { id: 4, type: 'INTERNET' },
]
const opening = (id) => ({ service_id: id })
test('PER_UNIT electric and water require a starting reading only when selected', () => {
  const selected = [{ service_id: 1, billing_method: 'PER_UNIT' }, { service_id: 2, billing_method: 'PER_UNIT' }]
  assert.deepEqual(selectedUnitOpenings(selected, available, [opening(1), opening(2), opening(3)]).map(row => row.service_id), [1, 2])
})
test('FIXED, PER_PERSON, TIERED, and non-utility services never require opening', () => {
  const selected = [{ service_id: 1, billing_method: 'FIXED' }, { service_id: 2, billing_method: 'PER_PERSON' }, { service_id: 3, billing_method: 'TIERED' }, { service_id: 4, billing_method: 'PER_UNIT' }]
  assert.deepEqual(selectedUnitOpenings(selected, available, [1, 2, 3, 4].map(opening)), [])
})
test('opening date follows step-one contract date rather than latest meter timestamp', () => {
  assert.equal(openingDateFromContract('2026-11-04'), '2026-11-04')
  assert.equal(openingDateFromContract(''), null)
})
