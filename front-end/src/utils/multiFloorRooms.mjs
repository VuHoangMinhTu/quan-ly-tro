export const MAX_MULTI_ROOMS = 200
const normalize = value => String(value ?? '').trim().toLocaleLowerCase('vi-VN')

// Read-only preview. Existing and draft codes reserve their sequence numbers,
// including a matching code stored under another floor: codes are house-wide.
export function planMultiFloorRooms({ from, to, count, prefix = '', draftFloors = [], existingFloors = [], draftRooms = [], existingRooms = [], makeKey }) {
  const numbers = [from, to, count].map(value => String(value).trim())
  if (numbers.some(value => !/^\d+$/.test(value))) throw new Error('Nhập số tầng và số phòng bằng số nguyên không âm.')
  const [first, last, perFloor] = numbers.map(Number)
  if (![first, last, perFloor].every(Number.isSafeInteger) || first > last || perFloor < 1 || perFloor > 99) throw new Error('Khoảng tầng không hợp lệ hoặc số phòng mỗi tầng ngoài 1–99.')
  const total = (last - first + 1) * perFloor
  if (total > MAX_MULTI_ROOMS || draftRooms.length + total > MAX_MULTI_ROOMS) throw new Error('Mỗi lần chỉ được tạo tối đa 200 phòng.')
  const cleanPrefix = String(prefix).trim()
  if (cleanPrefix.length + String(last).length + 2 > 50) throw new Error('Mã phòng tối đa 50 ký tự.')
  const taken = new Set([...draftRooms, ...existingRooms].map(room => normalize(room.room_code)))
  const floors = draftFloors.map(floor => ({ ...floor }))
  const rooms = []
  for (let offset = 0; offset <= last - first; offset++) {
    const number = first + offset
    const name = `Tầng ${number}`
    const existing = existingFloors.filter(item => normalize(item.name) === normalize(name))
    const drafts = floors.filter(item => normalize(item.name) === normalize(name))
    if (existing.length > 1 || drafts.length > 1) throw new Error(`Tên ${name} không xác định duy nhất. Vui lòng kiểm tra danh sách tầng.`)
    let floor = drafts[0]
    if (!floor) {
      floor = { client_key: makeKey(), name: existing[0]?.name ?? name }
      floors.push(floor)
    }
    const stem = `${cleanPrefix}${number}`
    const canonicalStem = normalize(stem)
    let highest = 0
    for (const code of taken) {
      if (!code.startsWith(canonicalStem)) continue
      const suffix = code.slice(canonicalStem.length)
      if (/^\d{2}$/.test(suffix)) highest = Math.max(highest, Number(suffix))
    }
    if (highest + perFloor > 99) throw new Error(`${name}: không đủ mã nối tiếp trong khoảng 01–99 với tiền tố này. Chọn tiền tố khác hoặc thêm phòng thủ công.`)
    for (let index = highest + 1; index <= highest + perFloor; index++) {
      const code = `${stem}${String(index).padStart(2, '0')}`
      if (taken.has(normalize(code))) throw new Error(`Mã phòng ${code} đã tồn tại hoặc đã có trong danh sách.`)
      taken.add(normalize(code))
      rooms.push({ room_code: code, floor_client_key: floor.client_key })
    }
  }
  if (floors.length > 200) throw new Error('Mỗi lần chỉ được khai báo tối đa 200 tầng.')
  return { floors, rooms }
}
