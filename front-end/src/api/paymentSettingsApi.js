import client from './axiosClient'

export const getPaymentProfile = () => client.get('/landlord/payment-profile')
export const savePaymentProfile = (data) => client.put('/landlord/payment-profile', data)
export const getPayosConfig = () => client.get('/landlord/payos-config')
export const savePayosConfig = (data) => client.put('/landlord/payos-config', data)
export const disconnectPayos = () => client.delete('/landlord/payos-config')
