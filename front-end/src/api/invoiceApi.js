import client from './axiosClient'
export const getInvoicesByRoom=(roomId,params={},config={})=>client.get(`/rooms/${roomId}/invoices`,{...config,params})
export const getInvoice=id=>client.get(`/invoices/${id}`)
export const getInvoiceContracts=(roomId,billingPeriod)=>client.get(`/rooms/${roomId}/invoice-contracts`,{params:{billing_period:billingPeriod}})
export const createInvoice=(roomId,p)=>client.post(`/rooms/${roomId}/invoices`,p)
export const updateInvoice=(id,p)=>client.put(`/invoices/${id}`,p)
export const deleteInvoice=id=>client.delete(`/invoices/${id}`)
export const generateInvoice=id=>client.post(`/invoices/${id}/generate`)
