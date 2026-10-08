import client from './axiosClient'
export const createInvoiceItem=(id,p)=>client.post(`/invoices/${id}/items`,p)
export const updateInvoiceItem=(id,p)=>client.put(`/invoice-items/${id}`,p)
export const deleteInvoiceItem=id=>client.delete(`/invoice-items/${id}`)
