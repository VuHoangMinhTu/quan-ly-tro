export const tenantKeys = {
  all: ['tenants'],
  list: (filters) => ['tenants', filters],
  detail: (id) => ['tenant', id],
}
