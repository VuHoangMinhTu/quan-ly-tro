import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { webcrypto } from 'node:crypto'
import { transformWithOxc } from 'vite'
import { boardingHouseKeys } from '../../api/boardingHouseKeys.js'
import { roomKeys } from '../../api/roomKeys.js'

async function wizardHarness() {
  const states = []
  let stateIndex = 0
  const calls = []
  const invalidated = []
  let navigation
  const source = (await readFile(new URL('../../pages/boarding-houses/BoardingHouseCreatePage.jsx', import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = {
    useState: (initial) => {
      const index = stateIndex++
      if (!(index in states)) states[index] = initial === false ? true : initial
      return [states[index], (value) => { states[index] = value }]
    },
    // Hydration/reload is covered by the real-browser test; this harness starts hydrated.
    useEffect: () => {},
    useRef: (initial) => {
      const index = stateIndex++
      if (!(index in states)) states[index] = { current: initial }
      return states[index]
    },
    crypto: webcrypto,
    sessionStorage: { setItem() {}, removeItem() {} },
    useNavigate: () => (...args) => { navigation = args },
    useQueryClient: () => ({ invalidateQueries: async ({ queryKey }) => { invalidated.push(queryKey) } }),
    useMutation: ({ mutationFn }) => ({ mutateAsync: mutationFn }),
    createBoardingHouseWithRooms: async (payload) => {
      calls.push(payload)
      return { data: { data: { id: 77, rooms_count: 2 }, message: 'Đã tạo nhà trọ và 2 phòng thành công.' } }
    },
    boardingHouseKeys, roomKeys,
    BoardingHouseForm: () => null, BulkRoomForm: () => null, Link: () => null,
  }
  const { code } = await transformWithOxc(source, 'BoardingHouseCreatePage.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), code + '\nreturn BoardingHouseCreatePage')(React, ...Object.values(bindings))
  const nodes = (node) => Array.isArray(node) ? node.flatMap(nodes) : React.isValidElement(node) ? [node, ...nodes(node.props.children)] : []
  const render = () => { stateIndex = 0; return nodes(Component()) }
  const houseForm = () => render().find((node) => node.type === bindings.BoardingHouseForm).props
  const roomsForm = () => render().find((node) => node.type === bindings.BulkRoomForm).props
  return { houseForm, roomsForm, calls, invalidated, navigation: () => navigation }
}

test('phase transition and back only keep drafts; final submit sends one atomic payload and opens the new house', async () => {
  const wizard = await wizardHarness()
  const house = { name: 'BeeHouse', address: 'Dĩ An', description: null }
  const drafts = [
    { room_code: '101', area: '20.5', monthly_rent: 3800000, max_tenants: '2' },
    { room_code: '102', area: '', monthly_rent: '', max_tenants: '' },
  ]
  await wizard.houseForm().onSubmit(house)
  assert.equal(wizard.calls.length, 0)
  const floors = [{ client_key: 'ground', name: 'Tầng trệt' }]
  wizard.roomsForm().onBack(drafts, floors)
  assert.deepEqual(wizard.houseForm().initialValues, { ...house, description: '' })
  await wizard.houseForm().onSubmit(house)
  assert.deepEqual(wizard.roomsForm().initialRooms, drafts)
  assert.deepEqual(wizard.roomsForm().initialFloors, floors)
  assert.equal(wizard.calls.length, 0)
  const rooms = [
    { room_code: '101', area: 20.5, monthly_rent: 3800000, max_tenants: 2 },
    { room_code: '102', area: null, monthly_rent: null, max_tenants: null },
  ]
  await wizard.roomsForm().onSubmit(rooms, [{ ...floors[0], sort_order: 0 }])
  assert.match(wizard.calls[0].idempotency_key, /^[0-9a-f-]{36}$/)
  const { idempotency_key, ...sent } = wizard.calls[0]
  assert.ok(idempotency_key)
  assert.deepEqual(sent, { boarding_house: house, rooms, floors: [{ ...floors[0], sort_order: 0 }] })
  assert.deepEqual(wizard.invalidated, [boardingHouseKeys.all, roomKeys.byBoardingHouse(77)])
  assert.deepEqual(wizard.navigation(), ['/boarding-houses/77', { replace: true, state: { message: 'Đã tạo nhà trọ và 2 phòng thành công.' } }])
})
