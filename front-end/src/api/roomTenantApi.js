import client from './axiosClient'
export const getRoomTenants = (roomId) => client.get(`/rooms/${roomId}/tenants`)
export const addRoomTenant = (roomId, payload) => client.post(`/rooms/${roomId}/tenants`, payload)
export const updateRoomTenant = (id, payload) => client.put(`/room-tenants/${id}`, payload)
export const deleteRoomTenant = (id) => client.delete(`/room-tenants/${id}`)
