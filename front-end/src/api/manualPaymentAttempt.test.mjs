import assert from 'node:assert/strict'
import test from 'node:test'
import { createManualPaymentAttempt } from './manualPaymentAttempt.mjs'

test('double click joins one request and timeout retry reuses exact original payload', async () => {
  let keys = 0
  const attempt = createManualPaymentAttempt(() => 'key-' + ++keys)
  const sent = []
  const send = async payload => {
    sent.push(payload)
    if (sent.length === 1) throw Object.assign(new Error('timeout'), { code: 'ECONNABORTED' })
    return { status: 201 }
  }
  const first = attempt.submit({ amount: 100, paid_at: '2026-10-10T08:00' }, send)
  assert.equal(attempt.submit({ amount: 200 }, send), first)
  await assert.rejects(first, /timeout/)
  assert.equal(attempt.uncertain, true)
  await attempt.submit(undefined, send)
  assert.equal(sent[0], sent[1])
  assert.equal(attempt.uncertain, false)
  await attempt.submit({ amount: 100 }, send)
  assert.equal(sent[2].request_key, 'key-2')
})

test('a definite first rejection allows correction, but rejection of a retry does not resolve earlier uncertainty', async () => {
  let keys = 0
  const attempt = createManualPaymentAttempt(() => 'key-' + ++keys)
  const rejectWith = status => async () => { throw { response: { status } } }
  await assert.rejects(attempt.submit({ amount: 100 }, rejectWith(422)))
  assert.equal(attempt.uncertain, false)
  await assert.rejects(attempt.submit({ amount: 200 }, rejectWith(502)))
  await assert.rejects(attempt.submit(undefined, rejectWith(401)))
  assert.equal(attempt.uncertain, true)
  await attempt.submit(undefined, async payload => assert.deepEqual(payload, { amount: 200, request_key: 'key-2' }))
})
