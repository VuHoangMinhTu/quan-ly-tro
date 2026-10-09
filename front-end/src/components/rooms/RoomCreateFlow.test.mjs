import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { createFormControl } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { transformWithOxc } from 'vite'
import { roomKeys } from '../../api/roomKeys.js'
import { boardingHouseKeys } from '../../api/boardingHouseKeys.js'
import { contractKeys } from '../../api/contractKeys.js'
import { invoiceKeys } from '../../api/invoiceKeys.js'

// Exercise the real component handlers and RHF/Zod resolver without a browser
// dependency. Query/router hooks are isolated; no API or production DB is used.
async function loadComponent(path, bindings) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '')
    .replace('export default function ', 'function ')
  const name = source.match(/function (RoomForm|RoomCreatePage|RoomDetailPage)\(/)[1]
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}

function elements(node) {
  if (Array.isArray(node)) return node.flatMap(elements)
  if (!React.isValidElement(node)) return []
  if (typeof node.type === 'function') return elements(node.type(node.props))
  return [node, ...elements(node.props.children)]
}

const catalog = [{ id: '1', name: 'Bàn ghế' }, { id: '2', name: 'Máy lạnh' }]

async function roomFormHarness(query = { data: { data: { data: catalog } } }, initialValues) {
  let form
  let previousDependencies
  let submitError = ''
  const submitted = []
  const RoomForm = await loadComponent('./RoomForm.jsx', {
    useState: () => [submitError, (value) => { submitError = value }],
    useEffect: (effect, dependencies) => {
      if (!previousDependencies || dependencies.some((value, index) => value !== previousDependencies[index])) {
        previousDependencies = dependencies
        effect()
      }
    },
    useQuery: () => query,
    useForm: (options) => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    },
    Controller: ({ name, render }) => render({ field: {
      name, value: form.getValues(name), ref: () => {}, onBlur: () => {},
      onChange: (value) => form.setValue(name, value),
    } }),
    zodResolver,
    z,
    getAmenities: () => {},
    getApiErrorMessage: () => 'API error',
    roomKeys,
    MoneyInput: (props) => React.createElement('input', props),
  })
  const props = { initialValues, onSubmit: async (payload) => { submitted.push(payload) }, submitLabel: 'Tạo phòng' }
  const render = () => elements(RoomForm(props))
  render()
  form.setValue('room_code', 'P001')
  form.setValue('monthly_rent', 2000000)
  const toggle = (id, checked) => {
    const input = render().find((node) => node.type === 'input' && node.props.type === 'checkbox' && node.props.value === id)
    assert.ok(input)
    input.props.onChange({ target: { checked } })
  }
  const submit = async () => render().find((node) => node.type === 'form').props.onSubmit()
  return { form, props, query, render, toggle, submit, submitted }
}

test('loaded catalog does not require any amenity to create a room', async () => {
  const harness = await roomFormHarness()
  await harness.submit()
  assert.equal(harness.submitted.length, 1)
  assert.deepEqual(harness.submitted[0].amenity_ids, [])
  assert.equal(harness.submitted[0].monthly_rent, 2000000)
})

test('selecting two amenities sends numeric IDs through real RHF/Zod validation', async () => {
  const harness = await roomFormHarness()
  harness.toggle(1, true)
  harness.toggle(2, true)
  await harness.submit()
  assert.equal(harness.submitted.length, 1)
  assert.deepEqual(harness.submitted[0].amenity_ids, [1, 2])
})

test('deselecting every amenity still submits an empty array', async () => {
  const harness = await roomFormHarness()
  harness.toggle(1, true)
  harness.toggle(2, true)
  harness.toggle(1, false)
  harness.toggle(2, false)
  await harness.submit()
  assert.equal(harness.submitted.length, 1)
  assert.deepEqual(harness.submitted[0].amenity_ids, [])
})

test('pending or failed amenities query does not disable room submission', async () => {
  for (const query of [{ isPending: true }, { isError: true }]) {
    const harness = await roomFormHarness(query)
    assert.equal(harness.render().find((node) => node.type === 'button').props.disabled, false)
    await harness.submit()
    assert.equal(harness.submitted.length, 1)
    assert.deepEqual(harness.submitted[0].amenity_ids, [])
  }
})

