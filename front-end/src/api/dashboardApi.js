import client from './axiosClient'

export const dashboardKey = filters => ['dashboard', filters]
export const getDashboard = ({ signal, queryKey } = {}) => {
  const [, filters] = queryKey || []
  const params = filters ? { period: filters.preset, start_date: filters.start, end_date: filters.end, boarding_house_id: filters.houseId || undefined, compare: filters.compare ? 1 : undefined } : undefined
  return client.get('/dashboard', { signal, params })
}
