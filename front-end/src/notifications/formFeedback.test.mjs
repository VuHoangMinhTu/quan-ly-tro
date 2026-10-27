import assert from 'node:assert/strict'
import test from 'node:test'
import { handleFormSubmit, notifyInvalidSubmit } from './formFeedback.js'
import { clearToasts, getToasts } from './toastStore.js'

test('invalid submit shows the first nested validation message once without walking DOM refs', () => {
  clearToasts()
  const ref = {}; ref.self = ref
  const errors = { items: [{ ref }, { name: { message: 'Vui lòng nhập tên thiết bị.', ref } }] }
  try {
    notifyInvalidSubmit(errors)
    notifyInvalidSubmit(errors)
    assert.equal(getToasts().length, 1)
    assert.equal(getToasts()[0].type, 'warning')
    assert.equal(getToasts()[0].message, 'Vui lòng nhập tên thiết bị.')
  } finally { clearToasts() }
})

test('validation without a message still gives explicit feedback', () => {
  clearToasts()
  try {
    notifyInvalidSubmit({ amount: { type: 'required' } })
    assert.match(getToasts()[0].message, /Dữ liệu chưa hợp lệ/)
  } finally { clearToasts() }
})

test('rapid submits share a form lock before async validation and release it after rejection', async () => {
  let release, validations = 0, writes = 0
  const form = { handleSubmit: onValid => async () => { validations++; await new Promise(resolve => { release = resolve }); await onValid() } }
  const submit = handleFormSubmit(form, async () => { writes++; throw new Error('Synthetic failure') })
  const first = submit()
  await Promise.all([submit(), submit()])
  assert.equal(validations, 1)
  release()
  await assert.rejects(first, /Synthetic failure/)
  assert.equal(writes, 1)
  const next = submit()
  assert.equal(validations, 2)
  release()
  await assert.rejects(next, /Synthetic failure/)
})

test('invalid form submit releases its lock for the corrected attempt', async () => {
  clearToasts()
  let invalid = true, writes = 0
  const form = { handleSubmit: (valid, failed) => async () => invalid ? failed({ name: { message: 'Vui lòng nhập tên.' } }) : valid() }
  const submit = handleFormSubmit(form, () => { writes++ })
  try {
    await submit()
    assert.equal(writes, 0)
    invalid = false
    await submit()
    assert.equal(writes, 1)
  } finally { clearToasts() }
})
