import client from './axiosClient'
export const getContractsByRoom = (roomId) => client.get(`/rooms/${roomId}/contracts`)
export const getContract = (id) => client.get(`/contracts/${id}`)
export const createContract = (roomId, payload) => client.post(`/rooms/${roomId}/contracts`, payload)
export const updateContract = (id, payload) => client.put(`/contracts/${id}`, payload)
export const deleteContract = (id) => client.delete(`/contracts/${id}`)
