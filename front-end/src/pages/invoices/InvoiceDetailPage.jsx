import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Controller, useForm } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'
import { deleteInvoice, generateInvoice, getInvoice } from '../../api/invoiceApi'
import { createInvoiceItem, deleteInvoiceItem, updateInvoiceItem } from '../../api/invoiceItemApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import { createPayment, deletePayment, getPayments, updatePayment } from '../../api/paymentApi'
import { createPayOSPaymentRequest } from '../../api/payosApi'
import { paymentKeys } from '../../api/paymentKeys'
import PaymentForm from '../../components/forms/PaymentForm'
import InvoiceStatusBadge from '../../components/invoices/InvoiceStatusBadge'
import { formatCurrency, formatDate } from '../../utils/formatters'
import MoneyInput from '../../components/ui/MoneyInput'
import { canEditInvoiceFinancials } from '../../utils/invoiceFinancials'

const paymentMethodLabels = { CASH: 'Tiền mặt', BANK_TRANSFER: 'Chuyển khoản', CARD: 'Thẻ', OTHER: 'Khác', PAYOS: 'payOS' }

function InvoiceItemForm({ initialValues, onSubmit }) {
  const form = useForm({
    defaultValues: {
      type: initialValues.type || 'OTHER',
      description: initialValues.description || '',
      quantity: initialValues.quantity || 1,
      unit_price: initialValues.unit_price ?? '',
    },
  })

  return (
    <form onSubmit={form.handleSubmit((values) => onSubmit({ ...values, quantity: Number(values.quantity), unit_price: Number(values.unit_price) }))} className="space-y-3">
      <input {...form.register('type')} className="w-full rounded border p-2" />
      <input {...form.register('description')} className="w-full rounded border p-2" />
      <input {...form.register('quantity')} type="number" min="0" step="0.01" className="w-full rounded border p-2" />
      <Controller name="unit_price" control={form.control} render={({ field }) => <MoneyInput {...field} placeholder="Đơn giá" className="w-full rounded border p-2" />} />
      <button className="rounded bg-slate-900 px-4 py-2 text-white">Lưu</button>
    </form>
  )
}

