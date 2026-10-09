import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { createFormControl } from 'react-hook-form'
import { transformWithOxc } from 'vite'
import { roomKeys } from '../../api/roomKeys.js'
import { serviceKeys } from '../../api/serviceKeys.js'
import { utilityKeys } from '../../api/utilityKeys.js'
import { BILLING_METHOD_LABELS, getSuggestedServiceUnit, SERVICE_TYPE_LABELS } from '../../utils/service.js'

async function loadFunctions(path, names, bindings = {}) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '')
    .replaceAll('export function ', 'function ')
    .replaceAll('export const ', 'const ')
  return new Function(...Object.keys(bindings), `${source}\nreturn { ${names.join(', ')} }`)(...Object.values(bindings))
}

const { formatCurrency } = await loadFunctions('../../utils/formatters.js', ['formatCurrency'], { formatDateForDisplay: () => '' })
const helpers = await loadFunctions('../../utils/roomServices.js', [
  'isActiveFlag', 'isAppliedRoomService', 'getMeterServices', 'getSelectedRoomServiceIds', 'toggleRoomService', 'formatRoomServicePrice',
], { formatCurrency, getSuggestedServiceUnit })

async function loadComponent(path, bindings) {
  let source = await readFile(new URL(path, import.meta.url), 'utf8')
  const name = source.match(/export default function (\w+)\(/)[1]
  source = source.replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}

function elements(node) {
  if (Array.isArray(node)) return node.flatMap(elements)
  if (!React.isValidElement(node)) return []
  if (typeof node.type === 'function') return elements(node.type(node.props))
  return [node, ...elements(node.props.children)]
}

const room = { id: 101, boarding_house_id: 7, room_code: 'P101' }
const service = (id, type = 'WATER', billing_method = 'PER_UNIT', values = {}) => ({
  id, boarding_house_id: 7, name: `Dịch vụ ${id}`, type, billing_method,
  base_price: '20000.00', unit: 'm³', is_active: true, pivot: { is_active: true }, ...values,
})
const catalog = [service('1'), service(2), service(3, 'INTERNET', 'FIXED'), service(4, 'INTERNET', 'FIXED')]

test('room services query keys normalize route and API IDs', () => {
  assert.deepEqual(roomKeys.services(101), roomKeys.services('101'))
  assert.deepEqual(roomKeys.services(101), ['room-services', '101'])
})

test('only active assigned PER_UNIT/TIERED services can have new meters', () => {
  const services = [service(1), service(2, 'ELECTRICITY', 'TIERED'), service(3, 'WATER', 'FIXED'),
    service(4, 'WATER', 'PER_PERSON'), service(5, 'WATER', 'PER_UNIT', { is_active: false }),
    service(6, 'WATER', 'PER_UNIT', { pivot: { is_active: false } }), service(7, 'WATER', 'PER_UNIT', { pivot: undefined })]
  assert.deepEqual(helpers.getMeterServices(services).map((item) => item.id), [1, 2])
})

test('choosing new water/electricity replaces that type; other services allow multiple', () => {
  assert.deepEqual(helpers.toggleRoomService([1, 3], catalog[1], catalog), [3, 2])
  assert.deepEqual(helpers.toggleRoomService([3], catalog[3], catalog), [3, 4])
  assert.deepEqual(helpers.toggleRoomService([1], catalog[0], catalog), [])
  const electricity = [service(10, 'ELECTRICITY'), service(11, 'ELECTRICITY', 'TIERED')]
  assert.deepEqual(helpers.toggleRoomService([10, 3], electricity[1], electricity), [3, 11])
})

test('price text reuses VND formatter and never invents a TIERED base price', () => {
  assert.equal(helpers.formatRoomServicePrice(service(1)), '20.000 đ / m³')
  assert.equal(helpers.formatRoomServicePrice(service(2, 'WATER', 'FIXED', { unit: null, base_price: 100000 })), '100.000 đ / tháng')
  assert.equal(helpers.formatRoomServicePrice(service(3, 'WATER', 'TIERED', { base_price: null })), 'Theo bảng giá bậc thang')
})

async function modalHarness(initialServices = [catalog[0]], query = { data: { data: { data: catalog } } }) {
  const state = []
  const requests = []
  const invalidated = []
  let stateIndex = 0
  let closed = false
  let pendingMutation
  const Component = await loadComponent('./RoomServicesModal.jsx', {
    useState: (initial) => {
      const index = stateIndex++
      if (!(index in state)) state[index] = typeof initial === 'function' ? initial() : initial
      return [state[index], (value) => { state[index] = typeof value === 'function' ? value(state[index]) : value }]
    },
    useEffect: () => {},
    useQuery: () => query,
    useQueryClient: () => ({ invalidateQueries: async ({ queryKey }) => { invalidated.push(queryKey) } }),
    useMutation: (options) => ({
      isPending: false,
      mutate: () => { pendingMutation = (async () => { await options.mutationFn(); await options.onSuccess() })() },
    }),
    updateRoomServices: async (id, payload) => { requests.push({ id, payload }) },
    getServices: () => {}, getApiErrorMessage: () => 'API error',
    roomKeys, serviceKeys, utilityKeys, BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS, ...helpers,
    Link: ({ children }) => React.createElement('a', {}, children),
  })
  const render = () => { stateIndex = 0; return elements(Component({ room, assignedServices: initialServices, onClose: () => { closed = true } })) }
  const toggle = (id) => {
    const index = (query.data?.data?.data || []).filter((item) => helpers.isActiveFlag(item.is_active) && item.boarding_house_id === 7).findIndex((item) => Number(item.id) === id)
    render().filter((node) => node.props.type === 'checkbox')[index].props.onChange()
  }
  const save = async () => {
    render().find((node) => node.type === 'button' && node.props.children === 'Lưu dịch vụ').props.onClick()
    await pendingMutation
  }
  return { render, toggle, save, query, requests, invalidated, get closed() { return closed } }
}

test('assignment modal preselects numeric IDs and does not reset choices on async catalog arrival/refetch', async () => {
  const harness = await modalHarness([catalog[0]], { isPending: true })
  assert.equal(harness.render().filter((node) => node.props.type === 'checkbox').length, 0)
  Object.assign(harness.query, { isPending: false, data: { data: { data: catalog } } })
  assert.equal(harness.render().filter((node) => node.props.type === 'checkbox')[0].props.checked, true)
  harness.toggle(2)
  harness.query.data = { data: { data: [...catalog] } }
  const checkboxes = harness.render().filter((node) => node.props.type === 'checkbox')
  assert.equal(checkboxes[0].props.checked, false)
  assert.equal(checkboxes[1].props.checked, true)
  await harness.save()
  assert.deepEqual(harness.requests, [{ id: 101, payload: { service_ids: [2] } }])
  assert.deepEqual(harness.invalidated, [roomKeys.services(101), roomKeys.detail(101), utilityKeys.meters(101)])
  assert.equal(harness.closed, true)
})

test('no services is valid and modal never includes inactive/other-house services', async () => {
  const invalidCatalog = [...catalog, service(9, 'WATER', 'PER_UNIT', { is_active: false }), service(10, 'WATER', 'PER_UNIT', { boarding_house_id: 8 })]
  const harness = await modalHarness([catalog[0]], { data: { data: { data: invalidCatalog } } })
  assert.equal(harness.render().filter((node) => node.props.type === 'checkbox').length, 4)
  harness.toggle(1)
  await harness.save()
  assert.deepEqual(harness.requests[0].payload, { service_ids: [] })
  const anotherRoom = await modalHarness([])
  assert.ok(anotherRoom.render().filter((node) => node.props.type === 'checkbox').every((node) => !node.props.checked))
})

test('room service section shows empty state, suspended services and meter guidance without inventing a meter', async () => {
  let assigned = []
  let meters = []
  const Component = await loadComponent('./RoomServicesSection.jsx', {
    useState: () => [false, () => {}],
    useQuery: ({ queryKey }) => ({ data: { data: { data: queryKey[0] === 'room-services' ? assigned : meters } } }),
    getRoomServices: () => {}, getUtilityMeters: () => {}, roomKeys, utilityKeys,
    BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS, ...helpers,
    Link: ({ children }) => React.createElement('a', {}, children),
    RoomServicesModal: () => null,
  })
  const render = () => elements(Component({ room }))
  assert.ok(render().some((node) => node.props.children === 'Phòng này chưa áp dụng dịch vụ nào.'))
  assigned = [service(1), service(2, 'WATER', 'FIXED'), service(3, 'INTERNET', 'FIXED', { is_active: false })]
  assert.equal(render().filter((node) => node.type === 'article').length, 3)
  assert.ok(render().some((node) => node.props.children === 'Tạm ngưng'))
  assert.equal(render().filter((node) => typeof node.props.children === 'string' && node.props.children.startsWith('Chưa có đồng hồ đang hoạt động.')).length, 1)
  meters = [{ service_id: 1, is_active: true }]
  assert.equal(render().filter((node) => typeof node.props.children === 'string' && node.props.children.startsWith('Chưa có đồng hồ đang hoạt động.')).length, 0)
})

async function meterHarness(services, initialValues = {}) {
  let form
  let previousDependencies
  const submitted = []
  const Component = await loadComponent('../forms/UtilityMeterForm.jsx', {
    useState: () => ['', () => {}],
    useEffect: (effect, dependencies) => {
      if (!previousDependencies || dependencies.some((item, index) => item !== previousDependencies[index])) {
        previousDependencies = dependencies
        effect()
      }
    },
    useQuery: () => ({ data: { data: { data: services } } }),
    useForm: (options) => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    },
    getRoomServices: () => {}, roomKeys, SERVICE_TYPE_LABELS, ...helpers,
  })
  const render = () => elements(Component({ roomId: 101, initialValues, onSubmit: async (payload) => { submitted.push(payload) }, label: 'Lưu đồng hồ' }))
  render()
  const submit = async () => render().find((node) => node.type === 'form').props.onSubmit()
  return { form, render, submit, submitted }
}

