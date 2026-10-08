import client from './axiosClient'

export const getUtilityReadings = (meterId) => client.get(`/utility-meters/${meterId}/readings`)
export const getUtilityReading = (id) => client.get(`/utility-readings/${id}`)
export const createUtilityReading = (meterId, payload) => client.post(`/utility-meters/${meterId}/readings`, payload)
export const updateUtilityReading = (id, payload) => client.put(`/utility-readings/${id}`, payload)
export const deleteUtilityReading = (id) => client.delete(`/utility-readings/${id}`)
