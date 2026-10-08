import client from './axiosClient'

export const getTenants = () => client.get('/tenants')
export const getTenant = (id) => client.get(`/tenants/${id}`)
export const createTenant = (payload) => client.post('/tenants', payload)
export const updateTenant = (id, payload) => client.put(`/tenants/${id}`, payload)
export const deleteTenant = (id) => client.delete(`/tenants/${id}`)
