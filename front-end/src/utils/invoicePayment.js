export function invoicePayosQr(invoice, now = Date.now()) {
  if (!['UNPAID', 'PARTIALLY_PAID'].includes(invoice.status)) return null
  const request = invoice.payos_payment_request
  const remaining = Math.max(0, Number(invoice.total_amount || 0) - Number(invoice.paid_amount || 0))
  if (!request?.qr_code || !request.payment_link_id || !request.checkout_url || !['PENDING', 'PROCESSING', 'ACTIVE'].includes(request.status) || Number(request.amount) !== remaining || remaining <= 0) return null
  if (request.expired_at && (!Number.isFinite(Date.parse(request.expired_at)) || Date.parse(request.expired_at) <= now)) return null
  return request
}
