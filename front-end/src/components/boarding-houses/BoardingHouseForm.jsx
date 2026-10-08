import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

const schema = z.object({
  name: z.string().trim().min(1, 'Vui lòng nhập tên nhà trọ').max(255),
  address: z.string().trim().min(1, 'Vui lòng nhập địa chỉ').max(500),
  description: z.string().optional(),
})

const emptyValues = { name: '', address: '', description: '' }

export default function BoardingHouseForm({ initialValues = emptyValues, onSubmit, submitLabel }) {
  const [submitError, setSubmitError] = useState('')
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    form.reset({
      name: initialValues.name || '',
      address: initialValues.address || '',
      description: initialValues.description || '',
    })
  }, [form, initialValues])

  const handleSubmit = async (values) => {
    setSubmitError('')

    try {
      await onSubmit({
        ...values,
        description: values.description?.trim() || null,
      })
    } catch (error) {
      const errors = error.response?.data?.errors

      if (errors) {
        Object.entries(errors).forEach(([field, messages]) => {
          form.setError(field, { type: 'server', message: messages[0] })
        })
      }

      setSubmitError(getApiErrorMessage(error))
    }
  }

  const { errors, isSubmitting } = form.formState

  return (
    <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-5 rounded-xl bg-white p-6 shadow-sm">
      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-name">
          Tên nhà trọ
        </label>
        <input
          id="boarding-house-name"
          className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          {...form.register('name')}
        />
        {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>}
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700" htmlFor="boarding-house-address">
          Địa chỉ
        </label>
        <input
          id="boarding-house-address"
          className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
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
          className="mt-1 w-full resize-y rounded-lg border border-slate-300 px-3 py-2 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          {...form.register('description')}
        />
        {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description.message}</p>}
      </div>

      {submitError && <p className="text-sm text-red-600">{submitError}</p>}

      <button
        disabled={isSubmitting}
        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {isSubmitting ? 'Đang lưu...' : submitLabel}
      </button>
    </form>
  )
}
