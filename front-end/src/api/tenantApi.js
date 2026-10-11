import client from './axiosClient'

// Only explicit filters enter the URL; direct queryFn callers also pass Query's context.
export const getTenants = ({
  q, boardingHouseId, roomId, occupancyStatus, role, contractStatus, signal,
} = {}) => client.get('/tenants', {
  signal,
  params: {
    q: q || undefined,
    boarding_house_id: boardingHouseId || undefined,
    room_id: roomId || undefined,
    occupancy_status: occupancyStatus || undefined,
    role: role || undefined,
    contract_status: contractStatus || undefined,
  },
})
export const getTenant = (id) => client.get(`/tenants/${id}`)
export const createTenant = (payload) => client.post('/tenants', payload)
export const updateTenant = (id, payload) => client.put(`/tenants/${id}`, payload)
export const deleteTenant = (id) => client.delete(`/tenants/${id}`)
