import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import axios from 'axios'
import { transformWithOxc } from 'vite'
import { installNotificationInterceptors, notifyApiError } from '../../api/notificationTransport.js'
import { getApiErrorMessage } from '../../api/getApiErrorMessage.js'
import { clearToasts, getToasts } from '../../notifications/toastStore.js'

const nodes = (node) => Array.isArray(node) ? node.flatMap(nodes) : React.isValidElement(node) ? [node, ...nodes(node.props.children)] : []
const text = (node) => Array.isArray(node) ? node.map(text).join('') : React.isValidElement(node) ? text(node.props.children) : typeof node === 'string' ? node : ''

async function harness({ failSave = false, failConfirm = false, contractType = 'DORM_BED', contractStatus = 'DRAFT', reportStatus = 'DRAFT', initialItems = [{ name: 'Giường', quantity: 1, condition: 'GOOD' }], confirmDelete = true } = {}) {
  const calls = [], states = [], refs = [], mutations = []
  const report = { status: reportStatus, items: initialItems }
  let cursor = 0, refCursor = 0, mutationCursor = 0
  const transport = axios.create({ adapter: async (config) => {
    calls.push(config)
    const failure = (config.method === 'put' && failSave) || (config.url.endsWith('/confirm') && failConfirm)
    const response = { config, status: failure ? 422 : 200, data: failure ? { errors: { 'items.13.name': ['Vui lòng nhập items.13.name.'] } } : { success: true, message: 'Thành công.' } }
    if (failure) throw new axios.AxiosError('Bad request', 'ERR_BAD_REQUEST', config, null, response)
    if (config.method === 'put') report.items = JSON.parse(config.data).items
    if (config.url.endsWith('/confirm')) report.status = 'CONFIRMED'
    return response
  } })
  installNotificationInterceptors(transport)
  const bindings = {
    useState: (initial) => { const index = cursor++; if (!(index in states)) states[index] = initial; return [states[index], (value) => { states[index] = typeof value === 'function' ? value(states[index]) : value }] },
    useRef: (initial) => { const index = refCursor++; return refs[index] ||= { current: initial } },
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
    useQuery: () => ({ data: { data: { data: report } } }),
    useMutation: (options) => {
      const index = mutationCursor++
      const mutation = mutations[index] ||= { isPending: false }
      mutation.mutate = () => {
        mutation.isPending = true
        mutation.promise = (async () => {
          try { await options.onSuccess?.(await options.mutationFn()) } catch (error) { options.onError?.(error) }
          finally { mutation.isPending = false; options.onSettled?.() }
        })()
      }
      return mutation
    },
    contractKeys: { handoverCheckIn: (id) => ['handover', id], detail: (id) => ['contract', id] },
    contractDocumentKeys: { preview: (id) => ['preview', id] },
    dormHandoverItems: [], getApiErrorMessage, notifyApiError,
    createHandoverCheckIn: (id) => transport.post(`/contracts/${id}/handover-check-in`),
    updateHandoverCheckIn: (id, items, config = {}) => transport.put(`/contracts/${id}/handover-check-in`, { items }, { toast: { success: 'Đã lưu nháp bàn giao.', ...config.toast } }),
    confirmHandoverCheckIn: (id) => transport.post(`/contracts/${id}/handover-check-in/confirm`, {}, { toast: { success: 'Đã xác nhận bàn giao.' } }),
    window: { confirm: () => confirmDelete },
  }
  const source = (await readFile(new URL('./ContractHandoverSection.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'ContractHandoverSection.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), code + '\nreturn ContractHandoverSection')(React, ...Object.values(bindings))
  const render = () => { cursor = 0; refCursor = 0; mutationCursor = 0; return nodes(Component({ contract: { id: 42, contract_type: contractType, status: contractStatus } })) }
  const button = (label) => render().find((node) => node.type === 'button' && text(node) === label)
  const drain = () => Promise.all(mutations.map((mutation) => mutation.promise))
  return { calls, render, button, drain }
}

test('default room items can be removed with confirmation and remain removed after saving and refetch', async () => {
  const page = await harness({ contractType: 'ROOM_RENTAL', initialItems: ['Tivi', 'Khóa cửa', 'Máy lạnh'].map(name => ({ name, quantity: 1 })) })
  assert.equal(page.render().filter(node => node.type === 'button' && text(node) === 'Xóa').length, 3)
  page.button('Xóa').props.onClick()
  assert.equal(page.calls.length, 0, 'Removing a row is a local edit until Save Draft or Confirm')
  assert.doesNotMatch(page.render().map(text).join(''), /1\. Tivi/)
  page.button('Lưu nháp').props.onClick()
  await page.drain()
  assert.deepEqual(JSON.parse(page.calls[0].data).items.map(item => item.name), ['Khóa cửa', 'Máy lạnh'])
  assert.equal(page.render().filter(node => node.type === 'button' && text(node) === 'Xóa').length, 2)
  assert.doesNotMatch(page.render().map(text).join(''), /Tivi|Ballet, nệm/)
})

test('cancel keeps the item and deletion is unavailable after confirmation or contract finalization', async () => {
  const cancelled = await harness({ confirmDelete: false })
  cancelled.button('Xóa').props.onClick()
  assert.equal(cancelled.render().filter(node => node.type === 'button' && text(node) === 'Xóa').length, 1)
  for (const options of [{ contractStatus: 'ACTIVE' }, { reportStatus: 'CONFIRMED' }]) {
    const page = await harness(options)
    assert.equal(page.button('Xóa'), undefined)
  }
})

test('removing the final item can save an empty list without restoring default rows', async () => {
  const page = await harness({ contractType: 'ROOM_RENTAL', initialItems: [{ name: 'Tivi', quantity: 1 }] })
  page.button('Xóa').props.onClick()
  assert.equal(page.button('Lưu nháp').props.disabled, false)
  page.button('Lưu nháp').props.onClick()
  await page.drain()
  assert.deepEqual(JSON.parse(page.calls[0].data).items, [])
  assert.equal(page.button('Xóa'), undefined)
})

test('save draft only saves and has its own loading state and success toast', async () => {
  clearToasts()
  const page = await harness()
  page.button('Lưu nháp').props.onClick()
  assert.ok(page.button('Đang lưu nháp...'))
  assert.equal(page.button('Xác nhận bàn giao').props.disabled, true)
  await page.drain()
  assert.deepEqual(page.calls.map((call) => call.method), ['put'])
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].message, 'Đã lưu nháp bàn giao.')
})

test('confirm first saves silently then reports one final success and independent loading state', async () => {
  clearToasts()
  const page = await harness()
  page.button('Xác nhận bàn giao').props.onClick()
  assert.ok(page.button('Đang xác nhận...'))
  await page.drain()
  assert.deepEqual(page.calls.map((call) => call.method), ['put', 'post'])
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].message, 'Đã xác nhận bàn giao.')
})

test('save failure never confirms and produces one normalized error despite the component catch', async () => {
  for (const action of ['Lưu nháp', 'Xác nhận bàn giao']) {
    clearToasts()
    const page = await harness({ failSave: true })
    page.button(action).props.onClick()
    await page.drain()
    assert.equal(page.calls.length, 1)
    assert.equal(getToasts().length, 1)
    assert.equal(getToasts()[0].type, 'error')
    assert.equal(getToasts()[0].message, 'Hạng mục 14: Vui lòng nhập tên thiết bị.')
  }
})

test('final confirmation failure never emits an intermediate saved or confirmed success', async () => {
  clearToasts()
  const page = await harness({ failConfirm: true })
  page.button('Xác nhận bàn giao').props.onClick()
  await page.drain()
  assert.equal(page.calls.length, 2)
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'error')
})
