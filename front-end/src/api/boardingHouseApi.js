import client from './axiosClient'

export const getBoardingHouses = () => client.get('/boarding-houses')
export const getBoardingHouse = (id) => client.get(`/boarding-houses/${id}`)
export const createBoardingHouse = (payload) => client.post('/boarding-houses', payload)
export const updateBoardingHouse = (id, payload) => client.put(`/boarding-houses/${id}`, payload)
export const deleteBoardingHouse = (id) => client.delete(`/boarding-houses/${id}`)
