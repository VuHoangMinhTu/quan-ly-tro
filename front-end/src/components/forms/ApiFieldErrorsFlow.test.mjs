import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { createFormControl } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { transformWithOxc } from 'vite'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage.js'
import { contractKeys } from '../../api/contractKeys.js'
import { tenantKeys } from '../../api/tenantKeys.js'
import { invoiceStatusLabels } from '../../utils/invoiceStatus.js'
import * as dates from '../../utils/date.js'
import { toFormString } from '../../utils/form.js'
import * as serviceHelpers from '../../utils/service.js'

function elements(node) {
  if (Array.isArray(node)) return node.flatMap(elements)
  if (!React.isValidElement(node)) return []
  if (typeof node.type === 'function') return elements(node.type(node.props))
  return [node, ...elements(node.props.children)]
}

function text(node) {
  if (Array.isArray(node)) return node.map(text).join(' ')
  if (React.isValidElement(node)) return text(node.props.children)
  return typeof node === 'string' || typeof node === 'number' ? String(node) : ''
}

// Real form handlers + RHF validation; isolate only hooks/router/network.
async function harness(path, props = {}, extraBindings = {}) {
  let form
  let mutation
  let stateCursor = 0
  const states = []
  let previousDependencies
  const input = (inputProps) => React.createElement('input', inputProps)
  const bindings = {
    ...dates, ...serviceHelpers, toFormString, applyApiFieldErrors, getApiErrorMessage, z, zodResolver, contractKeys, tenantKeys, invoiceStatusLabels,
    useRef: (initial) => ({ current: initial }),
    useState: (initial) => {
      const index = stateCursor++
      if (!(index in states)) states[index] = initial
      return [states[index], (value) => { states[index] = value }]
    },
    useEffect: (effect, dependencies) => {
      if (!previousDependencies || dependencies.some((value, index) => value !== previousDependencies[index])) {
        previousDependencies = dependencies
        effect()
      }
    },
    useQuery: () => ({ data: { data: { data: [] } } }),
    useMutation: (options) => { mutation = options; return {} },
    useNavigate: () => () => {},
    useForm: (options) => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true, errors: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    },
    Controller: ({ name, render }) => render({ field: { name, value: form.getValues(name), ref: () => {}, onChange: (value) => form.setValue(name, value), onBlur: () => {} } }),
    DateInput: input, MoneyInput: input, Link: ({ children }) => React.createElement('a', null, children),
    getContractsByRoom: () => {}, getTenants: () => {}, register: () => {},
    ...extraBindings,
  }
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const name = source.match(/function (RegisterPage|ContractForm|InvoiceForm|TenantForm|ServiceForm|ProtectedRoute)\(/)[1]
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
  const render = () => { stateCursor = 0; return elements(Component(props)) }
  render()
  return { form, render, fail: (error) => mutation.onError(error), submit: () => render().find(node => node.type === 'form').props.onSubmit() }
}

const apiError = (status, message, errors) => ({ response: { status, data: { success: false, message, errors } } })

const validContract = { tenant_id: 2, contract_code: 'HD001', start_date: '2026-11-16', monthly_rent: 2000000, deposit_amount: 1000000 }

for (const status of ['DRAFT', 'ACTIVE']) {
  test(`${status} contract accepts API null note as an empty textarea and submits null`, async () => {
    let payload
    const page = await harness('./ContractForm.jsx', { initialValues: { ...validContract, status, note: null }, onSubmit: async values => { payload = values } })
    assert.equal(page.form.getValues('note'), '')
    await page.submit()
    assert.equal(payload.note, null)
    assert.equal(payload.status, status)
    assert.equal(page.form.formState.errors.note, undefined)
    assert.equal(page.render().some(node => /expected string|mong đợi string|nhận được null/i.test(text(node))), false)
  })
}

test('contract preserves text, trims it on submit and sends null when cleared', async () => {
  const payloads = []
  const page = await harness('./ContractForm.jsx', { initialValues: { ...validContract, note: 'abc' }, onSubmit: async values => payloads.push(values) })
  assert.equal(page.form.getValues('note'), 'abc')
  await page.submit()
  assert.equal(payloads[0].note, 'abc')
  page.form.setValue('note', '  ghi chú  ')
  await page.submit()
  assert.equal(payloads[1].note, 'ghi chú')
  for (const value of ['', '   ']) {
    page.form.setValue('note', value)
    await page.submit()
    assert.equal(payloads.at(-1).note, null)
  }
})

test('genuinely invalid contract note types get a user-facing message', async () => {
  let submitted = false
  const page = await harness('./ContractForm.jsx', { initialValues: { ...validContract, note: 123 }, onSubmit: async () => { submitted = true } })
  await page.submit()
  assert.equal(submitted, false)
  assert.equal(page.form.formState.errors.note.message, 'Ghi chú phải là nội dung văn bản.')
})

test('tenant normalizes every nullable text field and submits empty fields as null', async () => {
  const fields = ['phone', 'email', 'identity_number', 'identity_issue_place', 'permanent_address']
  let payload
  const page = await harness('./TenantForm.jsx', { initialValues: { full_name: 'Nguyễn Văn A', ...Object.fromEntries(fields.map(field => [field, null])) }, onSubmit: async values => { payload = values } })
  for (const field of fields) assert.equal(page.form.getValues(field), '')
  await page.submit()
  assert.ok(payload)
  for (const field of fields) assert.equal(payload[field], null)
  assert.deepEqual(page.form.formState.errors, {})
})

