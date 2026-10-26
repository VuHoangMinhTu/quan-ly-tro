import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { boardingHouseKeys } from '../../api/boardingHouseKeys.js'
import { roomKeys } from '../../api/roomKeys.js'
import { tenantKeys } from '../../api/tenantKeys.js'
import { utilityKeys } from '../../api/utilityKeys.js'
import { serviceKeys } from '../../api/serviceKeys.js'
import { contractKeys } from '../../api/contractKeys.js'
import { invoiceKeys } from '../../api/invoiceKeys.js'

// These component checks complement the browser viewport matrix. They protect
// the shared mobile/desktop handlers without calling an API or adding a dependency.
async function loadComponent(path, bindings = {}) {
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

function text(node) {
  if (Array.isArray(node)) return node.map(text).join('')
  if (React.isValidElement(node)) return text(node.props.children)
  return node == null || typeof node === 'boolean' ? '' : String(node)
}

const Icon = () => React.createElement('svg', { 'aria-hidden': true })
const Link = ({ to, children, ...props }) => React.createElement('a', { href: to, ...props }, children)
const queryClient = { invalidateQueries: () => {}, removeQueries: () => {} }

test('room amenities dialog and service section keep distinct room-scoped React keys', async () => {
  const Services = () => null
  const Amenities = () => null
  let room = { id: 1, boarding_house_id: 1, room_code: 'P101', amenities: [] }
  let stateIndex = 0
  const Component = await loadComponent('../../pages/rooms/RoomDetailPage.jsx', {
    useState: (initial) => [stateIndex++ === 3 ? true : initial, () => {}],
    useParams: () => ({ id: String(room.id) }),
    useNavigate: () => () => {}, useLocation: () => ({}),
    useQueryClient: () => queryClient,
    useQuery: ({ queryKey }) => ({ data: { data: { data: queryKey[0] === 'room' ? room : [] } } }),
    useMutation: () => ({ isPending: false }),
    roomKeys, contractKeys, invoiceKeys, getApiErrorMessage: String,
    getRoom: () => {}, getContractsByRoom: () => {}, getInvoicesByRoom: () => {}, deleteRoom: () => {},
    Link, Pencil: Icon, Trash2: Icon, AmenityIcon: Icon,
    RoomServicesSection: Services, RoomAmenitiesModal: Amenities,
    RoomOccupantsSection: Icon, RoomUtilitiesSection: Icon,
    ContractStatusBadge: Icon, InvoiceStatusBadge: Icon,
    formatArea: String, formatCurrency: String, formatDate: String,
  })
  for (const id of [1, 2]) {
    room = { ...room, id }
    stateIndex = 0
    const children = Component().props.children.filter(React.isValidElement)
    const services = children.find((element) => element.type === Services)
    const amenities = children.find((element) => element.type === Amenities)
    assert.notEqual(services.key, amenities.key)
    assert.equal(services.key, `room-services-${id}`)
    assert.equal(amenities.key, `room-amenities-${id}`)
    assert.equal(amenities.props.room.id, id)
  }
})

test('room card handles long labels without fixed width and keeps null rent distinct from zero', async () => {
  const Component = await loadComponent('./RoomCard.jsx', {
    Users: Icon, formatArea: (value) => value == null ? 'Chưa thiết lập' : `${value} m²`,
    formatCurrency: (value) => `${value} đ`,
  })
  const room = { room_code: 'P001'.repeat(20), room_name: 'Tên phòng có tiếng Việt rất dài', status: 'AVAILABLE', monthly_rent: null, area: null, max_tenants: null }
  const empty = Component({ room })
  assert.match(empty.props.className, /min-w-0/)
  assert.ok(elements(empty).some((element) => element.props.className?.includes('break-words')))
  assert.ok(elements(empty).some((element) => element.props.className?.includes('flex-wrap')))
  assert.match(text(empty), /Chưa thiết lập giá thuê/)
  assert.doesNotMatch(text(empty), /0 đ/)
  assert.match(text(Component({ room: { ...room, monthly_rent: 0 } })), /0 đ/)
})

test('tenant mobile cards and desktop table reuse tenant actions and delete the same record', async () => {
  const state = []
  const deleted = []
  let stateIndex = 0
  let inputId = 0
  const tenant = { id: 42, full_name: 'Nguyễn Văn A', phone: '0385123456', email: 'long@email.example', current_residence: { boarding_house_name: 'BeeHouse Dĩ An', room_code: '101' } }
  const Component = await loadComponent('../../pages/tenants/TenantListPage.jsx', {
    useState: (initial) => {
      const index = stateIndex++
      if (!(index in state)) state[index] = typeof initial === 'function' ? initial() : initial
      return [state[index], (next) => { state[index] = typeof next === 'function' ? next(state[index]) : next }]
    },
    useEffect: () => {}, useCallback: (callback) => callback, useId: () => `filter-${inputId++}`,
    useRef: (current) => ({ current }), useSearchParams: () => [new URLSearchParams(), () => {}],
    useLocation: () => ({}), useQueryClient: () => ({ ...queryClient, getQueryData: () => ({ data: { data: { landlord: { id: 1 } } } }) }),
    useQuery: ({ queryKey }) => ({ data: { data: { data: queryKey[0] === 'tenants' ? [tenant] : [] } } }),
    useMutation: () => ({ isPending: false, mutate: (id) => deleted.push(id) }), keepPreviousData: () => {},
    boardingHouseKeys, roomKeys, tenantKeys, getApiErrorMessage: () => 'API error',
    getBoardingHouses: () => {}, getRoomsByBoardingHouse: () => {}, getTenants: () => {}, deleteTenant: () => {},
    formatDate: (value) => value || '—', Eye: Icon, Pencil: Icon, Plus: Icon, Search: Icon, Trash2: Icon, X: Icon, Link,
  })
  const render = () => { stateIndex = 0; return elements(Component()) }
  const nodes = render()
  assert.ok(nodes.some((element) => element.type === 'button' && text(element) === 'Import Excel'))
  const mobileList = nodes.find((element) => element.props.className === 'space-y-3 md:hidden')
  const desktopTable = nodes.find((element) => element.props.className?.includes('hidden overflow-x-auto') && element.props.className.includes('md:block'))
  assert.ok(mobileList)
  assert.ok(desktopTable)
  assert.match(text(mobileList), /Nguyễn Văn A/)
  assert.match(text(mobileList), /BeeHouse Dĩ An · Phòng 101/)
  const mobileActions = elements(mobileList)
  assert.equal(mobileActions.find((element) => element.type === 'a' && text(element).trim() === 'Xem').props.href, '/tenants/42')
  const remove = mobileActions.find((element) => element.type === 'button' && text(element).trim() === 'Xóa')
  assert.match(remove.props.className, /min-h-11/)
  remove.props.onClick()
  const confirmingMobileList = render().find((element) => element.props.className === 'space-y-3 md:hidden')
  elements(confirmingMobileList).find((element) => element.type === 'button' && text(element) === 'Xác nhận').props.onClick()
  assert.deepEqual(deleted, [42])
})

test('utility reading dialog is viewport-bounded and its close target remains accessible', async () => {
  let closed = false
  const Component = await loadComponent('./UtilityReadingModal.jsx', {
    useQueryClient: () => queryClient, useQuery: () => ({ data: { data: { data: [] } } }),
    useMutation: () => ({ isPending: false }), utilityKeys, roomKeys,
    getUtilityReadings: () => {}, createUtilityReading: () => {}, getApiErrorMessage: () => 'API error',
    formatDate: (value) => value, formatUtilityValue: String, formatMeterPrice: () => '3.500 đ / kWh', getReadingPreview: () => ({}),
    UtilityReadingForm: () => React.createElement('form'),
  })
  const nodes = elements(Component({ roomId: 1, meter: { id: 10, initial_reading: 0, service: { name: 'Điện nhà trọ', type: 'ELECTRICITY', unit: 'kWh' } }, onClose: () => { closed = true } }))
  const panel = nodes.find((element) => element.props.className?.includes('max-h-[90dvh]'))
  assert.match(panel.props.className, /overflow-y-auto/)
  assert.match(panel.props.className, /p-4 sm:p-6/)
  const close = nodes.find((element) => element.type === 'button' && element.props['aria-label'] === 'Đóng')
  assert.match(close.props.className, /size-11/)
  close.props.onClick()
  assert.equal(closed, true)
})

test('service editor dialog scrolls internally instead of growing beyond mobile viewport', async () => {
  const Component = await loadComponent('../../pages/services/ServiceListPage.jsx', {
    useState: (initial) => [initial === null ? {} : initial, () => {}],
    useQueryClient: () => queryClient, useQuery: () => ({ data: { data: { data: [] } } }),
    useMutation: () => ({ isPending: false }), useParams: () => ({ boardingHouseId: '7' }),
    serviceKeys, Link, ServiceForm: () => React.createElement('form'), MoneyInput: () => React.createElement('input'),
    getApiErrorMessage: () => 'API error', getApiFieldErrors: () => ({}), formatCurrency: String,
    BILLING_METHOD_LABELS: {}, SERVICE_TYPE_LABELS: {},
    createService: () => {}, deleteService: () => {}, getServices: () => {}, updateService: () => {},
    createServicePriceTier: () => {}, deleteServicePriceTier: () => {}, getServicePriceTiers: () => {},
  })
  const panel = elements(Component()).find((element) => element.props.className?.includes('max-h-[90dvh]'))
  assert.ok(panel)
  assert.match(panel.props.className, /overflow-y-auto/)
  assert.match(panel.props.className, /max-w-lg/)
})
