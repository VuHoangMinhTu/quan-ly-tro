import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { createFormControl } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { transformWithOxc } from 'vite'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage.js'
import { bulkRoomsSchema, emptyBulkRoom, generateRoomCodes, MAX_BULK_ROOMS } from '../../utils/roomOnboarding.js'
import { PRIVATE_ROOM_TYPES, privateRoomTypeOptions, roomTypeOptions, ROOM_TYPES } from '../../utils/roomTypes.js'

const nodes = (node) => Array.isArray(node) ? node.flatMap(nodes) : React.isValidElement(node) ? [node, ...nodes(node.props.children)] : []

async function renderBulkRooms(rooms) {
  let form
  const payloads = []
  const source = (await readFile(new URL('./BulkRoomForm.jsx', import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = {
    useState: (value) => [value, () => {}],
    useForm: (options) => {
      form = createFormControl(options)
      form.formState = { errors: {}, isSubmitting: false }
      return form
    },
    useFieldArray: () => ({ fields: rooms.map((room, index) => ({ ...room, id: 'row-' + index })), append: () => {}, remove: () => {} }),
    useWatch: () => form.getValues('rooms'),
    Controller: () => null,
    MoneyInput: () => null,
    zodResolver, applyApiFieldErrors, getApiErrorMessage,
    bulkRoomsSchema, emptyBulkRoom, generateRoomCodes, MAX_BULK_ROOMS,
    PRIVATE_ROOM_TYPES, privateRoomTypeOptions, roomTypeOptions, ROOM_TYPES,
  }
  const { code } = await transformWithOxc(source, 'BulkRoomForm.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), code + '\nreturn BulkRoomForm')(React, ...Object.values(bindings))
  const rendered = Component({ initialRooms: rooms, onBack: () => {}, onSubmit: async (payload) => payloads.push(payload) })
  return { nodes: nodes(rendered), payloads, submit: rendered.props.onSubmit }
}

test('responsive bulk cards and desktop table share a single registered input per field', async () => {
  const rendered = await renderBulkRooms([
    { room_code: '101', room_type: 'PRIVATE_ROOM', private_room_type: 'NO_LOFT', area: '20.5', monthly_rent: 3800000, max_tenants: '2' },
    { room_code: '102', room_type: 'DORMITORY', private_room_type: null, area: '', monthly_rent: '', max_tenants: '' },
  ])
  for (const index of [0, 1]) {
    for (const field of ['room_code', 'area', 'monthly_rent', 'max_tenants']) {
      assert.equal(rendered.nodes.filter((node) => node.props.name === 'rooms.' + index + '.' + field).length, 1)
    }
  }
  const table = rendered.nodes.find((node) => node.type === 'table')
  assert.match(table.props.className, /\bblock\b/)
  assert.match(table.props.className, /md:table/)
  assert.match(table.props.className, /md:min-w-\[700px\]/)
  const rows = rendered.nodes.filter((node) => node.type === 'tr' && node.key?.startsWith('row-'))
  assert.equal(rows.length, 2)
  rows.forEach((row) => assert.match(row.props.className, /md:table-row/))
  assert.equal(rendered.nodes.filter((node) => node.type === 'label' && node.props.htmlFor).length, 13)

  await rendered.submit({ preventDefault() {}, persist() {} })
  assert.deepEqual(rendered.payloads, [[
    { room_code: '101', room_type: 'PRIVATE_ROOM', private_room_type: 'NO_LOFT', area: 20.5, monthly_rent: 3800000, max_tenants: 2 },
    { room_code: '102', room_type: 'DORMITORY', private_room_type: null, area: null, monthly_rent: null, max_tenants: null },
  ]])
})