test('service nullable unit remains optional without changing pricing payload', async () => {
  let payload
  const page = await harness('./ServiceForm.jsx', { initialValues: { name: 'Dịch vụ', type: 'OTHER', billing_method: 'FIXED', unit: null, base_price: 25000 }, onSubmit: async values => { payload = values } })
  assert.equal(page.form.getValues('unit'), '')
  await page.submit()
  assert.equal(payload.unit, null)
  assert.equal(payload.base_price, 25000)
  assert.deepEqual(page.form.formState.errors, {})
})

test('invoice nullable note is an empty input and retains the null API convention', async () => {
  let payload
  const page = await harness('./InvoiceForm.jsx', { roomId: 1, initialValues: { invoice_code: 'HD001', billing_period: '2026-10-01', note: null }, onSubmit: async values => { payload = values } })
  assert.equal(page.form.getValues('note'), '')
  await page.submit()
  assert.equal(payload.note, null)
})

for (const field of ['name', 'email', 'phone', 'password']) {
  test(`register ${field} validation displays only the matching field, never Laravel generic text`, async () => {
    const form = await harness('../../pages/auth/RegisterPage.jsx')
    const message = `Lỗi dữ liệu ${field}.`
    form.fail(apiError(422, 'The given data was invalid.', { [field]: [message] }))
    assert.deepEqual(Object.keys(form.form.formState.errors), [field])
    const rendered = form.render()
    assert.equal(rendered.find(node => node.props.id === `${field}-error`).props.children, message)
    assert.equal(rendered.some(node => node.props.role === 'alert'), false)
    assert.equal(rendered.some(node => text(node).includes('The given data was invalid.')), false)
  })
}

test('register multiple field errors and a subsequent server failure stay separate', async () => {
  const form = await harness('../../pages/auth/RegisterPage.jsx')
  form.fail(apiError(422, 'The given data was invalid.', { email: ['Email đã tồn tại.'], phone: ['Số điện thoại không hợp lệ.'] }))
  assert.deepEqual(Object.keys(form.form.formState.errors), ['email', 'phone'])
  form.fail(apiError(500, 'SQLSTATE secret', { password: ['Internal secret'] }))
  assert.deepEqual(form.form.formState.errors, {})
  const rendered = form.render()
  assert.equal(rendered.find(node => node.props.role === 'alert').props.children, 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.')
  assert.equal(rendered.some(node => text(node).includes('SQLSTATE')), false)
})

test('contract catches duplicate-code errors under the input and preserves DATE payloads', async () => {
  const message = 'Mã hợp đồng này đã được sử dụng. Vui lòng chọn mã khác.'
  let payload
  const form = await harness('./ContractForm.jsx', { initialValues: {
    tenant_id: 2, contract_code: 'HD001', start_date: '2026-11-16', end_date: '2026-12-16', signed_date: '2026-11-15', monthly_rent: 2000000, deposit_amount: 1000000,
  }, onSubmit: async (values) => { payload = values; throw apiError(422, message, { contract_code: [message] }) } })
  await form.submit()
  assert.equal(payload.start_date, '2026-11-16')
  assert.equal(form.form.formState.errors.contract_code.message, message)
  const fieldContainer = form.render().find(node => node.type === 'div' && elements(node).some(child => child.props.name === 'contract_code'))
  assert.ok(text(fieldContainer).includes(message))
})

test('ACTIVE contract start_date errors are not relabeled as overlap errors', async () => {
  const message = 'Ngày bắt đầu phải là ngày hợp lệ.'
  const form = await harness('./ContractForm.jsx', { initialValues: { tenant_id: 2, contract_code: 'HD001', start_date: '2026-11-16', monthly_rent: 2000000, deposit_amount: 1000000, status: 'ACTIVE' }, onSubmit: async () => { throw apiError(422, message, { start_date: [message] }) } })
  await form.submit()
  assert.equal(form.form.formState.errors.start_date.message, message)
})

test('invoice awaits a rejected mutation, maps fields and retains numeric/DATE API payload', async () => {
  let payload
  const message = 'Hợp đồng phải thuộc phòng này.'
  const form = await harness('./InvoiceForm.jsx', { roomId: 1, initialValues: { contract_id: 2, invoice_code: 'HD001', billing_period: '2026-10-01', discount_amount: 25000, status: 'DRAFT' }, contractOptions: [{ id: 2, contract_code: 'HD2' }], onSubmit: async values => { payload = values; throw apiError(422, message, { contract_id: [message], discount_amount: ['Giảm giá không hợp lệ.'] }) } })
  await form.submit()
  assert.equal(payload.contract_id, 2)
  assert.equal(payload.billing_period, '2026-10-01')
  assert.equal(payload.discount_amount, 25000)
  assert.equal(form.form.formState.errors.contract_id.message, message)
  assert.equal(form.form.formState.errors.discount_amount.message, 'Giảm giá không hợp lệ.')
  assert.ok(form.render().some(node => node.type === 'p' && node.props.children === message))
})

test('expired-token redirect retains 401 feedback when Axios already removed the token', async () => {
  const page = await harness('../../routes/ProtectedRoute.jsx', {}, {
    useQuery: () => ({ isError: true, error: apiError(401, 'Unauthenticated.') }),
    getMe: () => {},
    localStorage: { getItem: () => null },
    Navigate: props => React.createElement('navigation', props),
    Outlet: () => React.createElement('outlet'),
  })
  const redirect = page.render().find(node => node.type === 'navigation')
  assert.equal(redirect.props.to, '/login')
  assert.equal(redirect.props.replace, true)
  assert.equal(redirect.props.state.error, 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
})