export default function InvoiceDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [item, setItem] = useState(null)
  const [payment, setPayment] = useState(null)
  const [payosRequest, setPayosRequest] = useState(null)
  const [generateMessage, setGenerateMessage] = useState('')
  const invoiceQuery = useQuery({ queryKey: invoiceKeys.detail(id), queryFn: () => getInvoice(id), refetchInterval: payosRequest ? (current) => current.state.data?.data?.data?.status === 'PAID' ? false : 5000 : false })
  const paymentsQuery = useQuery({ queryKey: paymentKeys.byInvoice(id), queryFn: () => getPayments(id), enabled: Boolean(id) })
  const invalidatePaymentData = () => {
    queryClient.invalidateQueries({ queryKey: paymentKeys.byInvoice(id) })
    queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) })
  }
  const itemMutation = useMutation({ mutationFn: (payload) => item?.id ? updateInvoiceItem(item.id, payload) : createInvoiceItem(id, payload), onSuccess: () => { setItem(null); queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) }) } })
  const deleteItemMutation = useMutation({ mutationFn: deleteInvoiceItem, onSuccess: () => queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) }) })
  const paymentMutation = useMutation({ mutationFn: (payload) => payment?.id ? updatePayment(payment.id, payload) : createPayment(id, payload), onSuccess: () => { setPayment(null); invalidatePaymentData() } })
  const deletePaymentMutation = useMutation({ mutationFn: deletePayment, onSuccess: invalidatePaymentData })
  const payosMutation = useMutation({ mutationFn: () => createPayOSPaymentRequest(id), onSuccess: (response) => { setPayosRequest(response.data.data.payment_request); queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) }) } })
  const deleteInvoiceMutation = useMutation({ mutationFn: deleteInvoice, onSuccess: () => navigate(`/rooms/${invoiceQuery.data.data.data.room_id}/invoices`) })
  const generateMutation = useMutation({ mutationFn: () => generateInvoice(id), onSuccess: () => { setGenerateMessage('Đã tạo các khoản thu tự động.'); queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) }); queryClient.invalidateQueries({ queryKey: invoiceKeys.byRoom(invoiceQuery.data.data.data.room_id) }) }, onError: (error) => setGenerateMessage(error.response?.data?.message || 'Không thể tạo các khoản thu tự động.') })

  if (invoiceQuery.isPending) return <p>Đang tải...</p>
  if (invoiceQuery.isError) return <p>Không tìm thấy hóa đơn.</p>

  const invoice = invoiceQuery.data.data.data
  const draft = invoice.status === 'DRAFT'
  const canEditFinancials = canEditInvoiceFinancials(invoice)
  const remaining = Number(invoice.total_amount) - Number(invoice.paid_amount)
  const canPay = ['UNPAID', 'PARTIALLY_PAID'].includes(invoice.status) && Number(invoice.total_amount) > 0
  const payments = paymentsQuery.data?.data?.data || []
  const activePayosRequest = payosRequest || invoice.payos_payment_request
  const payosError = payosMutation.error?.response?.data?.errors?.payment?.[0] || payosMutation.error?.response?.data?.message
  const openPayOS = () => {
    if (invoice.payos_payment_request) {
      setPayosRequest(invoice.payos_payment_request)
      return
    }
    payosMutation.mutate()
  }

  return <section>
    <div className="print-hide"><Link to={`/rooms/${invoice.room_id}/invoices`}>← Quay lại</Link>
    <div className="my-5 flex justify-between"><div><h2 className="text-2xl font-bold">{invoice.invoice_code}</h2><p>Kỳ {invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)} · <InvoiceStatusBadge status={invoice.status} /></p></div><div className="flex gap-3"><Link to={`/invoices/${id}/print`} target="_blank" rel="noreferrer" className="underline">In hóa đơn</Link><Link to={`/invoices/${id}/edit`} className="underline">Sửa</Link>{draft && <button onClick={() => window.confirm('Bạn có chắc muốn xóa hóa đơn này?') && deleteInvoiceMutation.mutate(id)} className="text-red-700">Xóa</button>}</div></div>
    <div className="grid gap-4 md:grid-cols-2"><div className="rounded-xl bg-white p-5 shadow"><h3 className="font-semibold">Thông tin hóa đơn</h3><p className="mt-3">Ngày phát hành: {formatDate(invoice.issued_at)}</p><p>Hạn thanh toán: {formatDate(invoice.due_date)}</p><p>Ghi chú: {invoice.note || '—'}</p></div><div className="rounded-xl bg-white p-5 shadow"><h3 className="font-semibold">Tổng kết</h3><p className="mt-3">Tạm tính: {formatCurrency(invoice.subtotal)}</p><p>Giảm giá: {formatCurrency(invoice.discount_amount)}</p><p className="font-medium">Tổng cộng: {formatCurrency(invoice.total_amount)}</p><p>Đã thanh toán: {formatCurrency(invoice.paid_amount)}</p><p>Còn lại: {formatCurrency(remaining)}</p></div></div>
    <div className="mt-6 rounded-xl bg-white p-5 shadow"><div className="flex flex-wrap justify-between gap-3"><h3 className="font-semibold">Thanh toán</h3>{canPay && <div className="flex gap-2"><button onClick={() => setPayment({})} className="text-sm underline">Ghi nhận tiền mặt</button><button disabled={payosMutation.isPending} onClick={openPayOS} className="rounded bg-blue-700 px-3 py-1 text-sm text-white disabled:opacity-60">{payosMutation.isPending ? 'Đang tạo QR...' : invoice.payos_payment_request ? 'Xem QR thanh toán' : 'Tạo QR thanh toán'}</button></div>}</div>{invoice.payos_payment_request && <button type="button" onClick={openPayOS} className="mt-3 text-sm text-blue-700 underline">QR payOS hiện có · {formatCurrency(invoice.payos_payment_request.amount)}</button>}{payosMutation.isError && <p className="mt-3 text-sm text-red-700">{payosError || 'Không thể tạo yêu cầu thanh toán payOS.'}</p>}<p className="mt-3 text-sm">Trạng thái: <InvoiceStatusBadge status={invoice.status} /></p>{invoice.status === 'PAID' && <p className="mt-2 text-sm font-medium">Đã thanh toán đầy đủ{invoice.paid_at ? ` lúc ${formatDate(invoice.paid_at)}` : ''}.</p>}<div className="mt-3 overflow-x-auto"><table className="min-w-full text-sm"><thead><tr>{['Ngày thanh toán', 'Số tiền', 'Phương thức', 'Mã tham chiếu', 'Ghi chú', ''].map(x => <th key={x} className="p-2 text-left">{x}</th>)}</tr></thead><tbody>{payments.map(p => <tr key={p.id} className="border-t"><td className="p-2">{formatDate(p.paid_at)}</td><td className="p-2">{formatCurrency(p.amount)}</td><td className="p-2">{paymentMethodLabels[p.payment_method] || p.payment_method}</td><td className="p-2">{p.reference_code || '—'}</td><td className="p-2">{p.note || '—'}</td><td className="p-2">{p.payment_source !== 'PAYOS_WEBHOOK' && <><button onClick={() => setPayment(p)} className="mr-2 underline">Sửa</button><button onClick={() => window.confirm('Bạn có chắc muốn xóa lần thanh toán này?') && deletePaymentMutation.mutate(p.id)} className="text-red-700">Xóa</button></>}</td></tr>)}</tbody></table>{!paymentsQuery.isPending && payments.length === 0 && <p className="mt-3 text-sm">Chưa có thanh toán nào được ghi nhận.</p>}</div></div>
    <div className="mt-6 rounded-xl bg-white p-5 shadow"><div className="flex flex-wrap justify-between gap-3"><h3 className="font-semibold">Các khoản thu</h3>{canEditFinancials && <div className="flex gap-3"><button disabled={generateMutation.isPending} onClick={() => { const hasAuto = (invoice.items || []).some((currentItem) => currentItem.source === 'AUTO'); if (!hasAuto || window.confirm('Tạo lại các khoản thu tự động sẽ thay thế các khoản tự động hiện tại. Các khoản thủ công vẫn được giữ nguyên. Bạn có muốn tiếp tục?')) generateMutation.mutate() }} className="text-sm underline disabled:opacity-60">{generateMutation.isPending ? 'Đang tạo khoản thu...' : 'Tạo các khoản thu tự động'}</button><button onClick={() => setItem({})} className="text-sm underline">Thêm khoản thủ công</button></div>}</div>{generateMessage && <p className="mt-3 text-sm text-slate-700">{generateMessage}</p>}<div className="mt-4 overflow-x-auto"><table className="min-w-full text-sm"><tbody>{(invoice.items || []).map(x => <tr key={x.id} className="border-t"><td className="p-2">{x.description}</td><td className="p-2">{formatCurrency(x.amount)}</td><td className="p-2"><span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{x.source === 'AUTO' ? 'Tự động' : 'Thủ công'}</span></td><td className="p-2">{canEditFinancials && x.source !== 'AUTO' && <><button onClick={() => setItem(x)} className="mr-2 underline">Sửa</button><button onClick={() => window.confirm('Xóa khoản thu này?') && deleteItemMutation.mutate(x.id)} className="text-red-700">Xóa</button></>}</td></tr>)}</tbody></table></div></div>
    {payment && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-white p-6"><div className="mb-4 flex justify-between"><b>{payment.id ? 'Sửa' : 'Ghi nhận'} thanh toán</b><button onClick={() => setPayment(null)}>✕</button></div><PaymentForm remaining={payment.id ? remaining + Number(payment.amount) : remaining} initialValues={payment} onSubmit={(payload) => paymentMutation.mutateAsync(payload)} label="Lưu thanh toán" /></div></div>}
    {activePayosRequest && payosRequest && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-white p-6 text-center"><div className="flex justify-between"><b>Thanh toán qua payOS</b><button type="button" onClick={() => setPayosRequest(null)}>✕</button></div>{invoice.status === 'PAID' ? <p className="mt-6 text-green-700">Hóa đơn đã được xác nhận thanh toán.</p> : <><p className="mt-3 text-sm text-slate-600">Quét mã QR hoặc mở trang thanh toán payOS. Trạng thái sẽ tự cập nhật sau khi payOS xác nhận.</p>{activePayosRequest.qr_code && <div className="mx-auto mt-5 w-fit rounded-lg border bg-white p-3"><QRCodeSVG value={activePayosRequest.qr_code} size={220} level="M" /></div>}<p className="mt-4 text-lg font-semibold">{formatCurrency(activePayosRequest.amount)}</p>{activePayosRequest.checkout_url && <a href={activePayosRequest.checkout_url} target="_blank" rel="noreferrer" className="mt-4 inline-block rounded bg-slate-900 px-4 py-2 text-sm text-white">Mở trang thanh toán</a>}<p className="mt-4 text-xs text-slate-500">Mã đơn: {activePayosRequest.order_code}</p></>}</div></div>}
    {item && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-white p-6"><button type="button" onClick={() => setItem(null)}>✕</button><InvoiceItemForm initialValues={item} onSubmit={(payload) => itemMutation.mutate(payload)} /></div></div>}
    </div></section>
}
