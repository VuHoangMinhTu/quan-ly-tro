import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { getApiErrorMessage } from '../../api/getApiErrorMessage.js'
import { tenantKeys } from '../../api/tenantKeys.js'
import { defaultImportSelection, exportImportErrors, IMPORT_PAGE_SIZE, importStatusLabels, maskedIdentity, selectableImportRow, validateTenantImportFile } from '../../utils/tenantImport.js'

const elements = (node) => Array.isArray(node) ? node.flatMap(elements) : React.isValidElement(node) ? [node, ...elements(node.props.children)] : []
const text = (node) => Array.isArray(node) ? node.map(text).join(' ') : React.isValidElement(node) ? text(node.props.children) : typeof node === 'string' || typeof node === 'number' ? String(node) : ''
const rows = [
  { row: 2, data: { full_name: 'Nguyễn A', phone: '0900000000', identity_number: '001000000001' }, status: 'valid', errors: [], warnings: [] },
  { row: 3, data: { full_name: 'Nguyễn B', phone: '0900000000', identity_number: '001000000002' }, status: 'warning', errors: [], warnings: ['Số điện thoại nghi trùng.'] },
  { row: 4, data: { full_name: 'Nguyễn C' }, status: 'duplicate', errors: [], warnings: ['CCCD đã tồn tại.'] },
  { row: 5, data: { full_name: '' }, status: 'invalid', errors: ['Vui lòng nhập họ tên.'], warnings: [] },
]

