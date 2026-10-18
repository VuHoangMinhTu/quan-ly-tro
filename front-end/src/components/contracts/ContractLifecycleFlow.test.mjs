import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import * as dates from '../../utils/date.js'
import { BILLING_METHOD_LABELS } from '../../utils/service.js'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage.js'
import { contractKeys } from '../../api/contractKeys.js'
import { residenceStatusLabels } from '../../utils/residence.js'
import { editableContractService, contractServicesPayload, contractServicePricingError } from '../../utils/contractServices.js'

function nodes(node) {
  if (Array.isArray(node)) return node.flatMap(nodes)
  if (!React.isValidElement(node)) return []
  return [node, ...nodes(node.props.children)]
}
function text(node) {
  if (Array.isArray(node)) return node.map(text).join(' ')
  if (React.isValidElement(node)) return text(node.props.children)
  return node == null || typeof node === 'boolean' ? '' : String(node)
}
const CoreForm = () => null
const DraftServices = () => null
const Input = props => React.createElement('input', props)
const context = {
  room: { id: 7, room_code: '101', monthly_rent: null, max_tenants: 3, boarding_house: { name: 'BeeHouse', default_invoice_issue_day_from: 25, default_invoice_issue_day_to: 28, default_payment_due_day: 5 } },
  tenants: [{ id: 1, full_name: 'Nguyễn Văn A' }, { id: 2, full_name: 'Trần Thị B' }],
  services: [{ id: 3, name: 'Điện', billing_method: 'PER_UNIT', unit: 'kWh', base_price: 4000, vat_rate: 8 }],
  utility_openings: [{ utility_meter_id: 4, service_name: 'Điện', opening_reading: '1800.00', reading_date: '2026-10-01', unit: 'kWh' }],
  representatives: [{ user_id: 9, full_name: 'Ngô Đức Bình', title: 'Quản lý' }],
}
async function wizard(initial) {
  let cursor = 0
  const state = []
  const payloads = []
  const source = (await readFile(new URL('./ContractWizard.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = { ...dates, ContractForm: CoreForm, DateInput: Input, BILLING_METHOD_LABELS, getApiErrorMessage, getApiFieldErrors,
    ContractDraftServicesEditor: DraftServices, editableContractService, contractServicesPayload, contractServicePricingError,
    getContractContext: async () => ({ data: { data: context } }),
    formatCurrency: value => `${value} đ`, formatUtilityValue: String,
    useState: initialValue => { const index = cursor++; if (!(index in state)) state[index] = typeof initialValue === 'function' ? initialValue() : initialValue; return [state[index], value => { state[index] = value }] },
  }
  const { code } = await transformWithOxc(source, 'ContractWizard.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn ContractWizard`)(React, ...Object.values(bindings))
  const render = () => { cursor = 0; return Component({ context, initial, onSubmit: async payload => payloads.push(payload) }) }
  const button = label => nodes(render()).find(node => node.type === 'button' && text(node) === label)
  const core = async values => { const form = nodes(render()).find(node => node.type === CoreForm); await form.props.onSubmit(values) }
  return { render, button, core, payloads }
}
const coreValues = { tenant_id: 1, contract_code: 'HD101', start_date: '2026-10-01', end_date: null, signed_date: null, monthly_rent: 3800000, deposit_amount: 5000000, note: null, status: 'DRAFT' }

test('wizard prefill uses latest meter reading and submitting is delayed until final step', async () => {
  const page = await wizard()
  const core = nodes(page.render()).find(node => node.type === CoreForm)
  assert.equal(core.props.initialValues.monthly_rent, '')
  assert.deepEqual(core.props.tenantOptions, context.tenants)
  await page.core(coreValues)
  assert.match(text(page.render()), /1800.00/)
  assert.equal(page.payloads.length, 0)
  const roommate = nodes(page.render()).find(node => node.type === 'label' && text(node).trim() === 'Trần Thị B')
  nodes(roommate).find(node => node.type === 'input').props.onChange()
  page.button('Tiếp tục').props.onClick()
  nodes(page.render()).find(node => node.props.id === 'contract-representative').props.onChange({ target: { value: '9' } })
  page.button('Tiếp tục').props.onClick()
  await page.button('Kích hoạt hợp đồng').props.onClick()
  assert.deepEqual(page.payloads[0], { ...coreValues, status: 'ACTIVE', lessor_representative_user_id: 9, occupant_tenant_ids: [2], services: [{ service_id: 3, base_price: 4000, vat_rate: 8, pricing_snapshot: null }], payment_due_day: 5, utility_openings: [{ utility_meter_id: 4, opening_reading: 1800, reading_date: '2026-10-01' }] })
})

test('step three only offers representative and monthly payment day, preserves custom selection and submits numeric day', async () => {
  const page = await wizard()
  await page.core(coreValues)
  page.button('Tiếp tục').props.onClick()
  assert.doesNotMatch(text(page.render()), /Ngày bắt đầu gửi hóa đơn|Ngày cuối gửi hóa đơn/)
  assert.match(text(page.render()), /Ngày thanh toán hàng tháng/)
  assert.match(text(page.render()), /ngày 05 hàng tháng/)
  const day = () => nodes(page.render()).find(node => node.props.id === 'contract-payment-due-day')
  assert.equal(day().props.value, 5)
  day().props.onChange({ target: { value: '31' } })
  assert.equal(day().props.value, '31')
  page.button('Tiếp tục').props.onClick()
  page.button('← Quay lại').props.onClick()
  assert.equal(day().props.value, '31')
  page.button('Tiếp tục').props.onClick()
  await page.button('Lưu nháp').props.onClick()
  assert.equal(page.payloads[0].payment_due_day, 31)
  assert.equal('invoice_issue_day_from' in page.payloads[0], false)
  assert.equal('invoice_issue_day_to' in page.payloads[0], false)
})

test('edit keeps its own nullable payment day instead of reapplying house defaults and never resends legacy issue range', async () => {
  for (const savedDay of [null, 12]) {
    const legacy = { ...coreValues, payment_due_day: savedDay, invoice_issue_day_from: 25, invoice_issue_day_to: 28 }
    const page = await wizard(legacy)
    await page.core(legacy)
    page.button('Tiếp tục').props.onClick()
    assert.equal(nodes(page.render()).find(node => node.props.id === 'contract-payment-due-day').props.value, savedDay ?? '')
    page.button('Tiếp tục').props.onClick()
    await page.button('Lưu nháp').props.onClick()
    assert.equal(page.payloads[0].payment_due_day, savedDay)
    assert.equal('invoice_issue_day_from' in page.payloads[0], false)
    assert.equal('invoice_issue_day_to' in page.payloads[0], false)
  }
})

test('monthly payment day rejects out of range or fractional input and allows clearing to null', async () => {
  const page = await wizard()
  await page.core(coreValues)
  page.button('Tiếp tục').props.onClick()
  const setDay = value => nodes(page.render()).find(node => node.props.id === 'contract-payment-due-day').props.onChange({ target: { value } })
  for (const invalid of ['0', '32', '1.5']) {
    setDay(invalid)
    page.button('Tiếp tục').props.onClick()
    await page.button('Lưu nháp').props.onClick()
    assert.equal(page.payloads.length, 0)
    assert.match(text(page.render()), /số nguyên từ 1 đến 31/)
    page.button('← Quay lại').props.onClick()
  }
  setDay('')
  page.button('Tiếp tục').props.onClick()
  await page.button('Lưu nháp').props.onClick()
  assert.equal(page.payloads[0].payment_due_day, null)
})

test('draft with null rent can be saved but cannot be activated without mandatory information', async () => {
  const page = await wizard()
  await page.core({ ...coreValues, start_date: null, monthly_rent: null })
  page.button('Tiếp tục').props.onClick(); page.button('Tiếp tục').props.onClick()
  await page.button('Kích hoạt hợp đồng').props.onClick()
  assert.equal(page.payloads.length, 0)
  assert.match(text(page.render()), /bổ sung ngày bắt đầu/)
  await page.button('Lưu nháp').props.onClick()
  assert.equal(page.payloads[0].monthly_rent, null)
  assert.equal(page.payloads[0].start_date, null)
  assert.equal(page.payloads[0].lessor_representative_user_id, null)
})

test('edit wizard preselects numeric IDs consistently and does not reset user selections on rerender', async () => {
  const page = await wizard({ ...coreValues, lessor_representative_user_id: 9, occupant_tenant_ids: [2], agreed_services: [{ ...context.services[0], service_id: 3 }], utility_openings: context.utility_openings })
  await page.core(coreValues)
  const checked = nodes(page.render()).filter(node => node.type === 'input' && node.props.type === 'checkbox')
  assert.equal(checked.length, 1)
  assert.ok(checked.every(node => node.props.checked))
  assert.equal(nodes(page.render()).find(node => node.type === DraftServices).props.value[0].service_id, 3)
  checked[0].props.onChange()
  assert.equal(nodes(page.render()).filter(node => node.type === 'input' && node.props.type === 'checkbox')[0].props.checked, false)
  page.button('Tiếp tục').props.onClick()
  assert.equal(nodes(page.render()).find(node => node.props.id === 'contract-representative').props.value, '9')
  page.button('Tiếp tục').props.onClick()
  await page.button('Lưu nháp').props.onClick()
  assert.deepEqual(page.payloads[0].occupant_tenant_ids, [])
  assert.equal(page.payloads[0].utility_openings[0].reading_date, '2026-10-01')
})

test('invalid schedule or opening date cannot submit a malformed payload', async () => {
  const page = await wizard()
  await page.core(coreValues)
  const dateField = nodes(page.render()).find(node => node.type === Input)
  dateField.props.onChange('31/02/2026')
  page.button('Tiếp tục').props.onClick(); page.button('Tiếp tục').props.onClick()
  await page.button('Lưu nháp').props.onClick()
  assert.equal(page.payloads.length, 0)
  assert.match(text(page.render()), /kiểm tra chỉ số đầu/)
})

test('contract detail renders signing snapshots rather than changed live parties and prices', async () => {
  const source = (await readFile(new URL('../../pages/contracts/ContractDetailPage.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const contract = { id: 1, room_id: 7, contract_code: 'HD101', status: 'ACTIVE', operational_role: 'MANAGER', tenant: { full_name: 'Tên mới', phone: 'Số mới' }, room: { room_code: 'Phòng mới', max_tenants: 3 }, signing_snapshot: { lessor: { full_name: 'Đại diện lúc ký', title: 'Quản lý' }, tenant: { full_name: 'Người thuê lúc ký', phone: '0900000000' }, room: { room_code: '101', boarding_house_name: 'Nhà lúc ký' }, contract: { monthly_rent: '3800000.00', deposit_amount: '5000000.00', start_date: '2026-09-28' }, services: [{ id: 3, service_name: 'Điện lúc ký', billing_method: 'PER_UNIT', base_price: 4000, vat_rate: 8 }] } }
  const bindings = { ...dates, contractKeys, BILLING_METHOD_LABELS, residenceStatusLabels, getApiErrorMessage,
    ContractServicesSection: () => null,
    formatCurrency: value => `${value} đ`, formatDate: dates.formatDateForDisplay, formatUtilityValue: String, genderLabels: {}, DateInput: Input,
    useState: initial => [typeof initial === 'function' ? initial() : initial, () => {}],
    useParams: () => ({ id: '1' }), useNavigate: () => () => {}, useQueryClient: () => ({}),
    useQuery: () => ({ data: { data: { data: contract } } }), useMutation: () => ({}), getContract: () => {},
    ContractStatusBadge: props => React.createElement('span', null, props.status), Link: props => React.createElement('a', { href: props.to }, props.children),
  }
  const { code } = await transformWithOxc(source, 'ContractDetailPage.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn ContractDetailPage`)(React, ...Object.values(bindings))
  function renderComponents(node) {
    if (Array.isArray(node)) return node.map(renderComponents)
    if (!React.isValidElement(node)) return node
    if (typeof node.type === 'function') return renderComponents(node.type(node.props))
    return React.cloneElement(node, {}, renderComponents(node.props.children))
  }
  const rendered = renderComponents(Component())
  const visible = text(rendered)
  assert.match(visible, /Người thuê lúc ký/)
  assert.match(visible, /Đại diện lúc ký/)
  assert.match(visible, /3800000.00 đ/)
  assert.match(visible, /28\/09\/2026/)
  assert.doesNotMatch(visible, /Tên mới|Phòng mới|Số mới/)
  assert.ok(!nodes(rendered).some(node => node.type === 'a' && node.props.href === '/rooms/7/invoices'))
  assert.ok(!nodes(rendered).some(node => node.type === 'button' && text(node) === 'Xóa nháp'))
})

test('manager create returns to scoped contract list instead of an owner-only RoomDetail API', async () => {
  const source = (await readFile(new URL('../../pages/contracts/ContractCreatePage.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const destinations = []
  const invalidated = []
  const Wizard = () => null
  const bindings = { contractKeys, getApiErrorMessage, ContractWizard: Wizard, roomKeys: { detail: id => ['room', id] },
    useParams: () => ({ roomId: '7' }), useNavigate: () => (to) => destinations.push(to),
    useQueryClient: () => ({ invalidateQueries: async value => invalidated.push(value.queryKey) }),
    useQuery: () => ({ data: { data: { data: { ...context, operational_role: 'MANAGER' } } } }),
    useMutation: () => ({ mutateAsync: async () => {} }), getContractContext: () => {},
    Link: () => null,
  }
  const { code } = await transformWithOxc(source, 'ContractCreatePage.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn ContractCreatePage`)(React, ...Object.values(bindings))
  await nodes(Component()).find(node => node.type === Wizard).props.onSubmit(coreValues)
  assert.deepEqual(destinations, ['/rooms/7/contracts'])
  assert.ok(invalidated.some(key => key[0] === 'contracts'))
})
