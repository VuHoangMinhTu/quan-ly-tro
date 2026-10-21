import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createFormControl } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { transformWithOxc } from 'vite'
import { residenceFormValues, residencePayload, wholeMonthsBetweenDates } from '../../utils/residence.js'
import { formatBillingPeriodForDisplay, formatDateForDisplay, isValidDisplayDate } from '../../utils/date.js'
import { invoiceStatusLabels } from '../../utils/invoiceStatus.js'
import { BILLING_METHOD_LABELS, BILLING_METHODS, getBasePriceLabel, getSuggestedServiceUnit, SERVICE_TYPE_LABELS, SERVICE_TYPES } from '../../utils/service.js'

const formatterSource = (await readFile(new URL('../../utils/formatters.js', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replaceAll('export function ', 'function ').replaceAll('export const ', 'const ')
const { formatCurrency, formatUtilityValue, genderLabels } = new Function('formatDateForDisplay', `${formatterSource}\nreturn { formatCurrency, formatUtilityValue, genderLabels }`)(formatDateForDisplay)

async function load(path, name, bindings = {}) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ').replaceAll('export function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}
function elements(node) {
  if (Array.isArray(node)) return node.flatMap(elements)
  if (!React.isValidElement(node)) return []
  if (typeof node.type === 'function') return elements(node.type(node.props))
  return [node, ...elements(node.props.children)]
}
const Link = ({ to, children, ...props }) => React.createElement('a', { href: to, ...props }, children)
const tenant = { id: 1, full_name: 'Nguyễn Văn A', date_of_birth: '2000-09-28', gender: 'MALE', identity_number: '079000000001', phone: null, email: null }
const context = { room: { id: 11 }, operational_role: 'OWNER', occupants: [{ tenant }, { tenant: { ...tenant, id: 2, full_name: 'Trần Thị B' } }], contracts: [] }

async function formHarness(initial, formContext = context) {
  let form
  const submitted = []
  const Component = await load('./ResidenceDeclarationForm.jsx', 'ResidenceDeclarationForm', {
    useState: () => ['', () => {}], useWatch: ({ name }) => form.getValues(name),
    useForm: options => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    }, zodResolver, z, Link, formatDateForDisplay, isValidDisplayDate, genderLabels, residenceFormValues, residencePayload,
    applyApiFieldErrors: () => {}, getApiErrorMessage: () => 'Lỗi API',
  })
  const props = { context: formContext, initial, onSubmit: async payload => { submitted.push(payload) } }
  const render = () => elements(Component(props))
  render()
  return { form, props, render, submitted, submit: () => render().find(node => node.type === 'form').props.onSubmit() }
}

test('nullable declaration fields become editable strings and payload is limited to declaration data', () => {
  const values = residenceFormValues({ declarant_tenant_id: 1, contract_id: null, authority_name: null, relationship_to_household_head: null, request_content: null, members: [{ tenant_id: '2', relationship_to_household_head: null }] })
  assert.equal(values.authority_name, '')
  assert.equal(values.declarant_tenant_id, '1')
  assert.equal(values.members[0].tenant_id, 2)
  const payload = residencePayload('11', { ...values, landlord_id: 99, full_name: 'Ghi đè', snapshot: { fake: true } })
  assert.equal(payload.room_id, 11)
  assert.equal(payload.declarant_tenant_id, 1)
  assert.equal(payload.contract_id, null)
  assert.equal(payload.authority_name, null)
  assert.deepEqual(payload.members, [{ tenant_id: 2, relationship_to_household_head: null }])
  assert.ok(!('landlord_id' in payload) && !('full_name' in payload) && !('snapshot' in payload))
})

test('household head choice belongs to the declaration and clears stale details when declarant is head', () => {
  const values = residenceFormValues({ household_head_mode: 'OTHER_PERSON', household_head_name: 'Chủ hộ riêng', household_head_identity_number: '079888888888', relationship_to_household_head: 'Ở thuê' })
  assert.equal(values.household_head_name, 'Chủ hộ riêng')
  const other = residencePayload(11, values)
  assert.equal(other.household_head_mode, 'OTHER_PERSON')
  assert.equal(other.household_head_name, 'Chủ hộ riêng')
  assert.equal(other.relationship_to_household_head, 'Ở thuê')
  const declarant = residencePayload(11, { ...values, household_head_mode: 'DECLARANT' })
  assert.equal(declarant.household_head_name, null)
  assert.equal(declarant.household_head_identity_number, null)
  assert.equal(declarant.relationship_to_household_head, 'Chủ hộ')
})

test('draft can save without legal details and selecting roommate sends numeric IDs, not copied master data', async () => {
  const harness = await formHarness()
  await harness.submit()
  assert.equal(harness.submitted[0].declarant_tenant_id, null)
  harness.form.setValue('declarant_tenant_id', '1')
  harness.render().find(node => node.type === 'input' && node.props.type === 'checkbox').props.onChange()
  harness.form.setValue('members.0.relationship_to_household_head', 'Người thuê')
  await harness.submit()
  assert.equal(harness.submitted[1].declarant_tenant_id, 1)
  assert.deepEqual(harness.submitted[1].members, [{ tenant_id: 2, relationship_to_household_head: 'Người thuê' }])
  assert.ok(!('identity_number' in harness.submitted[1]))
})

test('changing declarant removes that person from member selection and allows removing all members', async () => {
  const harness = await formHarness({ declarant_tenant_id: 1, members: [{ tenant_id: 2, relationship_to_household_head: '' }] })
  harness.render().find(node => node.type === 'select' && node.props.id === 'ct01-declarant').props.onChange({ target: { name: 'declarant_tenant_id', value: '2' }, type: 'change' })
  await harness.submit()
  assert.deepEqual(harness.submitted[0].members, [])
  assert.equal(harness.submitted[0].declarant_tenant_id, 2)
})

test('async context refetch does not reset the note or selected members and a new room form starts empty', async () => {
  const harness = await formHarness({ declarant_tenant_id: 1, members: [{ tenant_id: 2, relationship_to_household_head: 'Người thuê' }] })
  harness.form.setValue('request_content', 'Nội dung đang nhập')
  harness.props.context = { ...context, occupants: [...context.occupants] }
  harness.render()
  await harness.submit()
  assert.equal(harness.submitted[0].request_content, 'Nội dung đang nhập')
  assert.equal(harness.submitted[0].members[0].tenant_id, 2)
  const other = await formHarness()
  assert.deepEqual(other.form.getValues('members'), [])
})

test('CT01 matches the Word section order and leaves missing fields blank', async () => {
  const Document = await load('./Ct01Document.jsx', 'Ct01Document', { formatDateForDisplay, genderLabels })
  const markup = renderToStaticMarkup(React.createElement(Document, { data: { declarant: { ...tenant, full_name: '<script>alert(1)</script>' }, authority_name: null, request_content: '<img src=x>', relationship_to_household_head: null, household_head: { name: null, identity_number: null }, members: [] } }))
  assert.match(markup, /28\/09\/2000/)
  assert.match(markup, /&lt;script&gt;/)
  assert.doesNotMatch(markup, /<script>|<img src=x>|Tôi đồng ý/)
  assert.match(markup, /Mẫu CT01 ban hành kèm theo Thông tư số 116\/2026\/TT-BCA/)
  assert.match(markup, /Kính gửi/)
  assert.match(markup, /11\. Những thành viên trong hộ gia đình cùng thay đổi/)
  assert.equal((markup.match(/class="ct01-identity-boxes"/g) ?? []).length, 2)
  assert.equal((markup.match(/<tr>/g) ?? []).length, 9)
  assert.equal((markup.match(/class="ct01-signature"/g) ?? []).length, 4)
  assert.doesNotMatch(markup, /Chưa cập nhật|null|undefined/)
})

test('manual stay dates use dd/mm/yyyy in form and submit exact date strings', async () => {
  const harness = await formHarness({ stay_start_date: '2026-11-15', stay_end_date: '2027-11-14' })
  assert.equal(harness.form.getValues('stay_start_date'), '15/11/2026')
  await harness.submit()
  assert.equal(harness.submitted[0].stay_start_date, '2026-11-15')
  assert.equal(harness.submitted[0].stay_end_date, '2027-11-14')
  const buttons = harness.render().filter(node => node.type === 'button').map(node => node.props.children)
  assert.ok(buttons.includes('Lưu bản nháp') && buttons.includes('Xem trước CT01') && buttons.includes('Xem trước văn bản ở nhờ') && buttons.includes('Hoàn tất'))
})

test('contract stay dates prefill form and remain editable for the separate consent document', async () => {
  const contract = { id: 5, contract_code: 'HD-5', start_date: '2026-10-20', end_date: '2026-11-21' }
  const harness = await formHarness({ contract_id: 5, stay_start_date: contract.start_date, stay_end_date: contract.end_date }, { ...context, contracts: [contract] })
  assert.equal(harness.form.getValues('stay_start_date'), '20/10/2026')
  assert.equal(harness.form.getValues('stay_end_date'), '21/11/2026')
  harness.form.setValue('stay_end_date', '25/11/2026')
  await harness.submit()
  assert.equal(harness.submitted[0].stay_start_date, '2026-10-20')
  assert.equal(harness.submitted[0].stay_end_date, '2026-11-25')
})

test('no other active occupants has no member checkbox and independent confirmation choices submit', async () => {
  const harness = await formHarness({ declarant_tenant_id: 1 }, { ...context, occupants: [{ tenant }] })
  assert.equal(harness.render().filter(node => node.type === 'input' && node.props.type === 'checkbox').length, 0)
  harness.form.setValue('legal_owner_confirmation_method', 'SEPARATE_DOCUMENT')
  await harness.submit()
  assert.equal(harness.submitted[0].legal_owner_confirmation_method, 'SEPARATE_DOCUMENT')
  assert.equal(harness.submitted[0].guardian_confirmation_method, 'NOT_APPLICABLE')
})

test('stay consent preview uses frozen data and escapes untrusted person fields', async () => {
  const Document = await load('./StayConsentDocument.jsx', 'StayConsentDocument', { formatDateForDisplay, wholeMonthsBetweenDates })
  const markup = renderToStaticMarkup(React.createElement(Document, { data: {
    document_place: 'Vườn Lài', location: '15/3 đường Lê Quý Đôn, Phường Vườn Lài, TP.HCM', captured_at: '2026-11-01', host: { full_name: 'Bên A', birth_year: 1970, permanent_address: 'Địa chỉ A', identity_number: '111', phone: '0900' },
    guest: { full_name: '<script>alert(1)</script>', birth_year: 2000, permanent_address: 'Địa chỉ B', identity_number: '222', phone: '0901' },
    stay_address: '15/3 đường Lê Quý Đôn, Phường Vườn Lài, TP.HCM', room_code: '107', purpose: 'để ở', occupant_count: 2, companions: [{ full_name: 'Người đi cùng' }], start_date: '2026-11-15', end_date: '2027-11-14',
  } }))
  assert.match(markup, /VĂN BẢN ĐỒNG Ý CHO Ở NHỜ NHÀ/)
  assert.match(markup, /15\/11\/2026/)
  assert.match(markup, /Người đi cùng/)
  assert.match(markup, /Vườn Lài.*ngày/)
  assert.match(markup, /15\/3 đường Lê Quý Đôn, Phường Vườn Lài, TP.HCM.*Phòng 107/)
  assert.match(markup, /Địa chỉ B/)
  assert.match(markup, /&lt;script&gt;/)
  assert.doesNotMatch(markup, /<script>/)
  assert.match(markup, /2\. Hợp đồng được lập thành 02 bản và có giá trị như nhau/)
  assert.equal((markup.match(/class="stay-consent-companion"/g) ?? []).length, 5)
  assert.doesNotMatch(markup, /Chưa cập nhật|null|undefined/)
  assert.doesNotMatch(markup, /<span class="stay-consent-blank stay-consent-month-count">01<\/span>/)
})

test('stay consent fills exact whole months without timezone conversion or rounding', async () => {
  const Document = await load('./StayConsentDocument.jsx', 'StayConsentDocument', { formatDateForDisplay, wholeMonthsBetweenDates })
  const data = { document_place: 'Vườn Lài', captured_at: '2026-10-21', host: {}, guest: {}, start_date: '2026-10-21', end_date: '2026-11-21' }
  const whole = renderToStaticMarkup(React.createElement(Document, { data }))
  const partial = renderToStaticMarkup(React.createElement(Document, { data: { ...data, end_date: '2026-11-20', guest: { permanent_address: null } } }))
  assert.match(whole, /01.*tháng.*21\/10\/2026.*21\/11\/2026/)
  assert.doesNotMatch(partial, /01.*tháng/)
  assert.match(partial, /21\/10\/2026.*20\/11\/2026/)
  assert.doesNotMatch(partial, /null|undefined/)
})

test('invoice print renders the stored AUTO tax item and total without reading a live service rate', async () => {
  const Icon = () => React.createElement('svg')
  const Layout = await load('../invoices/InvoicePrintLayout.jsx', 'InvoicePrintLayout', { Building2: Icon, CreditCard: Icon, Home: Icon, UserRound: Icon, QRCodeSVG: Icon, formatBillingPeriodForDisplay, formatDateForDisplay, formatCurrency, formatUtilityValue, invoiceStatusLabels, APP_TITLE: 'BeeHouse - Quản lý trọ' })
  const invoice = { invoice_code: 'INV-VAT', billing_period: '2026-10-01', status: 'UNPAID', subtotal: 306720, total_amount: 306720, paid_amount: 0, items: [{ id: 1, type: 'ELECTRICITY', description: 'Tiền điện', quantity: 71, unit_price: 4000, amount: 284000, source: 'AUTO' }, { id: 2, type: 'SERVICE_TAX', tax_rate: '8.00', description: 'VAT tiền điện (8%)', quantity: 1, unit_price: 22720, amount: 22720, source: 'AUTO' }] }
  const markup = renderToStaticMarkup(React.createElement(Layout, { invoice }))
  assert.match(markup, /VAT tiền điện \(8%\)/)
  assert.match(markup, /22\.720 đ/)
  assert.match(markup, /306\.720 đ/)
  assert.doesNotMatch(markup, /SERVICE_TAX|vat_rate/)
})

async function serviceHarness() {
  let form
  const submitted = []
  const Form = await load('../forms/ServiceForm.jsx', 'ServiceForm', {
    useState: initial => [initial, () => {}], useRef: initial => ({ current: initial }),
    useForm: options => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    }, Controller: ({ name, render }) => render({ field: { name, value: form.getValues(name), onChange: value => form.setValue(name, value) } }),
    z, zodResolver, BILLING_METHOD_LABELS, BILLING_METHODS, getBasePriceLabel, getSuggestedServiceUnit, SERVICE_TYPE_LABELS, SERVICE_TYPES,
    toFormString: value => value ?? '', MoneyInput: props => React.createElement('input', props),
    applyApiFieldErrors: () => {}, getApiErrorMessage: () => '',
  })
  const render = () => elements(Form({ initialValues: { name: 'Điện', type: 'ELECTRICITY', billing_method: 'FIXED', base_price: '284000.00', unit: null, vat_rate: '8.00' }, onSubmit: async payload => submitted.push(payload), label: 'Lưu' }))
  render()
  return { form, submitted, submit: () => render().find(node => node.type === 'form').props.onSubmit() }
}

test('service VAT input preserves configured rate and submits numeric eight or zero when cleared', async () => {
  const harness = await serviceHarness()
  await harness.submit()
  assert.equal(harness.submitted[0].vat_rate, 8)
  assert.equal(harness.submitted[0].base_price, 284000)
  harness.form.setValue('vat_rate', '')
  await harness.submit()
  assert.equal(harness.submitted[1].vat_rate, 0)
})

test('invalid service VAT never submits and uses user-facing validation messages', async () => {
  const harness = await serviceHarness()
  for (const value of [-1, 101, '8.555', 'abc']) {
    harness.form.setValue('vat_rate', value)
    await harness.submit()
    assert.equal(harness.submitted.length, 0)
    assert.match(harness.form.formState.errors.vat_rate.message, /VAT/)
    assert.doesNotMatch(harness.form.formState.errors.vat_rate.message, /expected|received|Invalid input/i)
  }
})
