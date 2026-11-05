import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test, { afterEach } from 'node:test'
import axios from 'axios'
import { installNotificationInterceptors, notifyApiError } from './notificationTransport.js'
import { clearToasts, getToasts, toast } from '../notifications/toastStore.js'

afterEach(clearToasts)

async function api(adapter) {
  const client = axios.create({ adapter })
  installNotificationInterceptors(client)
  const source = (await readFile(new URL('./billingV2Api.js', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replaceAll('export const ', 'const ')
  return new Function('client', 'axios', 'notifyApiError', 'toast', source + ';return {generateBillingV2,recordBillingDeposit,generateMonthlyInvoices,previewMonthlyDraft,confirmMonthlyDraft};')(client, axios, notifyApiError, toast)
}

test('lost response reports uncertain result once and does not retry automatically', async () => {
  let calls = 0
  const billing = await api(async config => { calls++; throw new axios.AxiosError('Network Error', 'ERR_NETWORK', config) })

  await assert.rejects(billing.generateBillingV2(1, '2026-10-01', '2026-10-02 08:00:00', '2026-11-02 08:00:00'), error => /Chưa xác định/.test(error.billingV2Message))

  assert.equal(calls, 1)
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'warning')
  assert.doesNotMatch(getToasts()[0].message, /thất bại/)
})

test('confirmed deposit success emits one toast and preserves caller request key', async () => {
  const sent = []
  const billing = await api(async config => { sent.push(JSON.parse(config.data)); return { status: 200, data: { success: true, message: 'Đã ghi nhận cọc.' }, config } })
  const payload = { event: 'RECEIVED', amount: 1000, request_key: 'same-receipt', occurred_at: '2026-10-01 00:00:00' }

  await billing.recordBillingDeposit(1, payload)

  assert.deepEqual(sent, [payload])
  assert.equal(payload.request_key, 'same-receipt')
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'success')
})

test('422 tariff conflict remains actionable and does not become an uncertain success', async () => {
  const billing = await api(async config => { throw new axios.AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, { status: 422, data: { message: 'Các hợp đồng có bảng giá khác nhau.' }, config }) })

  await assert.rejects(billing.generateBillingV2(1, '2026-10-01', '2026-10-02 08:00:00', '2026-11-02 08:00:00'), error => error.response.status === 422 && !error.billingV2Message)

  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'error')
  assert.equal(getToasts()[0].message, 'Các hợp đồng có bảng giá khác nhau.')
})

test('session cancellation produces no stale billing notification', async () => {
  const billing = await api(async config => { throw new axios.CanceledError('Session ended', config) })

  await assert.rejects(billing.generateBillingV2(1, '2026-10-01', '2026-10-02 08:00:00', '2026-11-02 08:00:00'), axios.isCancel)

  assert.deepEqual(getToasts(), [])
})

test('HTTP 200 batch with blocked room warns instead of declaring every room successful', async () => {
  const billing = await api(async config => ({ status: 200, data: { success: true, data: [{ status: 'CREATED' }, { status: 'BLOCKED' }] }, config }))
  const result = await billing.generateMonthlyInvoices(1, { billing_period: '2026-10-01' })
  assert.equal(result.data.data[1].status, 'BLOCKED')
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'warning')
})

test('pending preview reports warning and never sends confirm automatically', async () => {
  const calls = []
  const billing = await api(async config => { calls.push(config.url); return { status: 200, data: { success: true, data: { status: 'PENDING_FINAL_SETTLEMENT' } }, config } })
  await billing.previewMonthlyDraft(1, { billing_period: '2026-10-01' })
  assert.deepEqual(calls, ['/billing-v2/rooms/1/monthly-recalculations/preview'])
  assert.equal(getToasts()[0].type, 'warning')
})

test('explicit draft confirmation retains preview token and reason', async () => {
  const calls = []
  const billing = await api(async config => { calls.push({ url: config.url, data: JSON.parse(config.data) }); return { status: 200, data: { success: true, data: { status: 'UPDATED' } }, config } })
  await billing.confirmMonthlyDraft(1, 'same-preview-token', 'Đối soát chỉ số công tơ')
  assert.deepEqual(calls, [{ url: '/billing-v2/rooms/1/monthly-recalculations/same-preview-token/confirm', data: { confirmed: true, reason: 'Đối soát chỉ số công tơ' } }])
  assert.equal(getToasts()[0].type, 'success')
})

test('utility generation sends invoice month separately from selected usage timestamps', async () => {
  const sent = []
  const billing = await api(async config => { sent.push({ url: config.url, data: JSON.parse(config.data) }); return { status: 200, data: { success: true, data: { meters: [] } }, config } })
  await billing.generateBillingV2(7, '2026-12-01', '2026-11-02 08:30:00', '2026-12-02 09:00:00')
  assert.deepEqual(sent, [{ url: '/billing-v2/rooms/7/generate', data: { billing_period: '2026-12-01', usage_start_at: '2026-11-02 08:30:00', usage_end_at: '2026-12-02 09:00:00' } }])
})
