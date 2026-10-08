export function canEditInvoiceFinancials(invoice) {
  return invoice?.status === 'DRAFT'
    || (invoice?.status === 'UNPAID' && Number(invoice?.paid_amount || 0) === 0)
}

export function hasInvoiceFinancialChanges(invoice, payload) {
  return Number(invoice?.contract_id || 0) !== Number(payload.contract_id || 0)
    || invoice?.billing_period !== payload.billing_period
    || Number(invoice?.discount_amount || 0) !== Number(payload.discount_amount || 0)
}
