import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatLedgerDate, readingError, readingWindowError, replaceLedgerDate, usageStatus } from './utilityLedgerDisplay.mjs'

test('zero consumption is valid and a decreasing or over-precise reading is invalid', () => {
  assert.equal(readingError('900.00', '900'), '')
  assert.match(readingError('899.99', '900'), /nhỏ hơn/)
  assert.match(readingError('900.001', '900'), /thập phân/)
  assert.equal(readingError('', '900'), '')
})

test('ledger statuses distinguish no readings, partial readings, complete readings and stale settlement', () => {
  assert.equal(usageStatus([], 2), 'Chưa ghi')
  assert.equal(usageStatus([{}], 2), 'Ghi một phần')
  assert.equal(usageStatus([{}, {}], 2), 'Đã ghi')
  assert.equal(usageStatus([{ needsReview: true }], 2), 'Cần đối soát')
})

test('date-only formatting and editing preserve the full local timestamp for Billing V2', () => {
  assert.equal(formatLedgerDate('2026-11-15 18:45:30'), '15/11/2026')
  assert.equal(replaceLedgerDate('2026-11-15 18:45:30', '2026-12-02'), '2026-12-02 18:45:30')
  assert.equal(replaceLedgerDate('2026-11-15T18:45', '2026-12-02'), '2026-12-02T18:45')
  assert.equal(readingWindowError('2026-11-15 08:00:00', '2026-11-15T18:45'), '')
  assert.match(readingWindowError('2026-11-15 18:45:30', '2026-11-15T18:45'), /phải sau/)
})
