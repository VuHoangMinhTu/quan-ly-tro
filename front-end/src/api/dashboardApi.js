import client from './axiosClient'

export const dashboardKey = ['dashboard']
export const getDashboard = ({ signal } = {}) => client.get('/dashboard', { signal })
