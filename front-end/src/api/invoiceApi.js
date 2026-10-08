import client from './axiosClient'
export const getInvoicesByRoom=roomId=>client.get(`/rooms/${roomId}/invoices`)
export const getInvoice=id=>client.get(`/invoices/${id}`)
export const createInvoice=(roomId,p)=>client.post(`/rooms/${roomId}/invoices`,p)
export const updateInvoice=(id,p)=>client.put(`/invoices/${id}`,p)
export const deleteInvoice=id=>client.delete(`/invoices/${id}`)
export const generateInvoice=id=>client.post(`/invoices/${id}/generate`)
