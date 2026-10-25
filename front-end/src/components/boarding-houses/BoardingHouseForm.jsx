import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { getApiErrorMessage, applyApiFieldErrors } from '../../api/getApiErrorMessage'

const schema = z.object({
  name: z.string().trim().min(1, 'Vui lòng nhập tên nhà trọ').max(255, 'Tên nhà trọ tối đa 255 ký tự.'),
  address: z.string().trim().min(1, 'Vui lòng nhập địa chỉ').max(500, 'Địa chỉ tối đa 500 ký tự.'),
  ward: z.string().trim().max(255, 'Phường/xã tối đa 255 ký tự.'),
  description: z.string().optional(),
})

const emptyValues = { name: '', address: '', ward: '', description: '' }

export default function BoardingHouseForm({ initialValues = emptyValues, onSubmit, submitLabel }) {
  const [submitError, setSubmitError] = useState('')
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: emptyValues,
    mode: 'onTouched',
  })

  useEffect(() => {
    form.reset({
      name: initialValues.name || '',
      address: initialValues.address || '',
      ward: initialValues.ward || '',
      description: initialValues.description || '',
    })
  }, [form, initialValues])

  const handleSubmit = async (values) => {
    setSubmitError('')

    try {
      await onSubmit({
        ...values,
        description: values.description?.trim() || null,
        ward: values.ward?.trim() || null,
      })
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError)) setSubmitError(getApiErrorMessage(error))
    }
  }

  const { errors, isSubmitting } = form.formState

  return (
    <form noValidate onSubmit={form.handleSubmit(handleSubmit)} className="min-w-0 break-words space-y-5 rounded-xl bg-white p-4 sm:p-6 shadow-sm">
      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-name">
          Tên nhà trọ
        </label>
        <input
          id="boarding-house-name"
          className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          {...form.register('name')}
        />
        {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>}
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-ward">Phường/xã <span className="font-normal text-slate-500">(không bắt buộc)</span></label>
        <input id="boarding-house-ward" placeholder="VD: Phường Vườn Lài" className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" {...form.register('ward')} />
        <p className="mt-1 text-xs text-slate-500">Dùng tên phường/xã này làm địa danh đầu văn bản ở nhờ.</p>
        {errors.ward && <p className="mt-1 text-sm text-red-600">{errors.ward.message}</p>}
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-address">
          Địa chỉ
        </label>
        <input
          id="boarding-house-address"
          className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          {...form.register('address')}
        />
        {errors.address && <p className="mt-1 text-sm text-red-600">{errors.address.message}</p>}
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-description">
          Mô tả <span className="font-normal text-slate-500">(không bắt buộc)</span>
        </label>
        <textarea
          id="boarding-house-description"
          rows="4"
          className="min-h-11 sm:min-h-0 mt-1 w-full min-w-0 resize-y rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          {...form.register('description')}
        />
        {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description.message}</p>}
      </div>

      {submitError && <p className="text-sm text-red-600">{submitError}</p>}

      <button
        disabled={isSubmitting}
        className="w-full sm:w-auto min-h-11 sm:min-h-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {isSubmitting ? 'Đang lưu...' : submitLabel}
      </button>
    </form>
  )
}
