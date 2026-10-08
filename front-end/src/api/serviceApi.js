import client from './axiosClient'
export const getServices=(id)=>client.get(`/boarding-houses/${id}/services`)
export const createService=(id,p)=>client.post(`/boarding-houses/${id}/services`,p)
export const getService=(id)=>client.get(`/services/${id}`)
export const updateService=(id,p)=>client.put(`/services/${id}`,p)
export const deleteService=(id)=>client.delete(`/services/${id}`)
