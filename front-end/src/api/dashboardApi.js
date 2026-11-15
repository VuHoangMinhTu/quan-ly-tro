import client from './axiosClient'

export const dashboardKey = ['dashboard']
export const getDashboard = ({ signal } = {}) => client.get('/dashboard', { signal })
export const getDashboardSummary = ({ queryKey, signal }) => client.get('/dashboard/summary', { params: queryKey[2], signal })
export const getDashboardAnalytics = ({ queryKey, signal }) => client.get('/dashboard/analytics', { params: queryKey[2], signal })
