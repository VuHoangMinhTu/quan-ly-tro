import assert from 'node:assert/strict'
import { test } from 'node:test'
import { analyticsParams, periodLabel } from './analyticsAdapter.js'

test('analytics request preserves calendar selection and property scope', () => {
  assert.deepEqual(analyticsParams({ preset: 'week', houseId: '7', compare: true },
    new URLSearchParams('anchor=2024-02-29&group=day')), {
    preset: 'week', boarding_house_id: '7', compare: 1, group_by: 'day', anchor_date: '2024-02-29',
  })
  assert.equal(periodLabel({ from: '2024-02-29', to_exclusive: '2024-03-01' }), '29/02/2024')
})

test('analytics does not fetch invalid date ranges or scopes', () => {
  for (const input of ['start=2025-02-29&end=2025-03-01', 'start=2026-03-01&end=2026-02-01',
    'start=2024-01-01&end=2025-01-03']) {
    assert.equal(analyticsParams({ preset: 'custom' }, new URLSearchParams(input)), null)
  }
  assert.equal(analyticsParams({ preset: 'month', houseId: 'other' }, new URLSearchParams()), null)
})
