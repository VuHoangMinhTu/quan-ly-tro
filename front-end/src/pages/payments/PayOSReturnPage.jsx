import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { getInvoice } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoiceStatusBadge from '../../components/invoices/InvoiceStatusBadge'

export default function PayOSReturnPage({ cancelled = false }) {
  const [params] = useSearchParams()
  const invoiceId = params.get('invoice_id')
  const query = useQuery({
    queryKey: invoiceKeys.detail(invoiceId),
    queryFn: () => getInvoice(invoiceId),
    enabled: Boolean(invoiceId),
    refetchInterval: (current) => current.state.data?.data?.data?.status === 'PAID' ? false : 5000,
  })
  const invoice = query.data?.data?.data

  return <section className="mx-auto max-w-lg rounded-xl bg-white p-6 text-center shadow-sm">
    <h2 className="text-xl font-bold">{cancelled ? 'Thanh toán payOS đã được hủy' : 'Đang kiểm tra thanh toán payOS'}</h2>
    {!invoiceId && <p className="mt-3 text-slate-600">Không xác định được hóa đơn cần kiểm tra.</p>}
    {query.isPending && invoiceId && <p className="mt-3 text-slate-600">Đang đồng bộ trạng thái từ hệ thống...</p>}
    {query.isError && <p className="mt-3 text-red-700">{getApiErrorMessage(query.error)}</p>}
    {invoice && <div className="mt-4 space-y-2"><p>{invoice.invoice_code}</p><InvoiceStatusBadge status={invoice.status} />{invoice.status === 'PAID' ? <p className="text-green-700">Hóa đơn đã được thanh toán.</p> : <p className="text-slate-600">Hóa đơn chưa được xác nhận thanh toán. Trạng thái sẽ tự cập nhật khi webhook payOS đến.</p>}<Link to={`/invoices/${invoice.id}`} className="mt-4 inline-block rounded bg-slate-900 px-4 py-2 text-sm text-white">Về chi tiết hóa đơn</Link></div>}
  </section>
}
