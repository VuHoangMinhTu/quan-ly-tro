import { test } from 'node:test'
import { strict as assert } from 'node:assert'
import { buildMeterPeriodPayload } from './meterPeriodPayload.mjs'

test('điện và nước khác ngày đầu, chung tháng hóa đơn', () => {
  const result = buildMeterPeriodPayload([{ id: 6 }, { id: 5 }], meter => meter.id === 6
    ? { start: '2026-11-03T00:00', end: '2026-12-01T00:00' }
    : { start: '2026-11-04T00:00', end: '2026-12-01T00:00' })
  assert.equal(result.start, '2026-11-03 00:00:00')
  assert.equal(result.end, '2026-12-01 00:00:00')
  assert.deepEqual(result.meterPeriods.map(x => x.usage_start_at), ['2026-11-03 00:00:00', '2026-11-04 00:00:00'])
})
test('ngày cuối độc lập cũng được giữ, envelope là max', () => {
  const result = buildMeterPeriodPayload([{ id: 6 }, { id: 5 }], meter => meter.id === 6
    ? { start: '2026-11-03T00:00', end: '2026-12-02T00:00' }
    : { start: '2026-11-04T00:00', end: '2026-12-01T00:00' })
  assert.equal(result.end, '2026-12-02 00:00:00')
})
test('chặn khoảng âm hoặc bằng 0', () => {
  assert.throws(() => buildMeterPeriodPayload([{ id: 6 }], () => ({start:'2026-11-04T00:00',end:'2026-11-03T00:00'})))
})
