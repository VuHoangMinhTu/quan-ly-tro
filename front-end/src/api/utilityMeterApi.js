import client from './axiosClient'

export const getUtilityMeters = (roomId) => client.get(`/rooms/${roomId}/utility-meters`)
export const getUtilityMeter = (id) => client.get(`/utility-meters/${id}`)
export const createUtilityMeter = (roomId, payload) => client.post(`/rooms/${roomId}/utility-meters`, payload)
export const updateUtilityMeter = (id, payload) => client.put(`/utility-meters/${id}`, payload)
export const deleteUtilityMeter = (id) => client.delete(`/utility-meters/${id}`)
