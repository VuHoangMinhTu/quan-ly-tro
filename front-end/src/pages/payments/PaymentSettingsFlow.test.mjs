import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { File } from 'node:buffer'
import React from 'react'
import { transformWithOxc } from 'vite'

function nodes(node) {
  if (Array.isArray(node)) return node.flatMap(nodes)
  if (!React.isValidElement(node)) return []
  return [node, ...nodes(node.props.children)]
}

async function harness() {
  const states = []
  let cursor = 0
  const requests = []
  const bank = { bank_name: 'Vietcombank', bank_account_name: 'Chủ tài khoản', bank_account_number: '00123', has_payment_qr: true, payment_qr_url: '/landlord/payment-profile/qr', payment_qr_version: 'old' }
  const bindings = {
    useState: initial => {
      const index = cursor++
      if (!(index in states)) states[index] = initial
      return [states[index], value => { states[index] = value }]
    },
    useQueryClient: () => ({ getQueryData: () => ({ data: { data: { landlord: { id: 5 } } } }), setQueryData: () => {}, invalidateQueries: () => {} }),
    useQuery: ({ queryKey }) => ({ data: { data: { data: queryKey.at(-1) === 'payment-profile' ? bank : { configured: true } } } }),
    useMutation: options => ({ mutate: value => options.mutationFn(value), isPending: false }),
    savePaymentProfile: value => requests.push(value), getPaymentProfile: () => {}, getPayosConfig: () => {}, savePayosConfig: () => {}, disconnectPayos: () => {}, getApiErrorMessage: () => '',
    PrivatePaymentQr: () => null, LocalQrImage: () => null,
  }
  const source = (await readFile(new URL('./PaymentSettingsPage.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'PaymentSettingsPage.jsx', { jsx: { runtime: 'classic' } })
  const Page = new Function('React', ...Object.keys(bindings), `${code}\nreturn PaymentSettingsPage`)(React, ...Object.values(bindings))
  const render = () => { cursor = 0; return nodes(Page()) }
  return { render, requests, submit: () => render().find(node => node.type === 'form').props.onSubmit({ preventDefault() {} }) }
}

test('bank QR upload and deletion send multipart data while preserving account number text', async () => {
  const page = await harness()
  const file = new File(['fake-image-for-ui-test'], 'qr.png', { type: 'image/png' })
  page.render().find(node => node.type === 'input' && node.props.type === 'file').props.onChange({ target: { files: [file], value: 'qr.png' } })
  page.submit()
  assert.equal(page.requests[0].get('payment_qr').name, 'qr.png')
  assert.equal(page.requests[0].get('bank_account_number'), '00123')
  assert.equal(page.requests[0].has('landlord_id'), false)
  page.render().find(node => node.type === 'button' && node.props.children === 'Xóa QR').props.onClick()
  page.submit()
  assert.equal(page.requests[1].get('remove_payment_qr'), '1')
  assert.equal(page.requests[1].has('payment_qr'), false)
})

test('invalid local image selection prevents uploading it', async () => {
  const page = await harness()
  const file = new File(['invalid'], 'fake.pdf', { type: 'application/pdf' })
  page.render().find(node => node.type === 'input' && node.props.type === 'file').props.onChange({ target: { files: [file], value: 'fake.pdf' } })
  page.submit()
  assert.equal(page.requests.length, 0)
  assert.ok(page.render().find(node => node.props.role === 'alert'))
})

test('multipart transport uses POST with PUT override so PHP receives uploaded files', async () => {
  const calls = []
  const source = (await readFile(new URL('../../api/paymentSettingsApi.js', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replaceAll('export const ', 'const ')
  const save = new Function('client', `${source}\nreturn savePaymentProfile`)({ post: (...args) => calls.push(args), put: (...args) => calls.push(args) })
  const data = new FormData()
  data.set('bank_account_number', '00123')
  save(data)
  assert.equal(data.get('_method'), 'PUT')
  assert.equal(calls[0][0], '/landlord/payment-profile')
  assert.equal(calls[0][2].headers['Content-Type'], undefined)
})
