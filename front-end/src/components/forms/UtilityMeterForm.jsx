import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { getRoomServices } from '../../api/roomServiceApi'
import { roomKeys } from '../../api/roomKeys'
import { getMeterServices } from '../../utils/roomServices'
import { SERVICE_TYPE_LABELS } from '../../utils/service'

const emptyValues = {}

export default function UtilityMeterForm({ roomId, initialValues = emptyValues, onSubmit, label }) {
  const servicesQuery = useQuery({
    queryKey: roomKeys.services(roomId),
    queryFn: () => getRoomServices(roomId),
  })
  const form = useForm({ defaultValues: { service_id: '', meter_code: '', initial_reading: '', is_active: true } })
  const [error, setError] = useState('')
  useEffect(() => form.reset({
    service_id: initialValues.service_id != null ? String(initialValues.service_id) : '',
    meter_code: initialValues.meter_code || '',
    initial_reading: initialValues.initial_reading ?? '',
    is_active: initialValues.is_active ?? true,
  }), [form, initialValues])
  const services = getMeterServices(servicesQuery.data?.data?.data || [])
  const isEditing = Boolean(initialValues.id)
  const currentServiceId = Number(initialValues.service_id)
  const hasHistoricalService = isEditing && !services.some((service) => Number(service.id) === currentServiceId)
  const canSubmit = !form.formState.isSubmitting && (isEditing || (!servicesQuery.isPending && !servicesQuery.isError && services.length > 0))
  const submit = async (values) => {
    setError('')
    const serviceId = Number(values.service_id)
    const retainingCurrentService = isEditing && serviceId === currentServiceId
    const reactivatingHistoricalMeter = retainingCurrentService && !initialValues.is_active && values.is_active
    if ((!retainingCurrentService || reactivatingHistoricalMeter) && !services.some((service) => Number(service.id) === serviceId)) {
      form.setError('service_id', { type: 'validate', message: 'Vui lòng chọn dịch vụ đang áp dụng cho phòng và tính theo đơn vị hoặc bậc thang.' })
      return
    }
    try {
      await onSubmit({
        ...values,
        service_id: serviceId,
        initial_reading: Number(values.initial_reading),
        meter_code: values.meter_code || null,
      })
    } catch (requestError) {
      applyApiFieldErrors(requestError, form.setError)
      setError(getApiErrorMessage(requestError))
    }
  }

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
      <div>
        <label htmlFor="meter-service" className="block text-sm font-medium">Dịch vụ *</label>
        <select id="meter-service" disabled={servicesQuery.isPending || servicesQuery.isError || form.formState.isSubmitting} className="mt-1 w-full rounded border p-2 disabled:bg-slate-50" {...form.register('service_id', { required: 'Vui lòng chọn dịch vụ.' })}>
          <option value="">Chọn dịch vụ</option>
          {hasHistoricalService && <option disabled value={String(currentServiceId)}>{initialValues.service?.name || 'Dịch vụ hiện tại'} (không còn áp dụng)</option>}
          {services.map((service) => <option key={service.id} value={String(service.id)}>{SERVICE_TYPE_LABELS[service.type] || 'Dịch vụ'} · {service.name}{service.unit ? ` - ${service.unit}` : ''}</option>)}
        </select>
        {form.formState.errors.service_id && <p className="mt-1 text-sm text-red-600">{form.formState.errors.service_id.message}</p>}
        {servicesQuery.isPending ? <p className="mt-2 text-xs text-slate-500">Đang tải dịch vụ của phòng...</p>
          : servicesQuery.isError ? <p className="mt-2 text-sm text-red-600">{getApiErrorMessage(servicesQuery.error)}</p>
            : services.length === 0 && <p className="mt-2 text-sm text-slate-600">Chưa có dịch vụ phù hợp. Hãy chọn dịch vụ Theo đơn vị hoặc Bậc thang ở phần Dịch vụ áp dụng trước.</p>}
        {hasHistoricalService && <p className="mt-2 text-xs text-slate-500">Có thể chỉnh thông tin hoặc ngừng đồng hồ cũ. Để bật lại, cần áp dụng lại dịch vụ phù hợp cho phòng.</p>}
      </div>
      <div><label htmlFor="meter-code" className="block text-sm font-medium">Mã đồng hồ</label><input id="meter-code" placeholder="Không bắt buộc" className="mt-1 w-full rounded border p-2" {...form.register('meter_code')} />{form.formState.errors.meter_code && <p className="mt-1 text-sm text-red-600">{form.formState.errors.meter_code.message}</p>}</div>
      <div><label htmlFor="meter-initial" className="block text-sm font-medium">Chỉ số ban đầu *</label><input id="meter-initial" type="number" min="0" step="0.01" placeholder="0" className="mt-1 w-full rounded border p-2" {...form.register('initial_reading', { required: 'Vui lòng nhập chỉ số ban đầu.', min: { value: 0, message: 'Chỉ số ban đầu không được âm.' } })} />{form.formState.errors.initial_reading && <p className="mt-1 text-sm text-red-600">{form.formState.errors.initial_reading.message}</p>}</div>
      <label className="flex gap-2 text-sm"><input type="checkbox" {...form.register('is_active')} /> Đang hoạt động</label>{form.formState.errors.is_active && <p className="mt-1 text-sm text-red-600">{form.formState.errors.is_active.message}</p>}
      {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      <button type="submit" disabled={!canSubmit} className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-60">{form.formState.isSubmitting ? 'Đang lưu...' : label}</button>
    </form>
  )
}
