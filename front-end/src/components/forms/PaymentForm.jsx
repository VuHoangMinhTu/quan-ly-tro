import { handleFormSubmit } from '../../notifications/formFeedback'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { paymentFormSchema } from '../../utils/formValidation'
import { formatCurrency } from '../../utils/formatters'
import { createManualPaymentAttempt } from '../../api/manualPaymentAttempt.mjs'
import MoneyInput from '../ui/MoneyInput'

export default function PaymentForm({ remaining, initialValues = {}, onSubmit, label, idempotent = false, onAttemptState }) {
  const form = useForm({
    resolver: zodResolver(paymentFormSchema(remaining)),
    mode: 'onSubmit',
    defaultValues: {
      amount: remaining,
      payment_method: 'CASH',
      paid_at: new Date().toISOString().slice(0, 16),
      reference_code: '',
      note: '',
    },
  })
  const [error, setError] = useState('')
  const [attempt] = useState(createManualPaymentAttempt)
  const [uncertain, setUncertain] = useState(false)
  const [sending, setSending] = useState(false)

  useEffect(() => {
    form.reset({
      amount: initialValues.amount ?? remaining,
      payment_method: initialValues.payment_method || 'CASH',
      paid_at: initialValues.paid_at?.slice(0, 16) || new Date().toISOString().slice(0, 16),
      reference_code: initialValues.reference_code || '',
      note: initialValues.note || '',
    })
  }, [form, initialValues, remaining])

  const submit = async (values) => {
    setError('')
    setSending(true)
    if (idempotent) onAttemptState?.(true)

    try {
      const payload = values && {
        ...values,
        amount: Number(values.amount),
        reference_code: values.reference_code || null,
        note: values.note || null,
      }
      if (idempotent) await attempt.submit(payload, onSubmit)
      else await onSubmit(payload)
    } catch (requestError) {
      if (idempotent && attempt.uncertain) {
        setError('Chưa xác định khoản thu đã được lưu hay chưa. Dùng nút Thử lại để gửi đúng giao dịch này. Nếu đã tải lại trang, hãy đối chiếu lịch sử trước khi ghi thêm.')
      } else if (!applyApiFieldErrors(requestError, form.setError)) setError(getApiErrorMessage(requestError))
    } finally {
      setSending(false)
      setUncertain(attempt.uncertain)
      if (idempotent) onAttemptState?.(attempt.uncertain)
    }
  }

  return (
    <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 break-words space-y-3">
      <p className="text-sm">Còn phải thanh toán: {formatCurrency(remaining)}</p>
      <fieldset disabled={sending || uncertain} className="min-w-0 space-y-3">
      <div>
        <label className="block text-sm font-medium" htmlFor="payment-amount">Số tiền thanh toán</label>
        <Controller name="amount" control={form.control} render={({ field }) => <MoneyInput {...field} id="payment-amount" placeholder="0" className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 rounded border p-2" />} />{form.formState.errors.amount && <p className="mt-1 text-sm text-red-600">{form.formState.errors.amount.message}</p>}
      </div>
      <select className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('payment_method')}><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option><option value="CARD">Thẻ</option><option value="OTHER">Khác</option></select>{form.formState.errors.payment_method && <p className="mt-1 text-sm text-red-600">{form.formState.errors.payment_method.message}</p>}
      <input type="datetime-local" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('paid_at')} />{form.formState.errors.paid_at && <p className="mt-1 text-sm text-red-600">{form.formState.errors.paid_at.message}</p>}
      <input placeholder="Mã tham chiếu" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('reference_code')} />{form.formState.errors.reference_code && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reference_code.message}</p>}
      <textarea placeholder="Ghi chú" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('note')} />{form.formState.errors.note && <p className="mt-1 text-sm text-red-600">{form.formState.errors.note.message}</p>}
      </fieldset>
      {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      {uncertain
        ? <button type="button" onClick={() => submit()} disabled={sending} className="min-h-11 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{sending ? 'Đang lưu...' : 'Thử lại giao dịch'}</button>
        : <button disabled={sending || form.formState.isSubmitting} className="w-full sm:w-auto min-h-11 sm:min-h-0 rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{sending || form.formState.isSubmitting ? 'Đang lưu...' : label}</button>}
    </form>
  )
}
