import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { formatCurrency } from '../../utils/formatters'
import MoneyInput from '../ui/MoneyInput'

export default function PaymentForm({ remaining, initialValues = {}, onSubmit, label }) {
  const form = useForm({
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

    if (Number(values.amount) <= 0) return setError('Số tiền phải lớn hơn 0.')
    if (Number(values.amount) > remaining) return setError('Số tiền thanh toán không được vượt quá số tiền còn lại.')

    try {
      await onSubmit({
        ...values,
        amount: Number(values.amount),
        reference_code: values.reference_code || null,
        note: values.note || null,
      })
    } catch (requestError) {
      const message = requestError.response?.data?.errors?.amount?.[0]
      setError(message?.includes('exceeds') ? 'Số tiền thanh toán không được vượt quá số tiền còn lại.' : message || requestError.response?.data?.message || 'Không thể lưu thanh toán.')
    }
  }

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-3">
      <p className="text-sm">Còn phải thanh toán: {formatCurrency(remaining)}</p>
      <div>
        <label className="block text-sm font-medium" htmlFor="payment-amount">Số tiền thanh toán</label>
        <Controller name="amount" control={form.control} render={({ field }) => <MoneyInput {...field} id="payment-amount" placeholder="0" className="mt-1 w-full rounded border p-2" />} />
      </div>
      <select className="w-full rounded border p-2" {...form.register('payment_method')}><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option><option value="CARD">Thẻ</option><option value="OTHER">Khác</option></select>
      <input type="datetime-local" className="w-full rounded border p-2" {...form.register('paid_at')} />
      <input placeholder="Mã tham chiếu" className="w-full rounded border p-2" {...form.register('reference_code')} />
      <textarea placeholder="Ghi chú" className="w-full rounded border p-2" {...form.register('note')} />
      {error && <p className="text-sm text-red-600">{error}</p>}
      <button className="rounded bg-slate-900 px-4 py-2 text-sm text-white">{label}</button>
    </form>
  )
}
