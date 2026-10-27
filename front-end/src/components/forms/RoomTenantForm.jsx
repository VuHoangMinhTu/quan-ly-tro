import { handleFormSubmit } from '../../notifications/formFeedback'
import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { getApiErrorMessage, applyApiFieldErrors } from '../../api/getApiErrorMessage'
import { tenantKeys } from '../../api/tenantKeys'
import { getTenants } from '../../api/tenantApi'
import { getBeds } from '../../api/roomApi'
import DateInput from '../ui/DateInput'
import { formatDateForApi, formatDateForDisplay, getTodayForDisplay, isValidDisplayDate } from '../../utils/date'

const date = z.string().min(1, 'Vui lòng nhập ngày').refine(isValidDisplayDate, 'Ngày phải theo dd/mm/yyyy và hợp lệ')
const schema = z.object({
  tenant_id: z.coerce.number().int().positive('Vui lòng chọn người thuê'), bed_id: z.string().optional(), is_primary: z.boolean(),
  move_in_date: date, move_out_date: z.string(), status: z.enum(['ACTIVE', 'MOVED_OUT']),
}).superRefine((value, context) => {
  if (value.status === 'ACTIVE' && value.move_out_date) context.addIssue({ code: 'custom', path: ['move_out_date'], message: 'Người đang ở không có ngày chuyển đi' })
  if (value.status === 'MOVED_OUT' && !value.move_out_date) context.addIssue({ code: 'custom', path: ['move_out_date'], message: 'Vui lòng nhập ngày chuyển đi' })
  if (value.move_out_date && !isValidDisplayDate(value.move_out_date)) context.addIssue({ code: 'custom', path: ['move_out_date'], message: 'Ngày phải theo dd/mm/yyyy và hợp lệ' })
  if (value.move_out_date && isValidDisplayDate(value.move_out_date) && formatDateForApi(value.move_out_date) < formatDateForApi(value.move_in_date)) context.addIssue({ code: 'custom', path: ['move_out_date'], message: 'Ngày chuyển đi phải sau ngày vào ở' })
})
const defaults = { tenant_id: '', bed_id: '', is_primary: false, move_in_date: getTodayForDisplay(), move_out_date: '', status: 'ACTIVE' }

export default function RoomTenantForm({ initialValues = defaults, onSubmit, submitLabel, room }) {
  const [error, setError] = useState('')
  const tenantsQuery = useQuery({ queryKey: tenantKeys.all, queryFn: getTenants })
  const bedsQuery = useQuery({ queryKey: ['beds', room?.id], queryFn: () => getBeds(room.id), enabled: room?.room_type === 'DORMITORY' })
  const form = useForm({ resolver: zodResolver(schema), defaultValues: defaults })
  useEffect(() => form.reset({ ...defaults, ...initialValues, bed_id: initialValues.bed_id == null ? '' : String(initialValues.bed_id), move_in_date: formatDateForDisplay(initialValues.move_in_date || defaults.move_in_date), move_out_date: formatDateForDisplay(initialValues.move_out_date), is_primary: Boolean(initialValues.is_primary) }), [form, initialValues])
  const submit = async (values) => {
    setError('')
    if (room?.room_type === 'DORMITORY' && values.status === 'ACTIVE' && !values.bed_id) { form.setError('bed_id', { message: 'Vui lòng chọn giường.' }); return }
    try { await onSubmit({ ...values, bed_id: room?.room_type === 'DORMITORY' && values.bed_id ? Number(values.bed_id) : null, move_in_date: formatDateForApi(values.move_in_date), move_out_date: formatDateForApi(values.move_out_date) }) }
    catch (apiError) { applyApiFieldErrors(apiError, form.setError); setError(getApiErrorMessage(apiError)) }
  }
  const { errors, isSubmitting } = form.formState
  const tenants = tenantsQuery.data?.data?.data || []
  const beds = bedsQuery.data?.data?.data || []
  return <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 space-y-4 break-words">
    <div><label className="block text-sm font-medium">Người thuê</label><select className="mt-1 min-h-11 w-full rounded-lg border p-2" {...form.register('tenant_id', { valueAsNumber: true })}><option value="">Chọn người thuê</option>{tenants.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.full_name}{tenant.phone ? ` · ${tenant.phone}` : ''}</option>)}</select>{errors.tenant_id && <p className="text-sm text-red-600">{errors.tenant_id.message}</p>}</div>
    {room?.room_type === 'DORMITORY' && <div><label className="block text-sm font-medium">Giường / Chỗ ở *</label><select className="mt-1 min-h-11 w-full rounded-lg border p-2" {...form.register('bed_id')}><option value="">Chọn giường</option>{beds.filter((bed) => bed.id === Number(form.watch('bed_id')) || (bed.status === 'AVAILABLE' && !bed.room_tenants?.some((membership) => membership.status === 'ACTIVE'))).map((bed) => <option key={bed.id} value={String(bed.id)}>{bed.bed_code}</option>)}</select>{errors.bed_id && <p className="text-sm text-red-600">{errors.bed_id.message}</p>}{bedsQuery.isError && <p className="text-sm text-red-600">{getApiErrorMessage(bedsQuery.error)}</p>}</div>}
    <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" {...form.register('is_primary')} /> Người chính</label>{errors.is_primary && <p className="text-sm text-red-600">{errors.is_primary.message}</p>}
    <div className="grid gap-4 sm:grid-cols-2"><div><label className="block text-sm font-medium">Ngày vào ở</label><Controller name="move_in_date" control={form.control} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(errors.move_in_date)} />} />{errors.move_in_date && <p className="text-sm text-red-600">{errors.move_in_date.message}</p>}</div><div><label className="block text-sm font-medium">Trạng thái</label><select className="mt-1 min-h-11 w-full rounded-lg border p-2" {...form.register('status')}><option value="ACTIVE">Đang ở</option><option value="MOVED_OUT">Đã chuyển đi</option></select></div><div><label className="block text-sm font-medium">Ngày chuyển đi</label><Controller name="move_out_date" control={form.control} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(errors.move_out_date)} />} />{errors.move_out_date && <p className="text-sm text-red-600">{errors.move_out_date.message}</p>}</div></div>
    {tenantsQuery.isError && <p className="text-sm text-red-600">{getApiErrorMessage(tenantsQuery.error)}</p>}{error && <p className="text-sm text-red-600">{error}</p>}
    <button disabled={isSubmitting || tenantsQuery.isPending} className="min-h-11 w-full rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-60 sm:w-auto">{isSubmitting ? 'Đang lưu...' : submitLabel}</button>
  </form>
}
