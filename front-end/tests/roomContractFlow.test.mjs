import test from 'node:test'
import assert from 'node:assert/strict'
import { reviewableRoomContracts } from '../src/utils/roomContractFlow.mjs'

test('shows DRAFT when room has no ACTIVE contract', () => {
  assert.deepEqual(reviewableRoomContracts([{ id: 15, status: 'DRAFT' }]).map(x => x.id), [15])
})
test('prioritizes latest DRAFT before ACTIVE and omits ended contracts', () => {
  const records = [{ id: 1, status: 'ACTIVE' }, { id: 2, status: 'EXPIRED' }, { id: 4, status: 'DRAFT' }, { id: 3, status: 'DRAFT' }]
  assert.deepEqual(reviewableRoomContracts(records).map(x => x.id), [4, 3, 1])
  assert.equal(records[0].id, 1)
})
test('room with no relevant contracts is empty', () => {
  assert.deepEqual(reviewableRoomContracts([]), [])
  assert.deepEqual(reviewableRoomContracts(null), [])
  assert.deepEqual(reviewableRoomContracts([{ id: 3, status: 'TERMINATED' }]), [])
})
