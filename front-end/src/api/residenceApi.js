import client from './axiosClient'

export const residenceKeys = {
  houses: ['residence-houses'],
  rooms: id => ['residence-rooms', String(id)],
  context: id => ['residence-context', String(id)],
  list: ['residence-declarations'],
  detail: id => ['residence-declaration', String(id)],
  profile: id => ['residence-profile', String(id)],
  managers: id => ['boarding-house-managers', String(id)],
}
export const getResidenceHouses = () => client.get('/residence/boarding-houses')
export const getResidenceRooms = id => client.get(`/residence/boarding-houses/${id}/rooms`)
export const getResidenceContext = id => client.get(`/residence/rooms/${id}/context`)
export const getResidenceDeclarations = roomId => client.get('/residence-declarations', { params: roomId ? { room_id: roomId } : {} })
export const getResidenceDeclaration = id => client.get(`/residence-declarations/${id}`)
export const createResidenceDeclaration = data => client.post('/residence-declarations', data)
export const updateResidenceDeclaration = (id, data) => client.put(`/residence-declarations/${id}`, data)
export const updateResidenceStatus = (id, data) => client.put(`/residence-declarations/${id}/status`, data)
export const deleteResidenceDeclaration = id => client.delete(`/residence-declarations/${id}`)
export const exportResidenceDeclaration = id => client.get(`/residence-declarations/${id}/export`)
export const getResidenceProfile = id => client.get(`/boarding-houses/${id}/residence-profile`)
export const updateResidenceProfile = (id, data) => client.put(`/boarding-houses/${id}/residence-profile`, data)
export const getBoardingHouseManagers = id => client.get(`/boarding-houses/${id}/managers`)
export const updateBoardingHouseManagers = (id, data) => client.put(`/boarding-houses/${id}/managers`, data)
