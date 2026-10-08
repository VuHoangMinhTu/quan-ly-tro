import client from './axiosClient'

export const createPayOSPaymentRequest = (invoiceId) => client.post(`/invoices/${invoiceId}/payos`)
