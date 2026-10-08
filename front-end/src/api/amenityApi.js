import client from './axiosClient'

export const getAmenities = () => client.get('/amenities')
export const createAmenity = (payload) => client.post('/amenities', payload)
