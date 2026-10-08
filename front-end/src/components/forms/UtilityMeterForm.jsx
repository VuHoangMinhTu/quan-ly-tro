import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { getServices } from '../../api/serviceApi'
import { serviceKeys } from '../../api/serviceKeys'

export default function UtilityMeterForm({ boardingHouseId, initialValues = {}, onSubmit, label }) {
  const servicesQuery = useQuery({ queryKey: serviceKeys.byBoardingHouse(boardingHouseId), queryFn: () => getServices(boardingHouseId) })
  const form = useForm({ defaultValues: { service_id: '', meter_code: '', initial_reading: '', is_active: true } })
  const [error, setError] = useState('')
  useEffect(() => form.reset({ service_id: initialValues.service_id || '', meter_code: initialValues.meter_code || '', initial_reading: initialValues.initial_reading ?? '', is_active: initialValues.is_active ?? true }), [form, initialValues])
  const services = (servicesQuery.data?.data?.data || []).filter((service) => ['PER_UNIT', 'TIERED'].includes(service.billing_method))
  const submit = async (values) => { setError(''); try { await onSubmit({ ...values, service_id: Number(values.service_id), initial_reading: Number(values.initial_reading), meter_code: values.meter_code || null }) } catch (e) { const message = e.response?.data?.errors?.service_id?.[0]; setError(message?.includes('active meter') ? 'Phòng đã có đồng hồ đang hoạt động cho dịch vụ này.' : message || e.response?.data?.message || 'Không thể lưu đồng hồ.') } }
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4"><div><label className="block text-sm font-medium">Dịch vụ</label><select className="mt-1 w-full rounded border p-2" {...form.register('service_id', { required: true })}><option value="">Chọn dịch vụ</option>{services.map(s => <option key={s.id} value={s.id}>{s.name}{s.unit ? ` - ${s.unit}` : ''}</option>)}</select></div><input placeholder="Mã đồng hồ (không bắt buộc)" className="w-full rounded border p-2" {...form.register('meter_code')} /><input type="number" min="0" step="0.01" placeholder="Chỉ số ban đầu" className="w-full rounded border p-2" {...form.register('initial_reading', { required: true })} /><label className="flex gap-2 text-sm"><input type="checkbox" {...form.register('is_active')} /> Đang hoạt động</label>{error && <p className="text-sm text-red-600">{error}</p>}<button className="rounded bg-slate-900 px-4 py-2 text-sm text-white">{label}</button></form>
}
