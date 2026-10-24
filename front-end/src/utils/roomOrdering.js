// Preserve backend order, including floors whose names happen to be numeric.
export function groupRoomsByFloor(rooms) {
  const groups = new Map()
  for (const room of rooms) {
    const key = room.floor_id == null ? 'none' : String(room.floor_id)
    if (!groups.has(key)) groups.set(key, { key, floorId: room.floor_id ?? null, name: room.floor?.name || 'Chưa thiết lập tầng', rooms: [] })
    groups.get(key).rooms.push(room)
  }
  return [...groups.values()]
}

export function moveRoom(rooms, index, direction) {
  const destination = index + direction
  if (index < 0 || index >= rooms.length || destination < 0 || destination >= rooms.length) return rooms
  const result = [...rooms]
  ;[result[index], result[destination]] = [result[destination], result[index]]
  return result
}
