import client from './axiosClient'
export const login = (payload) => client.post('/login', payload)
export const register = (payload) => client.post('/register', payload)
export const logout = () => client.post('/logout')
export const getMe = () => client.get('/me')
export const resendVerification = (email) => client.post('/email/verification-notification', { email })
export const exchangeGoogleCode = (code) => client.post('/auth/google/exchange', { code })
