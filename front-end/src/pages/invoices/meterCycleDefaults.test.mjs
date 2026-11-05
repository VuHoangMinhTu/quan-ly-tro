import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveMeterCycleDefault } from './meterCycleDefaults.mjs'
const meter = { id: 6 }
const usageWindow = {start:'2026-11-01T00:00',end:'2026-12-01T00:00'}
const cp = (id, value, when) => ({ id, utility_meter_id:6, kind:'PERIOD_BOUNDARY',reading_value:value,observed_at:when })
const get = data => resolveMeterCycleDefault({ meter, period:'2026-11', usageWindow, data })
test('electric November gets Nov 04 to Dec 01 (not default Nov 01)', () => {
  assert.deepEqual(get({checkpoints:[cp(1,0,'2026-11-04 00:00:00'),cp(2,10,'2026-12-01 00:00:00')]}), {start:'2026-11-04T00:00:00',end:'2026-12-01T00:00:00'})
})
test('November never uses Jan 01 boundary when December close is missing', () => {
  assert.deepEqual(get({checkpoints:[cp(1,0,'2026-11-04 00:00:00'),cp(3,12,'2027-01-01 00:00:00')]}), {start:'2026-11-04T00:00:00',end:'2026-12-01T00:00'})
})
test('multiple November points and corrected pair prefers explicit corrected pair', () => {
  const checkpoints = [cp(1,0,'2026-11-01 00:00:00'),cp(2,10,'2026-12-01 00:00:00'),cp(3,0,'2026-11-04 00:00:00'),cp(4,10,'2026-12-01 00:00:00')]
  const corrections=[{id:9,utility_meter_id:6,old_start_checkpoint_id:1,old_end_checkpoint_id:2,new_start_checkpoint_id:3,new_end_checkpoint_id:4}]
  assert.deepEqual(get({checkpoints,checkpoint_corrections:corrections}), {start:'2026-11-04T00:00:00',end:'2026-12-01T00:00:00'})
})
test('December selects Dec01 to Jan01', () => {
  const selected = resolveMeterCycleDefault({meter,period:'2026-12',usageWindow:{start:'2026-12-01T00:00',end:'2027-01-01T00:00'},data:{checkpoints:[cp(1,0,'2026-11-04 00:00:00'),cp(2,10,'2026-12-01 00:00:00'),cp(3,15,'2027-01-01 00:00:00')]}})
  assert.deepEqual(selected,{start:'2026-12-01T00:00:00',end:'2027-01-01T00:00:00'})
})
test('a billed cycle retains exact billed window', () => {
  const data={checkpoints:[cp(1,0,'2026-11-04 00:00:00'),cp(2,10,'2026-12-01 00:00:00')],periods:[{utility_meter_id:6,start_checkpoint_id:1,end_checkpoint_id:2}]}
  assert.deepEqual(get(data),{start:'2026-11-04T00:00:00',end:'2026-12-01T00:00:00'})
})
test('independent meters use their own boundaries', () => {
  const data={checkpoints:[cp(1,0,'2026-11-04 00:00:00'),cp(2,10,'2026-12-01 00:00:00'),{id:3,utility_meter_id:5,kind:'PERIOD_BOUNDARY',observed_at:'2026-11-05 00:00:00'},{id:4,utility_meter_id:5,kind:'PERIOD_BOUNDARY',observed_at:'2026-12-02 00:00:00'}]}
  assert.equal(get(data).start,'2026-11-04T00:00:00')
})
