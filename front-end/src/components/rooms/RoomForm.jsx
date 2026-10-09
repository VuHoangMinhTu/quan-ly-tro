import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { getAmenities } from '../../api/amenityApi'
import { getApiErrorMessage, applyApiFieldErrors } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import MoneyInput from '../ui/MoneyInput'

const optionalNumber = z.preprocess(
  (value) => (value === '' || value === null || Number.isNaN(value) ? undefined : Number(value)),
  z.number().min(0).optional(),
)

const optionalInteger = z.preprocess(
  (value) => (value === '' || value === null || Number.isNaN(value) ? undefined : Number(value)),
  z.number().int('Số người phải là số nguyên').min(1, 'Tối đa ít nhất 1 người').optional(),
)

const requiredNumber = z.preprocess(
  (value) => (value === '' || value === null || Number.isNaN(value) ? undefined : Number(value)),
  z.number({ error: 'Vui lòng nhập giá thuê' }).min(0, 'Giá thuê phải từ 0 trở lên'),
)

const schema = z.object({
  room_code: z.string().trim().min(1, 'Vui lòng nhập mã phòng').max(50),
  room_name: z.string().max(255).optional(),
  area: optionalNumber,
  monthly_rent: requiredNumber,
  max_tenants: optionalInteger,
  status: z.enum(['AVAILABLE', 'RENTED', 'RESERVED', 'MAINTENANCE']),
  description: z.string().optional(),
  amenity_ids: z.array(z.number().int()).default([]),
})

const emptyValues = {
  room_code: '', room_name: '', area: '', monthly_rent: '', max_tenants: '', status: 'AVAILABLE', description: '', amenity_ids: [],
}

export default function RoomForm({ initialValues = emptyValues, onSubmit, submitLabel }) {
  const [submitError, setSubmitError] = useState('')
  const amenitiesQuery = useQuery({ queryKey: roomKeys.amenities, queryFn: getAmenities })
  const form = useForm({ resolver: zodResolver(schema), defaultValues: emptyValues })

  useEffect(() => {
    form.reset({
      room_code: initialValues.room_code || '',
      room_name: initialValues.room_name || '',
      area: initialValues.area ?? '',
      monthly_rent: initialValues.monthly_rent ?? '',
      max_tenants: initialValues.max_tenants ?? '',
      status: initialValues.status || 'AVAILABLE',
      description: initialValues.description || '',
      amenity_ids: (initialValues.amenities?.map((amenity) => amenity.id) || initialValues.amenity_ids || []).map(Number),
    })
  }, [form, initialValues])

  const handleSubmit = async (values) => {
    setSubmitError('')
    try {
      await onSubmit({
        ...values,
        room_name: values.room_name?.trim() || null,
        description: values.description?.trim() || null,
        amenity_ids: (values.amenity_ids || []).map(Number),
      })
    } catch (error) {
      const applied = applyApiFieldErrors(error, form.setError, (field) => field.startsWith('amenity_ids.') ? 'amenity_ids' : field)
      if (!applied) setSubmitError(getApiErrorMessage(error))
    }
  }

  const { errors, isSubmitting } = form.formState
  const amenities = amenitiesQuery.data?.data?.data || []

  return (
    <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-6 rounded-xl bg-white p-6 shadow-sm">
      <div className="grid gap-5 md:grid-cols-2">
        <div>
          <label htmlFor="room-code" className="block text-sm font-medium text-slate-700">Mã phòng</label>
          <input id="room-code" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('room_code')} />
          {errors.room_code && <p className="mt-1 text-sm text-red-600">{errors.room_code.message}</p>}
        </div>
        <div>
          <label htmlFor="room-name" className="block text-sm font-medium text-slate-700">Tên phòng <span className="font-normal text-slate-500">(không bắt buộc)</span></label>
          <input id="room-name" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('room_name')} />
          {errors.room_name && <p className="mt-1 text-sm text-red-600">{errors.room_name.message}</p>}
        </div>
        <div>
          <label htmlFor="room-area" className="block text-sm font-medium text-slate-700">Diện tích (m²)</label>
          <input id="room-area" type="number" min="0" step="0.01" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('area', { valueAsNumber: true })} />
          {errors.area && <p className="mt-1 text-sm text-red-600">{errors.area.message}</p>}
        </div>
        <div>
          <label htmlFor="room-rent" className="block text-sm font-medium text-slate-700">Giá thuê hàng tháng</label>
          <Controller name="monthly_rent" control={form.control} render={({ field }) => <MoneyInput {...field} id="room-rent" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="0" />} />
          {errors.monthly_rent && <p className="mt-1 text-sm text-red-600">{errors.monthly_rent.message}</p>}
        </div>
        <div>
          <label htmlFor="room-max-tenants" className="block text-sm font-medium text-slate-700">Số người tối đa</label>
          <input id="room-max-tenants" type="number" min="1" step="1" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('max_tenants', { valueAsNumber: true })} />
          {errors.max_tenants && <p className="mt-1 text-sm text-red-600">{errors.max_tenants.message}</p>}
        </div>
        <div>
          <label htmlFor="room-status" className="block text-sm font-medium text-slate-700">Trạng thái</label>
          <select id="room-status" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('status')}>
            <option value="AVAILABLE">Còn trống</option>
            <option value="RENTED">Đã thuê</option>
            <option value="RESERVED">Đã đặt</option>
            <option value="MAINTENANCE">Bảo trì</option>
          </select>
          {errors.status && <p className="mt-1 text-sm text-red-600">{errors.status.message}</p>}
        </div>
      </div>

      <div>
        <label htmlFor="room-description" className="block text-sm font-medium text-slate-700">Mô tả <span className="font-normal text-slate-500">(không bắt buộc)</span></label>
        <textarea id="room-description" rows="4" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" {...form.register('description')} />
        {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description.message}</p>}
      </div>

      <fieldset>
        <legend className="text-sm font-medium text-slate-700">Tiện nghi <span className="font-normal text-slate-500">(không bắt buộc)</span></legend>
        {amenitiesQuery.isPending && <p className="mt-2 text-sm text-slate-500">Đang tải tiện nghi...</p>}
        {amenitiesQuery.isError && <p className="mt-2 text-sm text-slate-600">{getApiErrorMessage(amenitiesQuery.error)} Bạn vẫn có thể lưu phòng mà không chọn thêm tiện nghi.</p>}
        {amenities.length > 0 && (
          <Controller
            name="amenity_ids"
            control={form.control}
            render={({ field }) => {
              const selectedIds = field.value || []

              return <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {amenities.map((amenity, index) => {
                  const amenityId = Number(amenity.id)

                  return <label key={amenity.id} className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                    <input
                      type="checkbox"
                      name={field.name}
                      value={amenityId}
                      ref={index === 0 ? field.ref : undefined}
                      checked={selectedIds.includes(amenityId)}
                      onBlur={field.onBlur}
                      onChange={(event) => field.onChange(event.target.checked
                        ? [...new Set([...selectedIds, amenityId])]
                        : selectedIds.filter((id) => id !== amenityId))}
                    />
                    {amenity.name}
                  </label>
                })}
              </div>
            }}
          />
        )}
        {errors.amenity_ids && <p className="mt-1 text-sm text-red-600">{errors.amenity_ids.message}</p>}
      </fieldset>

      {submitError && <p className="text-sm text-red-600">{submitError}</p>}
      <button disabled={isSubmitting} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60">
        {isSubmitting ? 'Đang lưu...' : submitLabel}
      </button>
    </form>
  )
}
