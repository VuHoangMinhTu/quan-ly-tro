import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import * as pricing from '../../utils/contractServices.js'
import * as dates from '../../utils/date.js'
import { BILLING_METHOD_LABELS } from '../../utils/service.js'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage.js'
import { contractKeys } from '../../api/contractKeys.js'

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
async function component(path, name, bindings) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}
function stateHarness() {
  const state = []; let cursor = 0
  return {
    useState: initial => { const index = cursor++; if (!(index in state)) state[index] = typeof initial === 'function' ? initial() : initial; return [state[index], value => { state[index] = value }] },
    reset: () => { cursor = 0 },
  }
}
const common = { ...pricing, ...dates, BILLING_METHOD_LABELS, contractKeys, getApiErrorMessage, getApiFieldErrors,
  formatCurrency: value => `${Number(value).toLocaleString('vi-VN')} đ`, formatDate: dates.formatDateForDisplay, formatUtilityValue: String,
}
const Modal = () => null
const PriceEditor = () => null
const DraftEditor = () => null
const DateInput = () => null
const oldTerm = { id: 15, service_id: 3, service_name: 'Điện đã ký', billing_method: 'PER_UNIT', base_price: '4000.00', vat_rate: '8.00', unit: 'kWh', is_active: true, effective_from: '2027-01-01', effective_to: '2027-05-31' }
const newTerm = { ...oldTerm, id: 16, base_price: '4500.00', vat_rate: '10.00', effective_from: '2027-06-01', effective_to: null, adjustment_reason: 'Giá mới' }

test('pricing payload keeps numeric money, nullable tiers and stable source IDs without live metadata', () => {
  const row = pricing.editableContractService({ id: 3, name: 'Điện', billing_method: 'PER_UNIT', base_price: '4000.00', vat_rate: '8.00' })
  assert.deepEqual(pricing.contractServicesPayload([row]), [{ service_id: 3, base_price: 4000, vat_rate: 8, pricing_snapshot: null }])
  const tiered = { billing_method: 'TIERED', vat_rate: 0, pricing_snapshot: [{ from_quantity: '0', to_quantity: '', unit_price: '3500' }] }
  assert.equal(pricing.contractServicePricingError(tiered), '')
  assert.deepEqual(pricing.contractServicePricingPayload(tiered), { base_price: null, vat_rate: 0, pricing_snapshot: [{ from_quantity: 0, to_quantity: null, unit_price: 3500 }] })
  assert.match(pricing.contractServicePricingError({ ...tiered, pricing_snapshot: [{ from_quantity: 1, to_quantity: null, unit_price: 1 }] }), /liên tiếp từ 0/)
  assert.match(pricing.contractServicePricingError({ billing_method: 'FIXED', base_price: '', vat_rate: 0 }), /mức giá/)
  assert.equal(pricing.termAppliesOn(oldTerm, '2027-05-20'), true)
  assert.equal(pricing.termAppliesOn(newTerm, '2027-05-20'), false)
})

async function sectionHarness(status = 'ACTIVE', legacy = false) {
  const state = stateHarness(); const calls = []; const invalidated = []
  const data = { today: '2027-05-20', legacy_fallback: legacy, terms: [oldTerm, newTerm], available_services: [{ id: 4, name: 'Internet', billing_method: 'FIXED', base_price: 150000 }] }
  const bindings = { ...common, ...state, ContractServiceChangeModal: Modal,
    useQueryClient: () => ({ invalidateQueries: async ({ queryKey }) => invalidated.push(queryKey) }),
    useQuery: () => ({ data: { data: { data } } }),
    useMutation: options => ({ mutateAsync: async value => { await options.mutationFn(value); await options.onSuccess() } }),
    getContractServices: () => {}, saveContractServices: async (...args) => calls.push(['draft', ...args]),
    adjustContractService: async (...args) => calls.push(['adjust', ...args]), stopContractService: async (...args) => calls.push(['stop', ...args]),
    window: { confirm: () => true },
  }
  const Section = await component('./ContractServicesSection.jsx', 'ContractServicesSection', bindings)
  const render = () => { state.reset(); return Section({ contract: { id: 1, room_id: 7, status } }) }
  return { render, calls, invalidated }
}

