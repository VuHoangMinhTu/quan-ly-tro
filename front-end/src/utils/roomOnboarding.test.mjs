import assert from 'node:assert/strict'
import test from 'node:test'
import { bulkRoomsSchema, emptyBulkRoom, generateRoomCodes } from './roomOnboarding.js'

test('generator only creates codes and empty editable fields', () => {
  const rooms = generateRoomCodes('101', '110')
  assert.equal(rooms.length, 10)
  assert.deepEqual(rooms[0], { room_code: '101', area: '', monthly_rent: '', max_tenants: '' })
  assert.equal(rooms[9].room_code, '110')
  assert.deepEqual(bulkRoomsSchema.parse({ rooms }).rooms[0], { room_code: '101', area: null, monthly_rent: null, max_tenants: null })
})

test('mixed prices, decimal area and zero survive numeric payload normalization', () => {
  const result = bulkRoomsSchema.parse({ rooms: [
    { room_code: '101', area: '20.5', monthly_rent: 3800000, max_tenants: '2' },
    emptyBulkRoom('102'),
    { room_code: '103', area: '0', monthly_rent: 0, max_tenants: '' },
  ] })
  assert.deepEqual(result.rooms[0], { room_code: '101', area: 20.5, monthly_rent: 3800000, max_tenants: 2 })
  assert.equal(result.rooms[1].monthly_rent, null)
  assert.equal(result.rooms[2].monthly_rent, 0)
  assert.equal(result.rooms[2].area, 0)
})

test('duplicates are reported against both rows, ignoring whitespace and case', () => {
  const result = bulkRoomsSchema.safeParse({ rooms: [emptyBulkRoom(' p101 '), emptyBulkRoom('P101')] })
  assert.equal(result.success, false)
  assert.deepEqual(result.error.issues.map((issue) => issue.path), [['rooms', 0, 'room_code'], ['rooms', 1, 'room_code']])
})

test('required code, invalid numeric fields and empty batch prevent submit', () => {
  for (const room of [
    emptyBulkRoom(),
    { ...emptyBulkRoom('101'), area: -1 },
    { ...emptyBulkRoom('101'), monthly_rent: -1 },
    { ...emptyBulkRoom('101'), max_tenants: 1.5 },
  ]) {
    assert.equal(bulkRoomsSchema.safeParse({ rooms: [room] }).success, false)
  }
  assert.equal(bulkRoomsSchema.safeParse({ rooms: [] }).success, false)
})

test('generator rejects invalid or excessive ranges and preserves leading zeros', () => {
  assert.throws(() => generateRoomCodes('', '110'))
  assert.throws(() => generateRoomCodes('110', '101'))
  assert.throws(() => generateRoomCodes('1', '201'))
  assert.throws(() => generateRoomCodes('1', 'a'))
  assert.equal(generateRoomCodes('001', '010')[0].room_code, '001')
})