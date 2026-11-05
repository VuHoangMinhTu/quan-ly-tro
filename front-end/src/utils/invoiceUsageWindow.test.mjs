import test from 'node:test'
import assert from 'node:assert/strict'
import { invoiceUsageWindow } from './invoiceUsageWindow.js'

for (const contractType of ['ROOM_RENTAL', 'DORM_BED']) {
  test(`${contractType}: editing a draft reuses the exact saved 04/11 to 04/12 window`, () => {
    const snapshot = { contract_type: contractType, usage_window: { start: '2026-11-04 00:00:00', end: '2026-12-04 00:00:00' }, usage_periods: [] }
    assert.deepEqual(invoiceUsageWindow(snapshot), { start: '2026-11-04 00:00:00', end: '2026-12-04 00:00:00', hasSavedWindow: true })
  })
}
test('room meter periods are supported for legacy invoice snapshots', () => {
  assert.deepEqual(invoiceUsageWindow({ usage_periods: [{ usage_start_at: '2026-11-02 08:30:00', usage_end_at: '2026-12-02 09:00:00' }] }), { start: '2026-11-02 08:30:00', end: '2026-12-02 09:00:00', hasSavedWindow: true })
})
test('saved rental window takes precedence over a different meter period', () => {
  assert.equal(invoiceUsageWindow({ usage_window: { start: '2026-11-04 00:00:00', end: '2026-12-04 00:00:00' }, usage_periods: [{ usage_start_at: '2026-11-02 00:00:00', usage_end_at: '2026-12-02 00:00:00' }] }).start, '2026-11-04 00:00:00')
})
test('missing saved window must not silently default to first of month', () => {
  assert.deepEqual(invoiceUsageWindow({ usage_periods: [] }), { start: null, end: null, hasSavedWindow: false })
})