test('meter form submits only assigned metered service and numeric payload', async () => {
  const harness = await meterHarness([catalog[0], catalog[2]])
  const options = harness.render().filter((node) => node.type === 'option').map((node) => node.props.value)
  assert.deepEqual(options, ['', '1'])
  harness.form.setValue('service_id', '1')
  harness.form.setValue('initial_reading', '0')
  await harness.submit()
  assert.equal(harness.submitted[0].service_id, 1)
  assert.equal(harness.submitted[0].initial_reading, 0)
  harness.form.setValue('service_id', '3')
  await harness.submit()
  assert.equal(harness.submitted.length, 1)
  assert.ok(harness.form.formState.errors.service_id)
})

test('historical meter can edit unchanged service but cannot reactivate after unassignment', async () => {
  const active = await meterHarness([], { id: 5, service_id: 1, is_active: true, initial_reading: 0, service: catalog[0] })
  const historicOption = active.render().find((node) => node.type === 'option' && node.props.value === '1')
  assert.equal(historicOption.props.disabled, true)
  active.form.setValue('meter_code', 'OLD-METER')
  await active.submit()
  assert.equal(active.submitted.length, 1)
  assert.equal(active.submitted[0].service_id, 1)
  const inactive = await meterHarness([], { id: 5, service_id: 1, is_active: false, initial_reading: 0, service: catalog[0] })
  inactive.form.setValue('is_active', true)
  await inactive.submit()
  assert.equal(inactive.submitted.length, 0)
  assert.ok(inactive.form.formState.errors.service_id)
})
