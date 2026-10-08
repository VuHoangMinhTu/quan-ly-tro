import client from './axiosClient'

export const getRoomsByBoardingHouse = (boardingHouseId) => client.get(`/boarding-houses/${boardingHouseId}/rooms`)
export const getRoom = (id) => client.get(`/rooms/${id}`)
export const createRoom = (boardingHouseId, payload) => client.post(`/boarding-houses/${boardingHouseId}/rooms`, payload)
export const updateRoom = (id, payload) => client.put(`/rooms/${id}`, payload)
export const deleteRoom = (id) => client.delete(`/rooms/${id}`)
