import { z } from 'zod'
import { numberInput } from './formValidation.js'
import { PRIVATE_ROOM_TYPES, ROOM_TYPES } from './roomTypes.js'

export const MAX_BULK_ROOMS = 200
export const emptyBulkRoom = (roomCode = '', roomType = ROOM_TYPES.PRIVATE_ROOM, privateRoomType = PRIVATE_ROOM_TYPES.NO_LOFT) => ({
  room_code: roomCode,
  room_type: roomType,
  private_room_type: roomType === ROOM_TYPES.DORMITORY ? null : privateRoomType,
  area: '',
  monthly_rent: '',
  max_tenants: '',
})

const optionalNumber = (label, maximum, integer = false) => numberInput(label, { optional: true, min: integer ? 1 : 0, max: maximum, integer })

export const bulkRoomsSchema = z.object({
  floors: z.array(z.object({
    client_key: z.string().min(1).max(100),
    name: z.string().trim().min(1, 'Vui lòng nhập tên tầng.').max(100, 'Tên tầng tối đa 100 ký tự.'),
  })).max(200, 'Mỗi lần chỉ được tạo tối đa 200 tầng.').optional(),
  rooms: z.array(z.object({
    floor_client_key: z.string().nullable().optional(),
    room_code: z.string().trim().min(1, 'Vui lòng nhập mã phòng.').max(50, 'Mã phòng tối đa 50 ký tự.'),
    room_type: z.enum([ROOM_TYPES.PRIVATE_ROOM, ROOM_TYPES.DORMITORY]),
    private_room_type: z.enum([PRIVATE_ROOM_TYPES.STUDIO, PRIVATE_ROOM_TYPES.LOFT, PRIVATE_ROOM_TYPES.NO_LOFT, PRIVATE_ROOM_TYPES.CLOSED_ROOM]).nullable(),
    area: optionalNumber('Diện tích', 99999999.99),
    monthly_rent: optionalNumber('Giá thuê', 9999999999999.99),
    max_tenants: optionalNumber('Số người tối đa', 4294967295, true),
  })).min(1, 'Vui lòng thêm ít nhất một phòng.').max(MAX_BULK_ROOMS, 'Mỗi lần chỉ được tạo tối đa 200 phòng.'),
}).superRefine(({ rooms, floors }, context) => {
  if (floors) {
    const names = new Map()
    floors.forEach((floor, index) => {
      const name = floor.name.toLocaleLowerCase('vi-VN')
      if (names.has(name)) {
        for (const duplicate of [names.get(name), index]) context.addIssue({ code: 'custom', path: ['floors', duplicate, 'name'], message: 'Tên tầng bị trùng trong danh sách.' })
      } else names.set(name, index)
    })
    rooms.forEach((room, index) => {
      if (room.floor_client_key && !floors.some(floor => floor.client_key === room.floor_client_key)) context.addIssue({ code: 'custom', path: ['rooms', index, 'floor_client_key'], message: 'Vui lòng chọn lại tầng cho phòng.' })
    })
  }
  const indexes = new Map()
  rooms.forEach((room, index) => {
    const code = room.room_code.trim().toLocaleLowerCase('vi-VN')
    if (!code) return
    if (indexes.has(code)) {
      for (const duplicateIndex of [indexes.get(code), index]) context.addIssue({
        code: 'custom', path: ['rooms', duplicateIndex, 'room_code'], message: 'Mã phòng bị trùng trong danh sách.',
      })
    } else indexes.set(code, index)
  })
  rooms.forEach((room, index) => {
    if (room.room_type === ROOM_TYPES.DORMITORY && room.private_room_type !== null) {
      context.addIssue({ code: 'custom', path: ['rooms', index, 'private_room_type'], message: 'Phòng KTX không có phân loại phòng trọ.' })
    }
  })
})

export function generateRoomCodes(from, to, roomType = ROOM_TYPES.PRIVATE_ROOM, privateRoomType = PRIVATE_ROOM_TYPES.NO_LOFT) {
  const start = String(from).trim()
  const end = String(to).trim()
  if (!/^\d+$/.test(start) || !/^\d+$/.test(end)) throw new Error('Vui lòng nhập mã bắt đầu và kết thúc bằng số.')
  const first = Number(start)
  const last = Number(end)
  if (!Number.isSafeInteger(first) || !Number.isSafeInteger(last) || last < first) throw new Error('Khoảng mã phòng không hợp lệ.')
  if (last - first + 1 > MAX_BULK_ROOMS) throw new Error('Mỗi lần chỉ được tạo tối đa 200 phòng.')
  const width = start.startsWith('0') ? start.length : 0
  return Array.from({ length: last - first + 1 }, (_, index) => emptyBulkRoom(String(first + index).padStart(width, '0'), roomType, privateRoomType))
}
