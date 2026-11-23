import assert from 'node:assert/strict'
import test from 'node:test'
import { cycleEndCoverage, formatDay } from '../src/utils/dormitoryInvoiceEligibility.mjs'

const cycle = { start: '2026-12-02', end: '2027-01-02' }
test('contract ending at cycle start is expired, never a partial-rent override', () => {
  assert.deepEqual(cycleEndCoverage('2026-12-02', cycle), { endDate: '2026-12-02', expired: true, shortened: false })
})
test('contract ending before cycle start is expired', () => {
  assert.equal(cycleEndCoverage('2026-11-30', cycle).expired, true)
})
test('contract ending inside the cycle needs confirmed partial rent', () => {
  assert.deepEqual(cycleEndCoverage('2026-12-20', cycle), { endDate: '2026-12-20', expired: false, shortened: true })
})
test('last occupied day and next cycle boundary cover a full cycle', () => {
  assert.equal(cycleEndCoverage('2027-01-01', cycle).shortened, false)
  assert.equal(cycleEndCoverage('2027-01-02', cycle).shortened, false)
  assert.equal(cycleEndCoverage('2027-02-02', cycle).shortened, false)
})
test('no end date and missing cycle never falsely block a contract', () => {
  assert.equal(cycleEndCoverage(null, cycle).expired, false)
  assert.equal(cycleEndCoverage('2026-12-02', null).expired, false)
})
test('handles leap year month boundary as date only', () => {
  const leap = { start: '2028-02-29', end: '2028-03-29' }
  assert.equal(cycleEndCoverage('2028-02-29', leap).expired, true)
  assert.equal(cycleEndCoverage('2028-03-28', leap).shortened, false)
  assert.equal(cycleEndCoverage('2028-03-27', leap).shortened, true)
})
test('renders Vietnamese contract expiry date without timezone conversions', () => {
  assert.equal(formatDay('2026-12-02T00:00:00.000Z'), '02/12/2026')
})
