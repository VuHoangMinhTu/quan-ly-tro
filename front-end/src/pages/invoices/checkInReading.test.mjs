import test from 'node:test'
import assert from 'node:assert/strict'
import { inspectCheckInReading } from './checkInReading.mjs'
const points = [{id:1,utility_meter_id:6,kind:'PERIOD_BOUNDARY',observed_at:'2026-11-04 00:00:00',reading_value:'120'}]
test('120 and 125 at identical timestamp must block', () => { const r=inspectCheckInReading(points,6,'2026-11-04T00:00','125');assert.equal(r.allowed,false);assert.equal(r.reason,'SAME_TIME_CONFLICT') })
test('corrected 125 same timestamp can confirm', () => { const r=inspectCheckInReading([{...points[0],reading_value:'125'}],6,'2026-11-04T00:00','125');assert.equal(r.allowed,true) })
test('earlier boundary 120 then real 125 later is independent consumption', () => { const r=inspectCheckInReading(points,6,'2026-11-05T00:00','125');assert.equal(r.allowed,true) })
test('decreasing reading is blocked', () => { assert.equal(inspectCheckInReading(points,6,'2026-11-05T00:00','119').reason,'DECREASE') })
test('missing value does not treat blank as zero', () => { assert.equal(inspectCheckInReading(points,6,'2026-11-05T00:00','').allowed,false) })
