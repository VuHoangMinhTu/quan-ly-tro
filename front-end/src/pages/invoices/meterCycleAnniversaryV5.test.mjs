import test from 'node:test'
import assert from 'node:assert/strict'
import { suggestedMeterUsageWindow, resolveMeterCycleDefault } from './meterCycleDefaults.mjs'

const c = start_date => ({ start_date, status: 'ACTIVE' })
test('Nov 04-Dec 04 from the signed start date', () => {
  assert.deepEqual(suggestedMeterUsageWindow('2026-11', [c('2026-11-04')]), {start:'2026-11-04T00:00',end:'2026-12-04T00:00',ambiguous:false})
})
test('Dec 04-Jan 04 for the next billing month', () => {
  assert.deepEqual(suggestedMeterUsageWindow('2026-12', [c('2026-11-04')]), {start:'2026-12-04T00:00',end:'2027-01-04T00:00',ambiguous:false})
})
test('Feb leap month clamps 31st anniversary', () => {
  assert.deepEqual(suggestedMeterUsageWindow('2028-02', [c('2028-01-31')]), {start:'2028-02-29T00:00',end:'2028-03-31T00:00',ambiguous:false})
})
test('different contract anniversaries are explicitly ambiguous', () => {
  assert.equal(suggestedMeterUsageWindow('2026-11', [c('2026-11-04'),c('2026-11-20')]).ambiguous, true)
})
test('a finalized utility period keeps exact confirmed boundaries', () => {
  const meter={id:7}
  const suggested=suggestedMeterUsageWindow('2026-11',[c('2026-11-04')])
  const data={periods:[{utility_meter_id:7,usage_start_at:'2026-11-02 08:30:00',usage_end_at:'2026-12-02 09:00:00'}]}
  assert.deepEqual(resolveMeterCycleDefault({meter,period:'2026-11',usageWindow:suggested,data}),{start:'2026-11-02T08:30:00',end:'2026-12-02T09:00:00'})
})
