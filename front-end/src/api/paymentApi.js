import client from './axiosClient'
export const getPayments=invoiceId=>client.get(`/invoices/${invoiceId}/payments`)
export const getPayment=id=>client.get(`/payments/${id}`)
export const createPayment=(invoiceId,payload)=>client.post(`/invoices/${invoiceId}/payments`,payload)
export const updatePayment=(id,payload)=>client.put(`/payments/${id}`,payload)
export const deletePayment=id=>client.delete(`/payments/${id}`)
