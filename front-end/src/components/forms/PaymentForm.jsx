import { handleFormSubmit } from '../../notifications/formFeedback'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { paymentFormSchema } from '../../utils/formValidation'
import { formatCurrency } from '../../utils/formatters'
import MoneyInput from '../ui/MoneyInput'

export default function PaymentForm({ remaining, initialValues = {}, onSubmit, label }) {
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

    try {
      await onSubmit({
        ...values,
        amount: Number(values.amount),
        reference_code: values.reference_code || null,
        note: values.note || null,
      })
    } catch (requestError) {
      if (!applyApiFieldErrors(requestError, form.setError)) setError(getApiErrorMessage(requestError))
    }
  }

  return (
    <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 break-words space-y-3">
      <p className="text-sm">Còn phải thanh toán: {formatCurrency(remaining)}</p>
      <div>
        <label className="block text-sm font-medium" htmlFor="payment-amount">Số tiền thanh toán</label>
        <Controller name="amount" control={form.control} render={({ field }) => <MoneyInput {...field} id="payment-amount" placeholder="0" className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 rounded border p-2" />} />{form.formState.errors.amount && <p className="mt-1 text-sm text-red-600">{form.formState.errors.amount.message}</p>}
      </div>
      <select className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('payment_method')}><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option><option value="CARD">Thẻ</option><option value="OTHER">Khác</option></select>{form.formState.errors.payment_method && <p className="mt-1 text-sm text-red-600">{form.formState.errors.payment_method.message}</p>}
      <input type="datetime-local" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('paid_at')} />{form.formState.errors.paid_at && <p className="mt-1 text-sm text-red-600">{form.formState.errors.paid_at.message}</p>}
      <input placeholder="Mã tham chiếu" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('reference_code')} />{form.formState.errors.reference_code && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reference_code.message}</p>}
      <textarea placeholder="Ghi chú" className="min-h-11 sm:min-h-0 w-full min-w-0 rounded border p-2" {...form.register('note')} />{form.formState.errors.note && <p className="mt-1 text-sm text-red-600">{form.formState.errors.note.message}</p>}
      {error && <p className="text-sm text-red-600">{error}</p>}
      <button disabled={form.formState.isSubmitting} className="w-full sm:w-auto min-h-11 sm:min-h-0 rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{form.formState.isSubmitting ? 'Đang lưu...' : label}</button>
    </form>
  )
}