test('catalog arriving or refetching does not reset the room fields or selection', async () => {
  const harness = await roomFormHarness({ isPending: true })
  harness.form.setValue('room_code', 'P002')
  Object.assign(harness.query, { isPending: false, data: { data: { data: catalog } } })
  harness.toggle(2, true)
  harness.query.data = { data: { data: [...catalog] } }
  assert.equal(harness.form.getValues('room_code'), 'P002')
  assert.equal(harness.render().find((node) => node.props.type === 'checkbox' && node.props.value === 2).props.checked, true)
  await harness.submit()
  assert.equal(harness.submitted[0].room_code, 'P002')
  assert.deepEqual(harness.submitted[0].amenity_ids, [2])
})

test('edit values normalize string IDs and changing rooms resets amenity selection', async () => {
  const harness = await roomFormHarness(undefined, { room_code: 'P001', monthly_rent: 2000000, amenities: [{ id: '2' }] })
  assert.equal(harness.render().find((node) => node.props.type === 'checkbox' && node.props.value === 2).props.checked, true)
  harness.props.initialValues = { room_code: 'P002', monthly_rent: 2000000, amenities: [] }
  harness.render()
  assert.deepEqual(harness.form.getValues('amenity_ids'), [])
})

test('create caches the returned room with amenities, invalidates its list and opens RoomDetail', async () => {
  const payload = { room_code: 'P001', monthly_rent: 2000000, status: 'AVAILABLE', amenity_ids: [1, 2] }
  const response = { data: { success: true, data: { id: 42, boarding_house_id: 7, amenities: catalog } } }
  const cache = new Map()
  const invalidated = []
  let navigation
  const RoomCreatePage = await loadComponent('../../pages/rooms/RoomCreatePage.jsx', {
    useParams: () => ({ boardingHouseId: '7' }),
    useNavigate: () => (...args) => { navigation = args },
    useQueryClient: () => ({
      setQueryData: (key, value) => cache.set(JSON.stringify(key), value),
      invalidateQueries: async ({ queryKey }) => { invalidated.push(queryKey) },
    }),
    useQuery: () => ({ data: { data: { data: { name: 'Nhà trọ' } } } }),
    useMutation: ({ mutationFn }) => ({ mutateAsync: mutationFn }),
    createRoom: async (boardingHouseId, values) => {
      assert.equal(boardingHouseId, '7')
      assert.deepEqual(values, payload)
      return response
    },
    getBoardingHouse: () => {},
    boardingHouseKeys,
    roomKeys,
    getApiErrorMessage: () => '',
    RoomForm: (props) => React.createElement('form', props),
    Link: ({ children }) => React.createElement('a', {}, children),
  })
  await elements(RoomCreatePage()).find((node) => node.type === 'form').props.onSubmit(payload)
  assert.deepEqual(invalidated, [roomKeys.byBoardingHouse('7')])
  assert.equal(cache.get(JSON.stringify(roomKeys.detail('42'))), response)
  assert.deepEqual(cache.get(JSON.stringify(roomKeys.detail('42'))).data.data.amenities, catalog)
  assert.deepEqual(navigation, ['/rooms/42', { replace: true, state: { message: 'Tạo phòng thành công.' } }])

  const RoomDetailPage = await loadComponent('../../pages/rooms/RoomDetailPage.jsx', {
    useState: () => [false, () => {}],
    useParams: () => ({ id: '42' }),
    useNavigate: () => () => {},
    useLocation: () => ({ state: {} }),
    useQueryClient: () => ({}),
    useQuery: ({ queryKey }) => ({ data: cache.get(JSON.stringify(queryKey)) || { data: { data: [] } } }),
    useMutation: () => ({}),
    roomKeys, contractKeys, invoiceKeys,
    getRoom: () => {}, deleteRoom: () => {}, getContractsByRoom: () => {}, getInvoicesByRoom: () => {},
    getApiErrorMessage: () => '',
    formatCurrency: String, formatArea: String, formatDate: String,
    Pencil: () => null, Trash2: () => null, AmenityIcon: () => null,
    ContractStatusBadge: () => null, RoomOccupantsSection: () => null,
    RoomUtilitiesSection: () => null, RoomServicesSection: () => null, RoomAmenitiesModal: () => null, InvoiceStatusBadge: () => null,
    Link: ({ children }) => React.createElement('a', {}, children),
  })
  const displayedAmenities = elements(RoomDetailPage())
    .filter((node) => node.type === 'span' && catalog.some((amenity) => node.props.children === amenity.name))
    .map((node) => node.props.children)
  assert.deepEqual(displayedAmenities, ['Bàn ghế', 'Máy lạnh'])
})
