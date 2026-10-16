import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'

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
      if (invoiceVersions.get(query) !== version) {
        invoiceVersions.set(query, version)
        // payOS confirmation arrives through query polling, not a local mutation.
        void client.invalidateQueries({ queryKey: ['dashboard'] })
      }
    } }),
  })
  return client
}
