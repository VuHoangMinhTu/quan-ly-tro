import assert from 'node:assert/strict'
import { test } from 'node:test'
import { filterFromSearch, filterLabel } from './dashboardFilters.js'

test('uses Vietnam-calendar presets with inclusive, valid bounds', () => {
  assert.deepEqual(filterFromSearch('period=last7', '2026-11-14'), { preset: 'last7', start: '2026-11-08', end: '2026-11-14', houseId: '', compare: false })
  assert.deepEqual(filterFromSearch('period=quarter', '2026-11-14').start, '2026-10-01')
  assert.deepEqual(filterFromSearch('period=quarter', '2026-11-14').end, '2026-12-31')
})

test('keeps valid custom periods and rejects a reversed date range', () => {
  const custom = filterFromSearch('period=custom&start=2026-02-01&end=2026-02-28&house=12&compare=1', '2026-11-14')
  assert.equal(custom.houseId, '12')
  assert.equal(custom.compare, true)
  assert.match(filterLabel(custom), /1\/2\/2026.*28\/2\/2026/)
  const invalid = filterFromSearch('period=custom&start=2026-03-02&end=2026-03-01', '2026-11-14')
  assert.deepEqual([invalid.start, invalid.end], ['2026-11-14', '2026-11-14'])
})
