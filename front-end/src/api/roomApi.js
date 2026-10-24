import client from './axiosClient'

export const getRoomsByBoardingHouse = (boardingHouseId) => client.get(`/boarding-houses/${boardingHouseId}/rooms`)
export const getRoom = (id) => client.get(`/rooms/${id}`)
export const reorderRooms = (boardingHouseId, floorId, roomIds) => client.put(`/boarding-houses/${boardingHouseId}/rooms/order`, { floor_id: floorId, room_ids: roomIds })
export const createRoom = (boardingHouseId, payload) => client.post(`/boarding-houses/${boardingHouseId}/rooms`, payload)
export const createRoomsBulk = (boardingHouseId, rooms, floorId = null) => client.post(`/boarding-houses/${boardingHouseId}/rooms/bulk`, { rooms, floor_id: floorId })
export const getFloors = (boardingHouseId) => client.get(`/boarding-houses/${boardingHouseId}/floors`)
export const createFloor = (boardingHouseId, payload) => client.post(`/boarding-houses/${boardingHouseId}/floors`, payload)
export const getBeds = (roomId) => client.get(`/rooms/${roomId}/beds`)
export const createBed = (roomId, payload) => client.post(`/rooms/${roomId}/beds`, payload)
export const updateBed = (id, payload) => client.put(`/beds/${id}`, payload)
export const updateRoom = (id, payload) => client.put(`/rooms/${id}`, payload)
export const deleteRoom = (id) => client.delete(`/rooms/${id}`)
