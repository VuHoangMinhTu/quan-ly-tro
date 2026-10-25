import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'

test('money input preserves a negative sign for validation instead of changing the amount', async () => {
  const formatters = (await readFile(new URL('../../utils/formatters.js', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replaceAll('export ', '')
  const { formatVnd, parseVnd } = new Function(`${formatters}\nreturn {formatVnd, parseVnd}`)()
  const source = (await readFile(new URL('./MoneyInput.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'MoneyInput.jsx', { jsx: { runtime: 'classic' } })
  const MoneyInput = new Function('React', 'formatVnd', 'parseVnd', `${code}\nreturn MoneyInput`)(React, formatVnd, parseVnd)
  let value
  const input = MoneyInput({ value: '', onChange: next => { value = next } })
  for (const [raw, expected] of [['-100', -100], ['-1.000.000', -1000000], ['3.800.000', 3800000], ['0', 0], ['', '']]) {
    input.props.onChange({ target: { value: raw } })
    assert.equal(value, expected)
  }
})
