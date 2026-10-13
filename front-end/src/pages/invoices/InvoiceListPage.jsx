import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { getInvoicesByRoom } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoiceStatusBadge from '../../components/invoices/InvoiceStatusBadge'
import { formatCurrency } from '../../utils/formatters'

export default function InvoiceListPage() {
  const { roomId } = useParams()
  const query = useQuery({ queryKey: invoiceKeys.byRoom(roomId), queryFn: () => getInvoicesByRoom(roomId) })

  if (query.isError) return <p className="break-words text-red-600">{getApiErrorMessage(query.error)}</p>

  const invoices = query.data?.data?.data || []

  return (
    <section className="min-w-0">
      <Link to={`/rooms/${roomId}`} className="inline-flex min-h-11 items-center text-sm">← Quay lại phòng</Link>
      <div className="my-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-bold sm:text-2xl">Hóa đơn</h2>
        <Link to={`/rooms/${roomId}/invoices/new`} className="inline-flex min-h-11 items-center justify-center rounded bg-slate-900 px-4 py-2 text-sm text-white">Tạo hóa đơn</Link>
      </div>

      <div className="space-y-3 md:hidden">
        {invoices.map((invoice) => (
          <article key={invoice.id} className="min-w-0 rounded-xl bg-white p-4 shadow">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0 flex-1">
                <h3 className="break-words font-semibold text-slate-900">{invoice.invoice_code}</h3>
                <p className="mt-1 text-sm text-slate-500">Kỳ {invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)}</p>
              </div>
              <InvoiceStatusBadge status={invoice.status} />
            </div>
            <dl className="mt-4 space-y-2 text-sm">
              <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt className="text-slate-600">Tổng</dt><dd className="font-medium">{formatCurrency(invoice.total_amount)}</dd></div>
              <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt className="text-slate-600">Đã thanh toán</dt><dd>{formatCurrency(invoice.paid_amount)}</dd></div>
              <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt className="text-slate-600">Còn lại</dt><dd className="font-semibold">{formatCurrency(Number(invoice.total_amount) - Number(invoice.paid_amount))}</dd></div>
            </dl>
            <Link to={`/invoices/${invoice.id}`} className="mt-3 inline-flex min-h-11 items-center text-sm underline">Xem hóa đơn</Link>
          </article>
        ))}
      </div>

      <div className="hidden min-w-0 max-w-full overflow-x-auto rounded-xl bg-white shadow md:block">
        <table className="min-w-[760px] w-full text-sm">
          <thead className="bg-slate-50"><tr>{['Kỳ', 'Mã', 'Tổng', 'Đã thanh toán', 'Còn lại', 'Trạng thái', ''].map((label) => <th key={label} className="p-3 text-left">{label}</th>)}</tr></thead>
          <tbody>
            {invoices.map((invoice) => (
              <tr key={invoice.id} className="border-t">
                <td className="whitespace-nowrap p-3">{invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)}</td>
                <td className="max-w-64 break-words p-3">{invoice.invoice_code}</td>
                <td className="whitespace-nowrap p-3">{formatCurrency(invoice.total_amount)}</td>
                <td className="whitespace-nowrap p-3">{formatCurrency(invoice.paid_amount)}</td>
                <td className="whitespace-nowrap p-3">{formatCurrency(Number(invoice.total_amount) - Number(invoice.paid_amount))}</td>
                <td className="p-3"><InvoiceStatusBadge status={invoice.status} /></td>
                <td className="p-3"><Link to={`/invoices/${invoice.id}`} className="inline-flex min-h-11 items-center underline">Xem</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!query.isPending && invoices.length === 0 && <p className="rounded-xl bg-white p-6 text-center shadow">Phòng này chưa có hóa đơn.</p>}
    </section>
  )
}
