import client from './axiosClient'

export { contractDocumentKeys, contractTemplateKeys } from './contractTemplateKeys'
export const getContractTemplates = () => client.get('/contract-templates')
export const getContractTemplate = id => client.get(`/contract-templates/${id}`)
export const createContractTemplate = payload => client.post('/contract-templates', payload)
export const updateContractTemplate = (id, payload) => client.put(`/contract-templates/${id}`, payload)
export const createContractTemplateVersion = id => client.post(`/contract-templates/${id}/versions`, {})
export const setContractTemplateStatus = (id, active) => client.put(`/contract-templates/${id}/status`, { is_active: active })
export const previewContractTemplate = content => client.post('/contract-templates/preview', { content_html: content })
export const getContractDocument = id => client.get(`/contracts/${id}/document`)
export const previewContractDocument = id => client.get(`/contracts/${id}/document/preview`)
export const finalizeContractDocument = id => client.post(`/contracts/${id}/finalize`, {})
