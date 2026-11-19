import assert from 'node:assert/strict'
import test from 'node:test'
import { checkoutMeasurement, contractDebt, formatExitTime } from './contractExitDisplay.mjs'

const opening = { id: 1, utility_meter_id: 7, contract_id: 8, kind: 'CHECK_IN', observed_at: '2026-10-01 08:32:45', reading_value: '100.00', revision: 1, confirmed_by: 4 }
const point = (overrides = {}) => ({ ...opening, id: 2, contract_id: null, kind: 'PERIOD_BOUNDARY', observed_at: '2026-10-10 09:10:00', reading_value: '120.00', ...overrides })

test('Vietnamese display preserves naive Billing V2 wall-clock time and does not mutate timestamp', () => {
  const value = '2026-10-02 08:32:45'
  assert.equal(formatExitTime(value), '02/10/2026 08:32')
  assert.equal(value, '2026-10-02 08:32:45')
  assert.equal(formatExitTime('2026-10-02'), '02/10/2026')
  assert.equal(formatExitTime('2026-10-02T01:32:45.000000Z'), '02/10/2026 08:32')
  assert.equal(formatExitTime('2026-10-02T08:32:45+07:00'), '02/10/2026 08:32')
})

test('consumption uses latest confirmed checkpoint within the contract and before checkout', () => {
  const result = checkoutMeasurement([opening, point(), point({ id: 3, observed_at: '2026-10-20 10:00:00', reading_value: 150 })], 7, 8, '2026-10-15T10:00', '130.25')
  assert.equal(result.previous.id, 2)
  assert.equal(result.usage, 10.25)
})

test('missing contract checkpoint never invents an opening from another contract or initial_reading', () => {
  assert.deepEqual(checkoutMeasurement([point(), { ...opening, contract_id: 9 }], 7, 8, '2026-10-15T10:00', 130), { previous: null, usage: null })
  assert.deepEqual(checkoutMeasurement([{ ...opening, confirmed_by: null }], 7, 8, '2026-10-15T10:00', 130), { previous: null, usage: null })
  assert.deepEqual(checkoutMeasurement([opening, point({ reading_value: null })], 7, 8, '2026-10-15T10:00', 130), { previous: null, usage: null })
})

test('no negative consumption, future opening, or cross-meter evidence; equal readings are valid', () => {
  assert.equal(checkoutMeasurement([opening], 7, 8, '2026-10-15T10:00', 99).usage, null)
  assert.equal(checkoutMeasurement([opening], 7, 8, '2026-10-15T10:00', '').usage, null)
  assert.equal(checkoutMeasurement([opening], 7, 8, '2026-10-15T10:00', '100').usage, 0)
  assert.equal(checkoutMeasurement([opening], 7, 8, '2026-09-15T10:00', 130).previous, null)
  assert.equal(checkoutMeasurement([opening], 99, 8, '2026-10-15T10:00', 130).previous, null)
})

test('debt uses only issued invoices explicitly assigned to this contract, not drafts or other tenants', () => {
  const invoice = { contract_id: 8, total_amount: '1000.25', paid_amount: '200.00', status: 'PARTIALLY_PAID' }
  const debt = contractDebt([invoice, { ...invoice, status: 'DRAFT' }, { ...invoice, status: 'PAID' }, { ...invoice, contract_id: 9 }, { ...invoice, contract_id: null }], 8)
  assert.equal(debt.rows.length, 1)
  assert.equal(debt.total, 800.25)
  assert.equal(debt.drafts, 1)
  assert.equal(debt.unassigned, true)
})

test('incomplete or inconsistent invoice totals remain unknown, never a fabricated zero debt', () => {
  assert.equal(contractDebt([{ contract_id: 8, status: 'UNPAID', total_amount: null, paid_amount: 0 }], 8).total, null)
  assert.equal(contractDebt([{ contract_id: 8, status: 'UNPAID', total_amount: 10, paid_amount: 20 }], 8).total, null)
})