test('active service cards show historical prices, future labels and mutation invalidation without developer wording', async () => {
  const page = await sectionHarness()
  const tree = page.render()
  assert.match(text(tree), /Lịch sử thay đổi dịch vụ/)
  assert.doesNotMatch(text(tree), /RoomServices|Billing hiện tại|snapshot/)
  assert.ok(nodes(tree).some(node => node.type === 'details'))
  assert.ok(nodes(tree).some(node => node.props.className?.includes('sm:grid-cols-2')))
  nodes(tree).find(node => node.type === 'button' && text(node) === 'Điều chỉnh dịch vụ').props.onClick()
  const modal = nodes(page.render()).find(node => node.type === Modal)
  assert.equal(modal.props.action.term.id, 16)
  await modal.props.onSave({ effective_from: '2027-07-01', base_price: 5000, vat_rate: 10, reason: 'Điều chỉnh' })
  assert.equal(page.calls[0][0], 'adjust')
  assert.equal(page.calls[0][1], 1)
  assert.ok(page.invalidated.some(key => key[0] === 'contract-services'))
  assert.ok(page.invalidated.some(key => key[0] === 'invoice'))
  assert.ok(!nodes(page.render()).some(node => node.type === Modal))
})

test('draft offers edit/sync; ended contracts and legacy active contracts expose no write controls', async () => {
  const draft = await sectionHarness('DRAFT')
  const controls = nodes(draft.render()).filter(node => node.type === 'button').map(text)
  assert.ok(controls.includes('Chỉnh sửa dịch vụ'))
  await nodes(draft.render()).find(node => node.type === 'button' && text(node) === 'Đồng bộ từ phòng').props.onClick()
  assert.deepEqual(draft.calls[0], ['draft', 1, { sync_from_room: true }])
  for (const status of ['EXPIRED', 'TERMINATED']) {
    const page = await sectionHarness(status)
    assert.equal(nodes(page.render()).filter(node => node.type === 'button').length, 0)
  }
  const legacy = await sectionHarness('ACTIVE', true)
  assert.match(text(legacy.render()), /Hợp đồng cũ chưa có bảng giá/)
  assert.equal(nodes(legacy.render()).filter(node => node.type === 'button').length, 0)
})

async function modalHarness(type = 'adjust', pending = false) {
  const state = stateHarness(); const payloads = []
  const Component = await component('./ContractServiceChangeModal.jsx', 'ContractServiceChangeModal', {
    ...common, ...state, useEffect: () => {}, useRef: () => ({ current: null }), DateInput,
    ContractServicePriceEditor: PriceEditor, ContractDraftServicesEditor: DraftEditor,
  })
  const render = () => { state.reset(); return Component({ action: { type, term: newTerm }, availableServices: [], terms: [newTerm], pending, onClose: () => {}, onSave: async payload => payloads.push(payload) }) }
  const submit = async () => nodes(render()).find(node => node.type === 'form').props.onSubmit({ preventDefault() {} })
  return { render, submit, payloads }
}

test('adjustment modal submits month-first DATE, numeric prices and reason; rejects incomplete selection', async () => {
  const page = await modalHarness()
  await page.submit()
  assert.equal(page.payloads.length, 0)
  assert.match(text(page.render()), /Vui lòng chọn kỳ áp dụng/)
  nodes(page.render()).find(node => node.type === DateInput).props.onChange('06/2027')
  nodes(page.render()).find(node => node.type === 'textarea').props.onChange({ target: { value: ' Điều chỉnh điện ' } })
  const editor = nodes(page.render()).find(node => node.type === PriceEditor)
  editor.props.onChange({ ...editor.props.value, base_price: 5500, vat_rate: '10' })
  await page.submit()
  assert.deepEqual(page.payloads[0], { contract_service_id: 16, effective_from: '2027-06-01', base_price: 5500, vat_rate: 10, pricing_snapshot: null, reason: 'Điều chỉnh điện' })
  assert.ok(nodes(page.render()).some(node => node.props.role === 'dialog' && node.props['aria-modal']))
  assert.ok(nodes(page.render()).some(node => node.props.className?.includes('max-h-[92dvh]')))
})

