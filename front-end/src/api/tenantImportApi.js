import client from './axiosClient'

export const downloadTenantTemplate = () => client.get('/tenants/import/template', { responseType: 'blob' })
export const previewTenantImport = (file, signal) => {
  const form = new FormData()
  form.append('file', file)
  return client.post('/tenants/import/preview', form, { headers: { 'Content-Type': 'multipart/form-data' }, signal })
}
export const confirmTenantImport = (payload) => client.post('/tenants/import/confirm', payload)
export const cancelTenantImport = (token) => client.post('/tenants/import/cancel', { token }, { toast: false })
