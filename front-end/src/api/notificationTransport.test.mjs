import assert from 'node:assert/strict'
import test from 'node:test'
import axios from 'axios'
import { installNotificationInterceptors, notifyApiError } from './notificationTransport.js'
import { clearToasts, dismissToast, getToasts, toast } from '../notifications/toastStore.js'

function clientFor(status = 200, body = { success: true, message: 'Đã cập nhật phòng.' }) {
  const client = axios.create({ adapter: async (config) => {
    const response = { status, data: body, config, headers: {}, statusText: '' }
    if (status >= 400) throw new axios.AxiosError('Raw error', 'ERR_BAD_REQUEST', config, null, response)
    return response
  } })
  installNotificationInterceptors(client)
  return client
}

test('writes toast only after server success and ordinary GET requests remain quiet', async () => {
  clearToasts()
  const client = clientFor()
  await client.get('/rooms')
  assert.equal(getToasts().length, 0)
  await client.put('/rooms/1', {})
  assert.equal(getToasts()[0].type, 'success')
  assert.equal(getToasts()[0].message, 'Đã cập nhật phòng.')
})

test('validation failures emit one normalized error even if a component catches and reports again', async () => {
  clearToasts()
  const client = clientFor(422, { message: 'The given data was invalid.', errors: { 'items.13.name': ['Vui lòng nhập items.13.name.'] } })
  await client.post('/contracts/1/handover-check-in').catch((error) => notifyApiError(error))
  assert.equal(getToasts().length, 1)
  assert.equal(getToasts()[0].type, 'error')
  assert.equal(getToasts()[0].message, 'Hạng mục 14: Vui lòng nhập tên thiết bị.')
  assert.equal(getToasts().some((notification) => notification.type === 'success'), false)
})

test('preparatory saves suppress success but keep errors; final confirmation has its own message', async () => {
  clearToasts()
  await clientFor().put('/handover', {}, { toast: { success: false } })
  assert.equal(getToasts().length, 0)
  await clientFor().post('/handover/confirm', {}, { toast: { success: 'Đã xác nhận bàn giao.' } })
  assert.equal(getToasts()[0].message, 'Đã xác nhận bàn giao.')
  clearToasts()
  await clientFor(500, { message: 'SQLSTATE /var/www/app.php' }).put('/handover', {}, { toast: { success: false } }).catch(() => {})
  assert.equal(getToasts()[0].type, 'error')
  assert.doesNotMatch(getToasts()[0].message, /SQLSTATE|app.php/)
})

test('cancelled requests and opted-out internal writes do not notify', async () => {
  clearToasts()
  notifyApiError(new axios.CanceledError())
  await clientFor().post('/internal', {}, { toast: false })
  assert.equal(getToasts().length, 0)
})

test('business failures cannot resolve as successful mutations and conflicts use warning', async () => {
  clearToasts()
  await assert.rejects(clientFor(200, { success: false, message: 'Không thể lưu bàn giao.' }).post('/handover'))
  assert.equal(getToasts()[0].type, 'error')
  clearToasts()
  await assert.rejects(clientFor(409, { message: 'Hành động không thể thực hiện ở trạng thái hiện tại.' }).put('/contract/status'))
  assert.equal(getToasts()[0].type, 'warning')
  clearToasts()
})

test('toast deduplication, dismissal and timing match the notification convention', (context) => {
  clearToasts()
  const scheduled = []
  context.mock.method(globalThis, 'setTimeout', (callback, delay) => { scheduled.push({ callback, delay }); return { unref() {} } })
  context.mock.method(globalThis, 'clearTimeout', () => {})
  toast.success('Đã lưu.'); toast.success('Đã lưu.'); toast.error('Có lỗi.'); toast.warning('Kiểm tra dữ liệu.')
  assert.equal(getToasts().length, 3)
  assert.deepEqual(scheduled.map((timer) => timer.delay), [4000, 7000, 6000])
  dismissToast(getToasts()[0].id)
  assert.equal(getToasts().length, 2)
  scheduled[1].callback()
  assert.equal(getToasts().length, 1)
  clearToasts()
})
