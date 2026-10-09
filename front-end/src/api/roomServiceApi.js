import client from './axiosClient'

export const getRoomServices = (roomId) => client.get(`/rooms/${roomId}/services`)
export const updateRoomServices = (roomId, payload) => client.put(`/rooms/${roomId}/services`, payload)
