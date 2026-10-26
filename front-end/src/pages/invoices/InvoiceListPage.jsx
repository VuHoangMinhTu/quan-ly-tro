import { useCallback, useEffect, useRef, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Search, X } from 'lucide-react'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { getInvoicesByRoom } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoiceStatusBadge from '../../components/invoices/InvoiceStatusBadge'
import { formatCurrency } from '../../utils/formatters'
import { invoiceStatusLabels } from '../../utils/invoiceStatus'

const statuses = Object.entries(invoiceStatusLabels)

function InvoiceSearch({ value, onCommit }) {
  const [draft, setDraft] = useState(value)
  useEffect(() => { setDraft(value) }, [value])
  useEffect(() => { const timer = setTimeout(() => onCommit(draft.trim()), 350); return () => clearTimeout(timer) }, [draft, onCommit])
  return <label className="block"><span className="sr-only">Tìm hóa đơn</span><div className="flex items-center gap-2 rounded-lg border bg-white px-3"><Search size={17} className="text-slate-400" /><input type="search" value={draft} onChange={(event) => setDraft(event.target.value)} placeholder="Tìm mã hóa đơn, phòng hoặc người thuê" className="min-h-11 w-full min-w-0 text-sm outline-none" /></div></label>
}

export default function InvoiceListPage() {
  const { roomId } = useParams()
  const [params, setParams] = useSearchParams()
  const latestParams = useRef(params)
  useEffect(() => { latestParams.current = params }, [params])
  const filters = { search: params.get('search') || '', status: params.get('status') || '', month: params.get('month') || '', year: params.get('year') || '', page: params.get('page') || '1' }
  const update = useCallback((name, value) => {
    const next = new URLSearchParams(latestParams.current)
    if (value) next.set(name, value); else next.delete(name)
    if (name !== 'page') next.delete('page')
    latestParams.current = next; setParams(next)
  }, [setParams])
  const reset = () => { latestParams.current = new URLSearchParams(); setParams(latestParams.current) }
  const activeFilters = Boolean(filters.search || filters.status || filters.month || filters.year)
  const query = useQuery({ queryKey: invoiceKeys.byRoom(roomId, filters), queryFn: ({ signal }) => getInvoicesByRoom(roomId, filters, { signal }), placeholderData: keepPreviousData })
  const result = query.data?.data?.data
  const invoices = result?.items || []
  const pagination = result?.pagination

  return <section className="min-w-0"><Link to={`/rooms/${roomId}`} className="inline-flex min-h-11 items-center text-sm">← Quay lại phòng</Link><div className="my-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 className="text-xl font-bold sm:text-2xl">Hóa đơn</h2><Link to={`/rooms/${roomId}/invoices/new`} className="inline-flex min-h-11 items-center justify-center rounded bg-slate-900 px-4 py-2 text-sm text-white">Tạo hóa đơn</Link></div>
    <div className="mb-4 space-y-3"><InvoiceSearch value={filters.search} onCommit={(value) => update('search', value)} /><div className="grid gap-3 sm:grid-cols-3"><label className="text-sm">Trạng thái<select value={filters.status} onChange={(event) => update('status', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border bg-white px-3"><option value="">Tất cả trạng thái</option>{statuses.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label className="text-sm">Tháng<select value={filters.month} onChange={(event) => update('month', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border bg-white px-3"><option value="">Tất cả tháng</option>{Array.from({ length: 12 }, (_, index) => <option key={index + 1} value={index + 1}>Tháng {index + 1}</option>)}</select></label><label className="text-sm">Năm<input type="number" min="2000" max="2100" value={filters.year} onChange={(event) => update('year', event.target.value)} placeholder="2026" className="mt-1 min-h-11 w-full rounded-lg border bg-white px-3" /></label></div>{activeFilters && <button type="button" onClick={reset} className="inline-flex min-h-11 items-center gap-1 rounded-lg border px-3 text-sm"><X size={15} /> Xóa bộ lọc</button>}</div>
    {query.isError ? <p role="alert" className="text-red-600">{getApiErrorMessage(query.error)}</p> : query.isPending ? <p>Đang tải hóa đơn...</p> : invoices.length === 0 ? <div className="rounded-xl bg-white p-6 text-center shadow"><p>{activeFilters ? 'Không tìm thấy hóa đơn phù hợp.' : 'Phòng này chưa có hóa đơn.'}</p>{activeFilters && <button type="button" onClick={reset} className="mt-3 underline">Xóa bộ lọc</button>}</div> : <><div className="space-y-3 md:hidden">{invoices.map((invoice) => <article key={invoice.id} className="rounded-xl bg-white p-4 shadow"><div className="flex justify-between gap-2"><div><h3 className="font-semibold">{invoice.invoice_code}</h3><p className="text-sm text-slate-500">Kỳ {invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)}</p></div><InvoiceStatusBadge status={invoice.status} /></div><p className="mt-3 font-medium">{formatCurrency(invoice.total_amount)}</p><p className="mt-1 text-sm text-slate-600">Còn lại: {formatCurrency(Number(invoice.total_amount) - Number(invoice.paid_amount))}</p><Link to={`/invoices/${invoice.id}`} className="mt-3 inline-flex min-h-11 items-center underline">Xem hóa đơn</Link></article>)}</div><div className="hidden max-w-full overflow-x-auto rounded-xl bg-white shadow md:block"><table className="min-w-[760px] w-full text-sm"><thead className="bg-slate-50"><tr>{['Kỳ', 'Mã', 'Tổng', 'Đã thanh toán', 'Còn lại', 'Trạng thái', ''].map((label) => <th key={label} className="p-3 text-left">{label}</th>)}</tr></thead><tbody>{invoices.map((invoice) => <tr key={invoice.id} className="border-t"><td className="p-3">{invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)}</td><td className="p-3">{invoice.invoice_code}</td><td className="p-3">{formatCurrency(invoice.total_amount)}</td><td className="p-3">{formatCurrency(invoice.paid_amount)}</td><td className="p-3">{formatCurrency(Number(invoice.total_amount) - Number(invoice.paid_amount))}</td><td className="p-3"><InvoiceStatusBadge status={invoice.status} /></td><td className="p-3"><Link to={`/invoices/${invoice.id}`} className="underline">Xem</Link></td></tr>)}</tbody></table></div></>}
    {pagination?.last_page > 1 && <div className="mt-4 flex items-center justify-between"><button type="button" disabled={pagination.current_page <= 1} onClick={() => update('page', pagination.current_page - 1)} className="min-h-11 rounded border px-3 disabled:opacity-40">← Trước</button><span className="text-sm">Trang {pagination.current_page}/{pagination.last_page}</span><button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => update('page', pagination.current_page + 1)} className="min-h-11 rounded border px-3 disabled:opacity-40">Sau →</button></div>}
  </section>
}
