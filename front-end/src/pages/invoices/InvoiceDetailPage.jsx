import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
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
import PrivatePaymentQr from '../../components/payments/PrivatePaymentQr'
import { formatCurrency, formatDate } from '../../utils/formatters'
import MoneyInput from '../../components/ui/MoneyInput'
import { canEditInvoiceFinancials } from '../../utils/invoiceFinancials'
import { numberFieldError } from '../../utils/formValidation'

const paymentMethodLabels = { CASH: 'Tiền mặt', BANK_TRANSFER: 'Chuyển khoản', CARD: 'Thẻ', OTHER: 'Khác', PAYOS: 'payOS' }

function InvoiceItemForm({ initialValues, onSubmit }) {
  const form = useForm({
    mode: 'onTouched',
    defaultValues: {
      type: initialValues.type || 'OTHER',
      description: initialValues.description || '',
      quantity: initialValues.quantity || 1,
      unit_price: initialValues.unit_price ?? '',
    },
  })

  const [error, setError] = useState('')
  const submit = async (values) => {
    setError('')
    try {
      await onSubmit({ ...values, quantity: Number(values.quantity), unit_price: Number(values.unit_price) })
    } catch (requestError) {
      if (!applyApiFieldErrors(requestError, form.setError)) setError(getApiErrorMessage(requestError))
    }
  }

  return (
    <form noValidate onSubmit={form.handleSubmit(submit)} className="space-y-3">
      <input {...form.register('type', { validate: value => Boolean(value?.trim()) || 'Vui lòng nhập loại khoản thu.', maxLength: { value: 50, message: 'Loại khoản thu tối đa 50 ký tự.' } })} aria-label="Loại khoản thu" className="min-h-11 w-full min-w-0 rounded border p-2" />{form.formState.errors.type && <p className="mt-1 text-sm text-red-600">{form.formState.errors.type.message}</p>}
      <input {...form.register('description', { validate: value => Boolean(value?.trim()) || 'Vui lòng nhập diễn giải khoản thu.', maxLength: { value: 255, message: 'Diễn giải tối đa 255 ký tự.' } })} aria-label="Diễn giải khoản thu" className="min-h-11 w-full min-w-0 rounded border p-2" />{form.formState.errors.description && <p className="mt-1 text-sm text-red-600">{form.formState.errors.description.message}</p>}
      <input {...form.register('quantity', { validate: value => numberFieldError(value, 'Số lượng') })} aria-label="Số lượng" type="number" min="0" step="0.01" className="min-h-11 w-full min-w-0 rounded border p-2" />{form.formState.errors.quantity && <p className="mt-1 text-sm text-red-600">{form.formState.errors.quantity.message}</p>}
      <Controller name="unit_price" control={form.control} rules={{ validate: value => numberFieldError(value, 'Đơn giá') }} render={({ field }) => <MoneyInput {...field} placeholder="Đơn giá" aria-label="Đơn giá" className="min-h-11 w-full min-w-0 rounded border p-2" />} />{form.formState.errors.unit_price && <p className="mt-1 text-sm text-red-600">{form.formState.errors.unit_price.message}</p>}
      {error && <p role="alert" className="break-words text-sm text-red-600">{error}</p>}
      <button disabled={form.formState.isSubmitting} className="min-h-11 w-full rounded bg-slate-900 px-4 py-2 text-white sm:w-auto">Lưu</button>
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
  const generateMutation = useMutation({ mutationFn: () => generateInvoice(id), onSuccess: () => { setGenerateMessage('Đã tạo các khoản thu tự động.'); queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) }); queryClient.invalidateQueries({ queryKey: invoiceKeys.byRoom(invoiceQuery.data.data.data.room_id) }) }, onError: (error) => setGenerateMessage(getApiErrorMessage(error)) })

  if (invoiceQuery.isPending) return <p>Đang tải...</p>
  if (invoiceQuery.isError) return <p className="text-red-600">{getApiErrorMessage(invoiceQuery.error)}</p>

  const invoice = invoiceQuery.data.data.data
  const bank = invoice.payment_profile
  const canManage = invoice.can_manage !== false
  const draft = canManage && invoice.status === 'DRAFT'
  const canEditFinancials = canEditInvoiceFinancials(invoice)
  const remaining = Number(invoice.total_amount) - Number(invoice.paid_amount)
  const canPay = canManage && ['UNPAID', 'PARTIALLY_PAID'].includes(invoice.status) && Number(invoice.total_amount) > 0
  const payments = paymentsQuery.data?.data?.data || []
  const activePayosRequest = payosRequest || invoice.payos_payment_request
  const payosError = payosMutation.error ? getApiErrorMessage(payosMutation.error) : ''
  const openPayOS = () => {
    if (invoice.payos_payment_request) {
      setPayosRequest(invoice.payos_payment_request)
      return
    }
    payosMutation.mutate()
  }

  const renderPaymentActions = (currentPayment) => canManage && currentPayment.payment_source !== 'PAYOS_WEBHOOK' && (
    <div className="flex flex-wrap gap-2">
      <button type="button" onClick={() => setPayment(currentPayment)} className="inline-flex min-h-11 items-center px-2 underline">Sửa</button>
      <button type="button" onClick={() => window.confirm('Bạn có chắc muốn xóa lần thanh toán này?') && deletePaymentMutation.mutate(currentPayment.id)} className="inline-flex min-h-11 items-center px-2 text-red-700">Xóa</button>
    </div>
  )

  return <section className="min-w-0">
    <div className="print-hide min-w-0"><Link to={canManage ? `/rooms/${invoice.room_id}/invoices` : '/dashboard'} className="inline-flex min-h-11 items-center">← Quay lại</Link>
    {deleteInvoiceMutation.isError && <p role="alert" className="mt-3 text-sm text-red-600">{getApiErrorMessage(deleteInvoiceMutation.error)}</p>}
    {deleteItemMutation.isError && <p role="alert" className="mt-3 text-sm text-red-600">{getApiErrorMessage(deleteItemMutation.error)}</p>}
    {deletePaymentMutation.isError && <p role="alert" className="mt-3 text-sm text-red-600">{getApiErrorMessage(deletePaymentMutation.error)}</p>}
    {paymentsQuery.isError && <p className="mt-3 text-sm text-red-600">{getApiErrorMessage(paymentsQuery.error)}</p>}
    <div className="my-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0"><h2 className="break-words text-xl font-bold sm:text-2xl">{invoice.invoice_code}</h2><p className="mt-1 flex flex-wrap items-center gap-2">Kỳ {invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)} · <InvoiceStatusBadge status={invoice.status} /></p></div>
      <div className="flex shrink-0 flex-wrap gap-3"><Link to={`/invoices/${id}/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center underline">In hóa đơn</Link>{canManage && <Link to={`/invoices/${id}/edit`} className="inline-flex min-h-11 min-w-11 items-center justify-center underline lg:min-w-0">Sửa</Link>}{draft && <button onClick={() => window.confirm('Bạn có chắc muốn xóa hóa đơn này?') && deleteInvoiceMutation.mutate(id)} className="min-h-11 px-2 text-red-700">Xóa</button>}</div>
    </div>
    <div className="grid min-w-0 gap-4 md:grid-cols-2">
      <div className="min-w-0 rounded-xl bg-white p-4 shadow sm:p-5"><h3 className="font-semibold">Thông tin hóa đơn</h3><p className="mt-3">Ngày phát hành: {formatDate(invoice.issued_at)}</p><p>Hạn thanh toán: {formatDate(invoice.due_date)}</p><p className="break-words">Ghi chú: {invoice.note || '—'}</p></div>
      <div className="min-w-0 rounded-xl bg-white p-4 shadow sm:p-5">
        <h3 className="font-semibold">Tổng kết</h3>
        <dl className="mt-3 space-y-2">
          <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt>Tạm tính</dt><dd>{formatCurrency(invoice.subtotal)}</dd></div>
          <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt>Giảm giá</dt><dd>{formatCurrency(invoice.discount_amount)}</dd></div>
          <div className="flex flex-wrap justify-between gap-x-3 gap-y-1 font-medium"><dt>Tổng cộng</dt><dd>{formatCurrency(invoice.total_amount)}</dd></div>
          <div className="flex flex-wrap justify-between gap-x-3 gap-y-1"><dt>Đã thanh toán</dt><dd>{formatCurrency(invoice.paid_amount)}</dd></div>
          <div className="flex flex-wrap justify-between gap-x-3 gap-y-1 font-medium"><dt>Còn lại</dt><dd>{formatCurrency(remaining)}</dd></div>
        </dl>
      </div>
    </div>
    <div className="mt-6 min-w-0 rounded-xl bg-white p-4 shadow sm:p-5">
      <h3 className="font-semibold">Thông tin thanh toán</h3>
      {bank?.payment_qr_url && <div className="mt-3 grid justify-items-center gap-2">
        <PrivatePaymentQr profile={bank} className="h-48 w-48 max-w-full object-contain" />
        <p className="text-center text-sm text-slate-600">Quét mã QR để chuyển khoản thanh toán.</p>
      </div>}
      <dl className="mt-3 space-y-2 break-words text-sm">
        {bank?.bank_name && <div><dt className="text-slate-500">Ngân hàng</dt><dd>{bank.bank_name}</dd></div>}
        {bank?.bank_account_name && <div><dt className="text-slate-500">Chủ tài khoản</dt><dd>{bank.bank_account_name}</dd></div>}
        {bank?.bank_account_number && <div><dt className="text-slate-500">Số tài khoản</dt><dd>{bank.bank_account_number}</dd></div>}
      </dl>
      {!bank?.payment_qr_url && !bank?.bank_name && !bank?.bank_account_name && !bank?.bank_account_number && <p className="mt-3 text-sm text-slate-600">Vui lòng liên hệ chủ trọ để biết thông tin thanh toán.</p>}
    </div>
    <div className="mt-6 min-w-0 rounded-xl bg-white p-4 shadow sm:p-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <h3 className="font-semibold">Thanh toán</h3>
        {canPay && <div className="flex flex-wrap gap-2"><button onClick={() => setPayment({})} className="min-h-11 px-2 text-sm underline">Ghi nhận tiền mặt</button>{(invoice.payos_available || invoice.payos_payment_request) && <button disabled={payosMutation.isPending} onClick={openPayOS} className="min-h-11 rounded bg-blue-700 px-3 py-2 text-sm text-white disabled:opacity-60">{payosMutation.isPending ? 'Đang tạo QR...' : invoice.payos_payment_request ? 'Xem QR thanh toán' : 'Tạo QR thanh toán'}</button>}</div>}
        {canPay && !invoice.payos_available && !invoice.payos_payment_request && <p className="text-sm text-slate-600">Chủ trọ chưa cấu hình PayOS.</p>}
      </div>
      {invoice.payos_payment_request && <button type="button" onClick={openPayOS} className="mt-3 min-h-11 text-left text-sm text-blue-700 underline">QR payOS hiện có · {formatCurrency(invoice.payos_payment_request.amount)}</button>}
      {payosMutation.isError && <p className="mt-3 break-words text-sm text-red-700">{payosError || 'Không thể tạo yêu cầu thanh toán payOS.'}</p>}
      <p className="mt-3 flex flex-wrap items-center gap-2 text-sm">Trạng thái: <InvoiceStatusBadge status={invoice.status} /></p>
      {invoice.status === 'PAID' && <p className="mt-2 text-sm font-medium">Đã thanh toán đầy đủ{invoice.paid_at ? ` lúc ${formatDate(invoice.paid_at)}` : ''}.</p>}

      <div className="mt-3 space-y-3 md:hidden">
        {payments.map((currentPayment) => (
          <article key={currentPayment.id} className="min-w-0 rounded-lg border border-slate-200 p-3">
            <div className="flex flex-wrap justify-between gap-2"><p className="font-semibold">{formatCurrency(currentPayment.amount)}</p><p className="text-sm text-slate-600">{paymentMethodLabels[currentPayment.payment_method] || currentPayment.payment_method}</p></div>
            <dl className="mt-2 space-y-2 text-sm">
              <div><dt className="text-slate-500">Ngày thanh toán</dt><dd>{formatDate(currentPayment.paid_at)}</dd></div>
              <div><dt className="text-slate-500">Mã tham chiếu</dt><dd className="break-words">{currentPayment.reference_code || '—'}</dd></div>
              <div><dt className="text-slate-500">Ghi chú</dt><dd className="break-words">{currentPayment.note || '—'}</dd></div>
            </dl>
            {renderPaymentActions(currentPayment)}
          </article>
        ))}
      </div>
      <div className="mt-3 hidden min-w-0 max-w-full overflow-x-auto md:block">
        <table className="min-w-[760px] w-full text-sm">
          <thead><tr>{['Ngày thanh toán', 'Số tiền', 'Phương thức', 'Mã tham chiếu', 'Ghi chú', ''].map((label) => <th key={label} className="p-2 text-left">{label}</th>)}</tr></thead>
          <tbody>{payments.map((currentPayment) => <tr key={currentPayment.id} className="border-t"><td className="whitespace-nowrap p-2">{formatDate(currentPayment.paid_at)}</td><td className="whitespace-nowrap p-2">{formatCurrency(currentPayment.amount)}</td><td className="p-2">{paymentMethodLabels[currentPayment.payment_method] || currentPayment.payment_method}</td><td className="max-w-48 break-words p-2">{currentPayment.reference_code || '—'}</td><td className="max-w-64 break-words p-2">{currentPayment.note || '—'}</td><td className="p-2">{renderPaymentActions(currentPayment)}</td></tr>)}</tbody>
        </table>
      </div>
      {!paymentsQuery.isPending && !paymentsQuery.isError && payments.length === 0 && <p className="mt-3 text-sm">Chưa có thanh toán nào được ghi nhận.</p>}
    </div>
    <div className="mt-6 min-w-0 rounded-xl bg-white p-4 shadow sm:p-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <h3 className="font-semibold">Các khoản thu</h3>
        {canEditFinancials && <div className="flex flex-wrap gap-3"><button disabled={generateMutation.isPending} onClick={() => { const hasAuto = (invoice.items || []).some((currentItem) => currentItem.source === 'AUTO'); if (!hasAuto || window.confirm('Tạo lại các khoản thu tự động sẽ thay thế các khoản tự động hiện tại. Các khoản thủ công vẫn được giữ nguyên. Bạn có muốn tiếp tục?')) generateMutation.mutate() }} className="min-h-11 text-left text-sm underline disabled:opacity-60">{generateMutation.isPending ? 'Đang tạo khoản thu...' : 'Tạo các khoản thu tự động'}</button><button onClick={() => setItem({})} className="min-h-11 text-left text-sm underline">Thêm khoản thủ công</button></div>}
      </div>
      {generateMessage && <p className="mt-3 break-words text-sm text-slate-700">{generateMessage}</p>}
      <div className="mt-4 min-w-0 max-w-full overflow-x-auto">
        <table className="min-w-[560px] w-full text-sm"><tbody>{(invoice.items || []).map((currentItem) => <tr key={currentItem.id} className="border-t"><td className="max-w-80 break-words p-2">{currentItem.description}</td><td className="whitespace-nowrap p-2">{formatCurrency(currentItem.amount)}</td><td className="p-2">{canEditFinancials && currentItem.source !== 'AUTO' && <div className="flex gap-2"><button onClick={() => setItem(currentItem)} className="min-h-11 px-2 underline">Sửa</button><button onClick={() => window.confirm('Xóa khoản thu này?') && deleteItemMutation.mutate(currentItem.id)} className="min-h-11 px-2 text-red-700">Xóa</button></div>}</td></tr>)}</tbody></table>
      </div>
      {(invoice.items || []).some(currentItem => currentItem.type === 'SERVICE_TAX') && <p className="mt-3 text-sm text-slate-600">Khoản VAT đã ghi nhận được giữ theo hóa đơn gốc, không tự điều chỉnh dữ liệu lịch sử.</p>}
    </div>
    {payment && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4" role="dialog" aria-modal="true" aria-label={payment.id ? 'Sửa thanh toán' : 'Ghi nhận thanh toán'}><div className="max-h-[90dvh] w-full min-w-0 max-w-md overflow-y-auto rounded-xl bg-white p-4 sm:p-6"><div className="mb-4 flex items-center justify-between gap-2"><b>{payment.id ? 'Sửa' : 'Ghi nhận'} thanh toán</b><button type="button" onClick={() => setPayment(null)} aria-label="Đóng" className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center">✕</button></div><PaymentForm remaining={payment.id ? remaining + Number(payment.amount) : remaining} initialValues={payment} onSubmit={(payload) => paymentMutation.mutateAsync(payload)} label="Lưu thanh toán" /></div></div>}
    {activePayosRequest && payosRequest && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4" role="dialog" aria-modal="true" aria-label="Thanh toán qua payOS"><div className="max-h-[90dvh] w-full min-w-0 max-w-md overflow-y-auto rounded-xl bg-white p-4 text-center sm:p-6"><div className="flex items-center justify-between gap-2"><b className="text-left">Thanh toán qua payOS</b><button type="button" onClick={() => setPayosRequest(null)} aria-label="Đóng" className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center">✕</button></div>{invoice.status === 'PAID' ? <p className="mt-6 text-green-700">Hóa đơn đã được xác nhận thanh toán.</p> : <><p className="mt-3 text-sm text-slate-600">Quét mã QR hoặc mở trang thanh toán payOS. Trạng thái sẽ tự cập nhật sau khi payOS xác nhận.</p>{activePayosRequest.qr_code && <div className="mx-auto mt-5 w-fit max-w-full rounded-lg border bg-white p-3"><QRCodeSVG value={activePayosRequest.qr_code} size={220} level="M" className="block h-auto max-w-full" /></div>}<p className="mt-4 text-lg font-semibold">{formatCurrency(activePayosRequest.amount)}</p>{activePayosRequest.checkout_url && <a href={activePayosRequest.checkout_url} target="_blank" rel="noreferrer" className="mt-4 inline-flex min-h-11 items-center justify-center rounded bg-slate-900 px-4 py-2 text-sm text-white">Mở trang thanh toán</a>}<p className="mt-4 break-words text-xs text-slate-500">Mã đơn: {activePayosRequest.order_code}</p>{activePayosRequest.description && <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-left"><p className="text-xs text-slate-500">Nội dung chuyển khoản</p><p className="mt-1 break-words text-sm font-semibold text-slate-800">{activePayosRequest.description}</p></div>}</>}</div></div>}
    {item && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4" role="dialog" aria-modal="true" aria-label={item.id ? 'Sửa khoản thu' : 'Thêm khoản thu'}><div className="max-h-[90dvh] w-full min-w-0 max-w-md overflow-y-auto rounded-xl bg-white p-4 sm:p-6"><div className="mb-4 flex items-center justify-between gap-2"><h3 className="font-semibold">{item.id ? 'Sửa khoản thu' : 'Thêm khoản thu'}</h3><button type="button" onClick={() => setItem(null)} aria-label="Đóng" className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center">✕</button></div><InvoiceItemForm initialValues={item} onSubmit={(payload) => itemMutation.mutateAsync(payload)} /></div></div>}
    </div></section>
}
