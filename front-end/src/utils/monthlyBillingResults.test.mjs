import test from 'node:test'
import assert from 'node:assert/strict'
import { summarizeMonthlyResults } from './monthlyBillingResults.js'

test('HTTP-success batch still separates created, existing, pending and blocked rooms', () => {
  const counts = summarizeMonthlyResults(['CREATED', 'EXISTING', 'PENDING_FINAL_SETTLEMENT', 'BLOCKED', 'BLOCKED', 'NO_ELIGIBLE_CONTRACT', 'NO_NEW_RECEIVABLES'].map(status => ({ status })))
  assert.deepEqual(counts, { CREATED: 1, EXISTING: 1, PENDING_FINAL_SETTLEMENT: 1, BLOCKED: 2, NO_ELIGIBLE_CONTRACT: 1, NO_NEW_RECEIVABLES: 1 })
})
