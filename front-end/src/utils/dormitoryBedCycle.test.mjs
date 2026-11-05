import test from 'node:test'
import assert from 'node:assert/strict'
import { dormitoryBedCycle } from './dormitoryBedCycle.mjs'

test('two different beds have independent monthly cycles', () => {
  assert.deepEqual(dormitoryBedCycle('2026-11-05', '2026-11'), { start: '2026-11-05', end: '2026-12-05', fullCycle: true })
  assert.deepEqual(dormitoryBedCycle('2026-11-15', '2026-11'), { start: '2026-11-15', end: '2026-12-15', fullCycle: true })
})
test('renewal keeps the same anniversary day', () => {
  assert.deepEqual(dormitoryBedCycle('2026-11-05', '2026-12'), { start: '2026-12-05', end: '2027-01-05', fullCycle: true })
})
test('31st clamps February and resumes March anniversary', () => {
  assert.deepEqual(dormitoryBedCycle('2026-01-31', '2026-02'), { start: '2026-02-28', end: '2026-03-31', fullCycle: true })
})
test('never bill before contract begins', () => {
  assert.equal(dormitoryBedCycle('2026-12-15', '2026-11'), null)
})
