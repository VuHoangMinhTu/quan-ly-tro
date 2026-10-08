import client from './axiosClient'
export const getServicePriceTiers=id=>client.get(`/services/${id}/price-tiers`)
export const createServicePriceTier=(id,p)=>client.post(`/services/${id}/price-tiers`,p)
export const updateServicePriceTier=(id,p)=>client.put(`/service-price-tiers/${id}`,p)
export const deleteServicePriceTier=id=>client.delete(`/service-price-tiers/${id}`)
