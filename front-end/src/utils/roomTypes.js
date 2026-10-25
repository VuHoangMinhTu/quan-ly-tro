export const ROOM_TYPES = {
  PRIVATE_ROOM: 'PRIVATE_ROOM',
  DORMITORY: 'DORMITORY',
}

export const PRIVATE_ROOM_TYPES = {
  STUDIO: 'STUDIO',
  LOFT: 'LOFT',
  NO_LOFT: 'NO_LOFT',
  CLOSED_ROOM: 'CLOSED_ROOM',
}

export const roomTypeOptions = [
  { value: ROOM_TYPES.PRIVATE_ROOM, label: 'Phòng trọ' },
  { value: ROOM_TYPES.DORMITORY, label: 'Phòng KTX' },
]

export const privateRoomTypeOptions = [
  { value: PRIVATE_ROOM_TYPES.STUDIO, label: 'Studio' },
  { value: PRIVATE_ROOM_TYPES.LOFT, label: 'Có gác' },
  { value: PRIVATE_ROOM_TYPES.NO_LOFT, label: 'Không gác' },
  { value: PRIVATE_ROOM_TYPES.CLOSED_ROOM, label: 'Phòng kín' },
]

export const roomTypeLabel = (roomType) => roomTypeOptions.find((option) => option.value === roomType)?.label || 'Phòng trọ'
export const privateRoomTypeLabel = (privateRoomType) => privateRoomTypeOptions.find((option) => option.value === privateRoomType)?.label || 'Chưa phân loại'
