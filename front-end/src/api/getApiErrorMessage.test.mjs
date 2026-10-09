import assert from 'node:assert/strict'
import test from 'node:test'
import { applyApiFieldErrors, getApiErrorMessage, getApiFieldErrors } from './getApiErrorMessage.js'

const apiError = (status, data) => ({ response: { status, data } })

test('422 uses concrete validation errors instead of the Laravel generic message', () => {
  const message = 'Mã hợp đồng này đã được sử dụng. Vui lòng chọn mã khác.'
  const error = apiError(422, { message: 'The given data was invalid.', errors: { contract_code: [message] } })
  assert.equal(getApiErrorMessage(error), message)
  assert.deepEqual(getApiFieldErrors(error), { contract_code: message })
})

test('field adapter maps each field without attaching general errors to password', () => {
  const applied = []
  const error = apiError(422, { message: 'The given data was invalid.', errors: { email: ['Email đã tồn tại.'], phone: ['Số điện thoại không hợp lệ.'] } })
  assert.equal(applyApiFieldErrors(error, (...args) => applied.push(args)), 2)
  assert.deepEqual(applied.map(([field]) => field), ['email', 'phone'])
  assert.equal(applied[0][1].message, 'Email đã tồn tại.')
})

test('nested pivot errors map to the visible selection once', () => {
  const applied = []
  applyApiFieldErrors(apiError(422, { errors: { 'amenity_ids.0': ['Tiện nghi không hợp lệ.'], 'amenity_ids.1': ['Tiện nghi khác không hợp lệ.'] } }), (...args) => applied.push(args), field => field.split('.')[0])
  assert.equal(applied.length, 1)
  assert.equal(applied[0][0], 'amenity_ids')
})

test('specific backend 401/403/404/409 messages are preserved', () => {
  for (const [status, message] of [[401, 'Email hoặc mật khẩu không chính xác.'], [403, 'Vui lòng xác minh email trước khi đăng nhập.'], [404, 'Không tìm thấy hợp đồng.'], [409, 'Yêu cầu thanh toán payOS hiện tại đang được đối soát.']]) {
    assert.equal(getApiErrorMessage(apiError(status, { message })), message)
  }
})

test('status fallbacks are Vietnamese and not resource-specific guesses', () => {
  assert.equal(getApiErrorMessage(apiError(401, {})), 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
  assert.equal(getApiErrorMessage(apiError(404, { message: 'Not Found.' })), 'Không tìm thấy dữ liệu.')
  assert.equal(getApiErrorMessage(apiError(409, {})), 'Yêu cầu bị xung đột với dữ liệu hiện tại. Vui lòng tải lại và thử lại.')
})

test('5xx never expose SQL, file paths, secret or field errors', () => {
  for (const status of [500, 502, 503]) {
    const error = apiError(status, { message: 'SQLSTATE credentials /var/www/app.php', errors: { password: ['Secret'] } })
    assert.equal(getApiErrorMessage(error), 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.')
    assert.deepEqual(getApiFieldErrors(error), {})
  }
  assert.equal(getApiErrorMessage(apiError(422, { message: 'SQLSTATE constraint violation' })), 'Dữ liệu không hợp lệ.')
})

test('offline and transport errors do not expose Axios messages', () => {
  assert.equal(getApiErrorMessage({ message: 'Network Error' }), 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra kết nối và thử lại.')
  assert.equal(getApiErrorMessage(apiError(422, { message: 'Request failed with status code 422' })), 'Dữ liệu không hợp lệ.')
  assert.deepEqual(getApiFieldErrors(apiError(422, { errors: null })), {})
})