test('stop modal never submits pricing; pending modal prevents duplicate submission', async () => {
  const page = await modalHarness('stop')
  assert.ok(!nodes(page.render()).some(node => node.type === PriceEditor))
  nodes(page.render()).find(node => node.type === DateInput).props.onChange('09/2027')
  nodes(page.render()).find(node => node.type === 'textarea').props.onChange({ target: { value: 'Không sử dụng nữa' } })
  await page.submit()
  assert.deepEqual(page.payloads[0], { effective_from: '2027-09-01', reason: 'Không sử dụng nữa' })
  const pending = await modalHarness('adjust', true)
  await pending.submit()
  assert.equal(pending.payloads.length, 0)
})

test('draft service editor normalizes checkbox IDs, preserves edited price and confirms destructive sync', async () => {
  const state = stateHarness()
  let value = [pricing.editableContractService({ id: 3, name: 'Điện', billing_method: 'PER_UNIT', base_price: 4321 })]
  let confirmed = false; let syncs = 0
  const Editor = await component('./ContractDraftServicesEditor.jsx', 'ContractDraftServicesEditor', {
    ...common, ...state, ContractServicePriceEditor: PriceEditor, window: { confirm: () => confirmed },
  })
  const availableServices = [{ id: '3', name: 'Điện', billing_method: 'PER_UNIT', base_price: 9999 }]
  const render = () => { state.reset(); return Editor({ availableServices, value, onChange: next => { value = next }, onSync: async () => { syncs += 1; value = availableServices.map(pricing.editableContractService) } }) }
  assert.equal(nodes(render()).find(node => node.type === 'input').props.checked, true)
  assert.equal(nodes(render()).find(node => node.type === PriceEditor).props.value.base_price, 4321)
  const sync = () => nodes(render()).find(node => node.type === 'button' && text(node) === 'Đồng bộ từ dịch vụ của phòng').props.onClick()
  await sync()
  assert.equal(syncs, 0)
  confirmed = true
  await sync()
  assert.equal(syncs, 1)
  assert.equal(value[0].base_price, 9999)
  nodes(render()).find(node => node.type === 'input').props.onChange()
  assert.deepEqual(value, [])
})

test('tier price editor supports adding/removing rows with money inputs and an open-ended last tier', async () => {
  let value = { billing_method: 'TIERED', vat_rate: 8, pricing_snapshot: [{ from_quantity: 0, to_quantity: 50, unit_price: 3500 }] }
  const Money = () => null
  const Editor = await component('./ContractServicePriceEditor.jsx', 'ContractServicePriceEditor', { MoneyInput: Money, getBasePriceLabel: () => 'Đơn giá' })
  const render = () => Editor({ value, onChange: next => { value = next } })
  nodes(render()).find(node => node.type === 'button' && text(node) === '+ Thêm bậc giá').props.onClick()
  assert.equal(value.pricing_snapshot.length, 2)
  assert.equal(value.pricing_snapshot[1].from_quantity, 50)
  assert.equal(value.pricing_snapshot[1].to_quantity, null)
  nodes(render()).filter(node => node.type === Money)[1].props.onChange(4000)
  assert.equal(pricing.contractServicePricingError(value), '')
  assert.ok(nodes(render()).some(node => node.props.className?.includes('sm:grid-cols-3')))
  nodes(render()).find(node => node.type === 'button' && /Bỏ bậc\s+2/.test(text(node))).props.onClick()
  assert.equal(value.pricing_snapshot.length, 1)
})
