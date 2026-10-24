import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { groupRoomsByFloor, moveRoom } from '../../utils/roomOrdering.js'
import { roomKeys } from '../../api/roomKeys.js'

function nodes(node) {
  if (Array.isArray(node)) return node.flatMap(nodes)
  if (!React.isValidElement(node)) return []
  return [node, ...nodes(node.props.children)]
}

test('floor groups preserve backend order with numeric names and repeated names', () => {
  const rooms = [
    { id: 3, floor_id: 20, floor: { name: '10' } },
    { id: 1, floor_id: 10, floor: { name: '2' } },
    { id: 2, floor_id: 30, floor: { name: '2' } },
    { id: 4, floor_id: null },
  ]
  assert.deepEqual(groupRoomsByFloor(rooms).map(group => group.floorId), [20, 10, 30, null])
  assert.deepEqual(groupRoomsByFloor(rooms).flatMap(group => group.rooms.map(room => room.id)), [3, 1, 2, 4])
})

test('up/down moves preserve source data and reject movement past boundaries', () => {
  const rooms = [{ id: 1 }, { id: 2 }, { id: 3 }]
  assert.deepEqual(moveRoom(rooms, 2, -1).map(room => room.id), [1, 3, 2])
  assert.deepEqual(moveRoom(rooms, 0, 1).map(room => room.id), [2, 1, 3])
  assert.deepEqual(rooms.map(room => room.id), [1, 2, 3])
  assert.equal(moveRoom(rooms, 0, -1), rooms)
  assert.equal(moveRoom(rooms, 2, 1), rooms)
})

test('arrow buttons save only the selected floor and refetch both room list caches', async () => {
  let rows
  let mutation
  let closed = false
  const requests = []
  const invalidated = []
  const bindings = {
    useState: initial => {
      rows ??= initial
      return [rows, value => { rows = typeof value === 'function' ? value(rows) : value }]
    },
    useQueryClient: () => ({ invalidateQueries: async ({ queryKey }) => invalidated.push(queryKey) }),
    useMutation: options => {
      mutation = options
      return { isPending: false, mutate: async () => { await mutation.mutationFn(); await mutation.onSuccess() } }
    },
    reorderRooms: async (...args) => requests.push(args),
    roomKeys, moveRoom, getApiErrorMessage: () => '',
  }
  const source = (await readFile(new URL('./RoomOrderButton.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'RoomOrderButton.jsx', { jsx: { runtime: 'classic' } })
  const Group = new Function('React', ...Object.keys(bindings), `${code}\nreturn RoomOrderGroup`)(React, ...Object.values(bindings))
  const props = { group: { floorId: 8, rooms: [{ id: 1, room_code: 'P1' }, { id: 2, room_code: 'P2' }] }, boardingHouseId: '5', onClose: () => { closed = true } }
  const render = () => nodes(Group(props))
  assert.equal(render().find(node => node.props['aria-label'] === 'Đưa phòng P1 lên').props.disabled, true)
  render().find(node => node.props['aria-label'] === 'Đưa phòng P2 lên').props.onClick()
  await render().find(node => node.type === 'button' && node.props.children === 'Lưu thứ tự').props.onClick()
  assert.deepEqual(requests, [['5', 8, [2, 1]]])
  assert.deepEqual(invalidated, [['rooms', '5'], ['residence-rooms', '5']])
  assert.equal(closed, true)
})
