import client from './axiosClient'

export const getPaymentProfile = () => client.get('/landlord/payment-profile')
export const savePaymentProfile = (data) => {
  if (data instanceof FormData) {
    data.set('_method', 'PUT')
    return client.post('/landlord/payment-profile', data, { headers: { 'Content-Type': undefined } })
  }
  return client.put('/landlord/payment-profile', data)
}
export const getPaymentQrImage = (endpoint) => client.get(endpoint, { responseType: 'blob' })
export const getPayosConfig = () => client.get('/landlord/payos-config')
export const savePayosConfig = (data) => client.put('/landlord/payos-config', data)
export const disconnectPayos = () => client.delete('/landlord/payos-config')
