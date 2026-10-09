import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { getApiErrorMessage, applyApiFieldErrors } from '../../api/getApiErrorMessage'
import DateInput from '../ui/DateInput'
import { formatDateForApi, formatDateForDisplay, isNotFutureDisplayDate, isValidDisplayDate } from '../../utils/date'

const optionalDate = z.string()
  .refine((value) => !value || isValidDisplayDate(value), 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy')
  .refine((value) => !value || isNotFutureDisplayDate(value), 'Ngày không được ở tương lai')
const schema = z.object({
  full_name: z.string().trim().min(1, 'Vui lòng nhập họ tên').max(255),
  phone: z.string().max(20).optional(),
  email: z.string().email('Email không hợp lệ').max(255).or(z.literal('')),
  date_of_birth: optionalDate,
  gender: z.enum(['MALE', 'FEMALE', 'OTHER', '']),
  identity_number: z.string().max(30).optional(),
  identity_issue_place: z.string().max(255).optional(),
  identity_issue_date: optionalDate,
  permanent_address: z.string().max(500).optional(),
})
const emptyValues = { full_name: '', phone: '', email: '', date_of_birth: '', gender: '', identity_number: '', identity_issue_place: '', identity_issue_date: '', permanent_address: '' }

export default function TenantForm({ initialValues = emptyValues, onSubmit, submitLabel }) {
  const [submitError, setSubmitError] = useState('')
  const form = useForm({ resolver: zodResolver(schema), defaultValues: emptyValues })
  useEffect(() => { form.reset({ ...emptyValues, ...initialValues, date_of_birth: formatDateForDisplay(initialValues.date_of_birth), identity_issue_date: formatDateForDisplay(initialValues.identity_issue_date), gender: initialValues.gender || '' }) }, [form, initialValues])
  const submit = async (values) => {
    setSubmitError('')
    const payload = {
      ...Object.fromEntries(Object.entries(values).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value])),
      date_of_birth: formatDateForApi(values.date_of_birth),
      identity_issue_date: formatDateForApi(values.identity_issue_date),
    }
    try { await onSubmit(payload) } catch (error) {
      applyApiFieldErrors(error, form.setError)
      setSubmitError(getApiErrorMessage(error))
    }
  }
  const { errors, isSubmitting } = form.formState
  const fields = [
    ['full_name', 'Họ tên', 'text'], ['phone', 'Số điện thoại', 'tel'], ['email', 'Email', 'email'], ['date_of_birth', 'Ngày sinh', 'text'], ['identity_number', 'CCCD / Số định danh', 'text'], ['identity_issue_place', 'Nơi cấp', 'text'], ['identity_issue_date', 'Ngày cấp', 'text'],
  ]
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-6 rounded-xl bg-white p-6 shadow-sm"><div className="grid gap-5 md:grid-cols-2">{fields.map(([field, label, type]) => <div key={field}><label htmlFor={field} className="block text-sm font-medium text-slate-700">{label}{field === 'full_name' && ' *'}</label>{field.includes('date') ? <Controller name={field} control={form.control} render={({ field: dateField }) => <DateInput {...dateField} placeholder="dd/mm/yyyy" invalid={Boolean(errors[field])} />} /> : <input id={field} type={type} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register(field)} />}{errors[field] && <p className="mt-1 text-sm text-red-600">{errors[field].message}</p>}</div>)}<div><label htmlFor="gender" className="block text-sm font-medium text-slate-700">Giới tính</label><select id="gender" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('gender')}><option value="">Chưa cập nhật</option><option value="MALE">Nam</option><option value="FEMALE">Nữ</option><option value="OTHER">Khác</option></select>{errors.gender && <p className="mt-1 text-sm text-red-600">{errors.gender.message}</p>}</div></div><div><label htmlFor="permanent_address" className="block text-sm font-medium text-slate-700">Địa chỉ thường trú</label><textarea id="permanent_address" rows="3" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('permanent_address')} />{errors.permanent_address && <p className="mt-1 text-sm text-red-600">{errors.permanent_address.message}</p>}</div>{submitError && <p className="text-sm text-red-600">{submitError}</p>}<button disabled={isSubmitting} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60">{isSubmitting ? 'Đang lưu...' : submitLabel}</button></form>
}
