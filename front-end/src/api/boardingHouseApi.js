import client from './axiosClient'
import { toast } from '../notifications/toastStore.js'
import { notifyApiError } from './notificationTransport.js'

export const getBoardingHouses = () => client.get('/boarding-houses')
export const getBoardingHouse = (id) => client.get(`/boarding-houses/${id}`)
export const createBoardingHouse = (payload) => client.post('/boarding-houses', payload)
export const updateBoardingHouse = (id, payload) => client.put(`/boarding-houses/${id}`, payload)
export const deleteBoardingHouse = (id) => client.delete(`/boarding-houses/${id}`)

export const createBoardingHouseWithRooms = async (payload) => {
  try {
    const response = await client.post('/boarding-houses/with-rooms', payload, { toast: false })
    toast.success(response.data.message)
    return response
  } catch (error) {
    if (!error.response || error.response.status >= 500) {
      error.creationOutcomeUnknown = true
      error.message = 'Chưa xác định thao tác đã hoàn tất hay chưa. Giữ nguyên thông tin và bấm Tạo lại để kiểm tra an toàn; hệ thống sẽ dùng lại mã yêu cầu.'
      toast.warning(error.message)
    } else notifyApiError(error)
    throw error
  }
}