async function harness({ confirmImpl, previewRows = rows } = {}) {
  const states = [], refs = [], cleanups = [], calls = { preview: [], confirm: [], cancel: [], invalidate: [], download: 0, blobs: [] }
  let cursor = 0, refCursor = 0
  const bindings = {
    useState: (value) => {
      const index = cursor++
      if (!(index in states)) states[index] = value
      return [states[index], (next) => { states[index] = typeof next === 'function' ? next(states[index]) : next }]
    },
    useRef: (value) => { const index = refCursor++; return refs[index] ||= { current: value } },
    useEffect: (effect) => { if (!cleanups.length) cleanups.push(effect()) },
    useQueryClient: () => ({ invalidateQueries: async (query) => calls.invalidate.push(query) }),
    previewTenantImport: async (file) => {
      calls.preview.push(file)
      return { data: { data: { token: 'abc', rows: previewRows, summary: { total: previewRows.length, valid: 1, warning: 1, duplicate: 1, invalid: 1 } } } }
    },
    confirmTenantImport: async (payload) => { calls.confirm.push(payload); return confirmImpl ? confirmImpl(payload) : { data: { message: 'Đã thêm thành công 1 người thuê.' } } },
    cancelTenantImport: async (token) => calls.cancel.push(token),
    downloadTenantTemplate: async () => { calls.download++; return { data: new Blob(['xlsx']) } },
    document: { activeElement: null, createElement: () => ({ click() {} }) },
    URL: { createObjectURL: (blob) => { calls.blobs.push(blob); return 'blob:test' }, revokeObjectURL() {} },
    setTimeout: (fn) => { fn(); return 1 },
    tenantKeys, getApiErrorMessage, defaultImportSelection, exportImportErrors, IMPORT_PAGE_SIZE, importStatusLabels, maskedIdentity, selectableImportRow, validateTenantImportFile,
  }
  const source = (await readFile(new URL('./TenantImportModal.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'TenantImportModal.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), code + '\nreturn TenantImportModal')(React, ...Object.values(bindings))
  let closed = false
  const render = () => { cursor = 0; refCursor = 0; return elements(Component({ onClose: () => { closed = true } })) }
  const button = (label) => render().find((node) => node.type === 'button' && text(node).includes(label))
  const selectFile = () => render().find((node) => node.props.type === 'file').props.onChange({ target: { files: [{ name: 'tenants.xlsx', size: 1024 }] } })
  const upload = async () => { selectFile(); await button('Upload').props.onClick(); await new Promise((resolve) => globalThis.setTimeout(resolve, 0)) }
  return { calls, render, button, selectFile, upload, cleanup: () => cleanups.forEach((cleanup) => cleanup?.()), closed: () => closed }
}

test('file picker rejects the wrong extension, empty file and files above 5 MB', () => {
  assert.match(validateTenantImportFile({ name: 'bad.xls', size: 10 }), /\.xlsx/)
  assert.match(validateTenantImportFile({ name: 'big.xlsx', size: 5 * 1024 * 1024 + 1 }), /5 MB/)
  assert.match(validateTenantImportFile({ name: 'empty.xlsx', size: 0 }), /rỗng/)
  assert.equal(validateTenantImportFile({ name: 'good.XLSX', size: 1024 }), '')
  assert.deepEqual(defaultImportSelection(rows), [2])
  assert.equal(maskedIdentity('001000000001'), '••••0001')
  const csv = exportImportErrors(rows)
  assert.match(csv, /CCCD đã tồn tại/)
  assert.doesNotMatch(csv, /001000000001|0900000000|Nguyễn/)
})

test('template download and upload show row-specific errors and duplicate warnings', async () => {
  const ui = await harness()
  await ui.button('Tải file Excel mẫu').props.onClick()
  assert.equal(ui.calls.download, 1)
  assert.equal(ui.calls.blobs.length, 1)
  await ui.upload()
  assert.equal(ui.calls.preview.length, 1)
  assert.match(ui.render().map(text).join(' '), /Vui lòng nhập họ tên/)
  const boxes = ui.render().filter((node) => node.props.type === 'checkbox')
  assert.equal(boxes[0].props.checked, true)
  assert.equal(boxes[1].props.checked, false)
  assert.equal(boxes[2].props.disabled, true)
  assert.equal(boxes[3].props.disabled, true)
  assert.match(ui.render().find((node) => node.props.role === 'dialog').props.className, /max-h-\[90dvh\].*min-w-0.*overflow-y-auto/)
  ui.cleanup()
  assert.deepEqual(ui.calls.cancel, ['abc'])
})

test('phone-warning selection requires acknowledgment and confirm invalidates the tenant list', async () => {
  const ui = await harness()
  await ui.upload()
  ui.render().filter((node) => node.props.type === 'checkbox')[1].props.onChange()
  assert.equal(ui.button('Xác nhận nhập').props.disabled, true)
  ui.render().find((node) => node.type === 'label' && text(node).includes('Tôi đã kiểm tra')).props.children[0].props.onChange({ target: { checked: true } })
  await ui.button('Xác nhận nhập').props.onClick()
  assert.deepEqual(ui.calls.confirm, [{ token: 'abc', selected_rows: [2, 3], accept_phone_warnings: true }])
  assert.deepEqual(ui.calls.invalidate, [{ queryKey: tenantKeys.all }])
  assert.match(ui.render().map(text).join(' '), /Đã thêm thành công 1 người thuê/)
  ui.cleanup()
  assert.deepEqual(ui.calls.cancel, [])
})

test('pending confirm blocks a second request and leaves retry available after a failure', async () => {
  let reject
  const ui = await harness({ confirmImpl: () => new Promise((resolve, fail) => { reject = fail }) })
  await ui.upload()
  const confirm = ui.button('Xác nhận nhập').props.onClick
  const pending = confirm()
  confirm()
  assert.equal(ui.calls.confirm.length, 1)
  assert.equal(ui.button('Đang nhập').props.disabled, true)
  reject({ response: { status: 422, data: { message: 'Phiên import đã hết hạn.' } } })
  await pending
  assert.match(ui.render().find((node) => node.props.role === 'alert').props.children, /hết hạn/)
  assert.equal(ui.button('Xác nhận nhập').props.disabled, false)
})

test('long previews paginate twenty rows at a time', async () => {
  const ui = await harness({ previewRows: Array.from({ length: 45 }, (_, index) => ({ ...rows[0], row: index + 2 })) })
  await ui.upload()
  assert.equal(ui.render().filter((node) => node.props.type === 'checkbox').length, 20)
  ui.button('Sau').props.onClick()
  ui.button('Sau').props.onClick()
  assert.equal(ui.render().filter((node) => node.props.type === 'checkbox').length, 5)
})
