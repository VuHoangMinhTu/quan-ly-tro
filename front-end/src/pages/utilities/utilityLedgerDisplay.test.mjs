import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatTime, readingError, usageStatus } from './utilityLedgerDisplay.mjs'

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

test('formatting keeps the business-local date and 24 hour time', () => {
  assert.equal(formatTime('2026-11-15 18:45:30'), '15/11/2026 18:45')
})
