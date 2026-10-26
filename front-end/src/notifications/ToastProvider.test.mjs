import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { clearToasts, dismissToast, getToasts, subscribeToasts, toast } from './toastStore.js'

const nodes = (node) => Array.isArray(node) ? node.flatMap(nodes) : React.isValidElement(node) ? typeof node.type === 'function' ? nodes(node.type(node.props)) : [node, ...nodes(node.props.children)] : []

test('one top-center portal stays outside scroll containers with accessible live regions and close controls', async () => {
  clearToasts(); toast.success('Đã lưu.'); toast.error('Không thể lưu.')
  const targets = []
  const body = {}
  const bindings = { useSyncExternalStore: () => getToasts(), createPortal: (child, target) => { targets.push(target); return child }, document: { body }, dismissToast, getToasts, subscribeToasts }
  const source = (await readFile(new URL('./ToastProvider.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'ToastProvider.jsx', { jsx: { runtime: 'classic' } })
  const Provider = new Function('React', ...Object.keys(bindings), code + '\nreturn ToastProvider')(React, ...Object.values(bindings))
  const rendered = nodes(Provider({ children: React.createElement('main') }))
  assert.deepEqual(targets, [body])
  const root = rendered.find((node) => node.props['aria-label'] === 'Thông báo')
  assert.match(root.props.className, /fixed.*inset-x-0.*top-0/)
  assert.match(root.props.className, /z-\[2147483647\].*w-full.*max-w-md/)
  assert.match(root.props.className, /pointer-events-none/)
  assert.ok(rendered.some((node) => node.props['aria-live'] === 'polite'))
  assert.ok(rendered.some((node) => node.props['aria-live'] === 'assertive'))
  const close = rendered.filter((node) => node.props['aria-label'] === 'Đóng thông báo')
  assert.equal(close.length, 2)
  assert.match(close[0].props.className, /size-11/)
  close[0].props.onClick()
  assert.equal(getToasts().length, 1)
  clearToasts()
})
