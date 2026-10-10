import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = (await readFile(new URL('../../utils/utilityReading.js', import.meta.url), 'utf8'))
  .replace(/^import .*$/gm, '')
  .replaceAll('export function ', 'function ')

const { formatMeterPrice, getReadingPreview } = new Function(
  'formatDateForApi',
  'formatDateForDisplay',
  'formatCurrency',
  `${source}\nreturn { formatMeterPrice, getReadingPreview }`,
)(
  (value) => {
    const parts = String(value).split('/')
    return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : null
  },
  (value) => {
    const [year, month, day] = String(value).split('T')[0].split('-')
    return year && month && day ? `${day}/${month}/${year}` : ''
  },
  (value) => `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value))} đ`,
)

test('reading preview uses the initial reading when no earlier reading exists', () => {
  const preview = getReadingPreview([], '0.00', '10/12/2026', '20')

  assert.deepEqual(preview, { baseline: 0, consumption: 20 })
})

test('reading preview uses the closest prior reading by date', () => {
  const readings = [
    { reading_date: '2026-11-09', reading_value: '20.00' },
    { reading_date: '2026-12-15', reading_value: '40.00' },
  ]

  const preview = getReadingPreview(readings, '0.00', '10/12/2026', '35')

  assert.deepEqual(preview, { baseline: 20, consumption: 15 })
})

test('reading preview does not pretend an invalid date or value is valid', () => {
  assert.equal(getReadingPreview([], 0, '', '20'), null)
  assert.equal(getReadingPreview([], 0, '10/12/2026', ''), null)
})

test('meter pricing shows a unit price only for per-unit services', () => {
  assert.equal(formatMeterPrice({ billing_method: 'PER_UNIT', base_price: '3500.00', unit: 'kWh' }), '3.500 đ / kWh')
  assert.equal(formatMeterPrice({ billing_method: 'TIERED', base_price: null, unit: 'm³' }), 'Theo bảng giá bậc thang')
})
