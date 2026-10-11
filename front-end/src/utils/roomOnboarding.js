import { z } from 'zod'

export const MAX_BULK_ROOMS = 200
export const emptyBulkRoom = (roomCode = '') => ({ room_code: roomCode, area: '', monthly_rent: '', max_tenants: '' })

const optionalNumber = (label, maximum, integer = false) => z.preprocess(
  (value) => value == null || value === '' ? null : Number(value),
  (integer ? z.number({ error: label + ' phải là số hợp lệ.' }).int(label + ' phải là số nguyên.') : z.number({ error: label + ' phải là số hợp lệ.' }))
    .min(integer ? 1 : 0, label + (integer ? ' phải ít nhất là 1.' : ' phải từ 0 trở lên.'))
    .max(maximum, label + ' vượt giới hạn cho phép.').nullable(),
)

export const bulkRoomsSchema = z.object({
  rooms: z.array(z.object({
    room_code: z.string().trim().min(1, 'Vui lòng nhập mã phòng.').max(50, 'Mã phòng tối đa 50 ký tự.'),
    area: optionalNumber('Diện tích', 99999999.99),
    monthly_rent: optionalNumber('Giá thuê', 9999999999999.99),
    max_tenants: optionalNumber('Số người tối đa', 4294967295, true),
  })).min(1, 'Vui lòng thêm ít nhất một phòng.').max(MAX_BULK_ROOMS, 'Mỗi lần chỉ được tạo tối đa 200 phòng.'),
}).superRefine(({ rooms }, context) => {
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
})

export function generateRoomCodes(from, to) {
  const start = String(from).trim()
  const end = String(to).trim()
  if (!/^\d+$/.test(start) || !/^\d+$/.test(end)) throw new Error('Vui lòng nhập mã bắt đầu và kết thúc bằng số.')
  const first = Number(start)
  const last = Number(end)
  if (!Number.isSafeInteger(first) || !Number.isSafeInteger(last) || last < first) throw new Error('Khoảng mã phòng không hợp lệ.')
  if (last - first + 1 > MAX_BULK_ROOMS) throw new Error('Mỗi lần chỉ được tạo tối đa 200 phòng.')
  const width = start.startsWith('0') ? start.length : 0
  return Array.from({ length: last - first + 1 }, (_, index) => emptyBulkRoom(String(first + index).padStart(width, '0')))
}