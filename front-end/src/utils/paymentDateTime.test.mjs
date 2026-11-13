import test from 'node:test'
import assert from 'node:assert/strict'
import { paymentDateTime } from './paymentDateTime.js'

test('payment defaults use Vietnam time across the UTC date boundary', () => {
  assert.equal(paymentDateTime(new Date('2026-11-07T23:50:00Z')), '2026-11-08T06:50')
})

test('editing a serialized timestamp restores Vietnam wall time without slicing off its offset', () => {
  assert.equal(paymentDateTime('2026-11-08T03:00:00.000000Z'), '2026-11-08T10:00')
  assert.equal(paymentDateTime('2026-11-08T10:00:00+07:00'), '2026-11-08T10:00')
})

test('existing naive API wall times retain their original date and time', () => {
  assert.equal(paymentDateTime('2026-11-08 10:00:00'), '2026-11-08T10:00')
  assert.equal(paymentDateTime('2026-11-08T10:00'), '2026-11-08T10:00')
})
