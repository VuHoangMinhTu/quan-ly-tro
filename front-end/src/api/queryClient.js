import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { DASHBOARD_REFRESH_MS } from './dashboardPolicy.js'

export function createAppQueryClient() {
  const invoiceVersions = new WeakMap()
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
    // One central hook covers existing and future room/contract/invoice/payment
    // mutations, including manager assignment, without overriding page callbacks.
    mutationCache: new MutationCache({ onSuccess: () => { void client.invalidateQueries({ queryKey: ['dashboard'] }) } }),
    queryCache: new QueryCache({ onSuccess: (response, query) => {
      if (query.queryKey[0] !== 'invoice') return
      const invoice = response?.data?.data
      if (!invoice) return
      const version = JSON.stringify([invoice.status, invoice.total_amount, invoice.paid_amount])
      const previousVersion = invoiceVersions.get(query)
      if (previousVersion !== version) {
        invoiceVersions.set(query, version)
        // payOS confirmation arrives through query polling, not a local mutation.
        // The first read establishes a baseline; it is not a financial change.
        if (previousVersion !== undefined) void client.invalidateQueries({ queryKey: ['dashboard'] })
      }
    } }),
  })
  // Each login has its own client; no dashboard data is shared across sessions.
  client.setQueryDefaults(['dashboard'], { staleTime: DASHBOARD_REFRESH_MS })
  return client
}
