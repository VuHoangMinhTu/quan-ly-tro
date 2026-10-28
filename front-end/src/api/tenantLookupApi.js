import client from './axiosClient'

export const lookupTenants = (params, signal) => client.get('/tenant-lookup', { params, signal })
export const getTenantLookupOptions = (params, signal) => client.get('/tenant-lookup/options', { params, signal })
