import { handleFormSubmit } from '../../notifications/formFeedback'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useRef, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { BILLING_METHOD_LABELS, BILLING_METHODS, getBasePriceLabel, getSuggestedServiceUnit, SERVICE_TYPE_LABELS, SERVICE_TYPES } from '../../utils/service'
import { toFormString } from '../../utils/form'
import MoneyInput from '../ui/MoneyInput'

const schema = z.object({
  name: z.string().trim().min(1, 'Vui lòng nhập tên dịch vụ').max(255, 'Tên dịch vụ tối đa 255 ký tự.'),
  type: z.enum(SERVICE_TYPES, { error: 'Vui lòng chọn loại dịch vụ hợp lệ.' }),
  billing_method: z.enum(BILLING_METHODS, { error: 'Vui lòng chọn cách tính hợp lệ.' }),
  unit: z.string({ error: 'Đơn vị phải là nội dung văn bản.' }).max(50, 'Đơn vị không được quá 50 ký tự').optional(),
  base_price: z.any(),
  vat_rate: z.coerce.number({ error: 'Vui lòng nhập mức VAT hợp lệ từ 0 đến 100%.' }).min(0, 'VAT phải từ 0 đến 100%.').max(100, 'VAT phải từ 0 đến 100%.').refine(value => Math.abs(value * 100 - Math.round(value * 100)) < 0.0000001, 'VAT chỉ được có tối đa 2 chữ số thập phân.'),
  is_active: z.boolean(),
}).superRefine((values, context) => {
  if (values.billing_method !== 'TIERED' && (values.base_price == null || String(values.base_price).trim() === '' || !Number.isFinite(Number(values.base_price)) || Number(values.base_price) < 0)) {
    context.addIssue({ code: 'custom', path: ['base_price'], message: 'Vui lòng nhập giá hợp lệ.' })
  }
})

const defaults = { name: '', type: 'ELECTRICITY', billing_method: 'FIXED', unit: '', base_price: '', vat_rate: 0, is_active: true }
const inputClass = 'mt-1 min-h-11 w-full min-w-0 sm:min-h-0 rounded border p-2'

export default function ServiceForm({ initialValues = defaults, onSubmit, label }) {
  const values = { ...defaults, ...initialValues, unit: toFormString(initialValues.unit), base_price: initialValues.base_price ?? '', vat_rate: initialValues.vat_rate ?? 0, is_active: initialValues.is_active ?? true }
  const form = useForm({ resolver: zodResolver(schema), defaultValues: values, mode: 'onSubmit' })
  const [submitError, setSubmitError] = useState('')
  const [billingMethod, setBillingMethod] = useState(values.billing_method)
  const [serviceType, setServiceType] = useState(values.type)
  const autoUnitRef = useRef(values.unit === getSuggestedServiceUnit(values.type, values.billing_method) ? values.unit : '')
  const typeRegistration = form.register('type')
  const billingMethodRegistration = form.register('billing_method')
  const unitRegistration = form.register('unit')

  const suggestUnit = (type, method) => {
    const currentUnit = form.getValues('unit')
    const suggestion = getSuggestedServiceUnit(type, method)
    if (!suggestion || (currentUnit && currentUnit !== autoUnitRef.current)) return
    form.setValue('unit', suggestion, { shouldDirty: true })
    autoUnitRef.current = suggestion
  }

  const submit = async (formValues) => {
    setSubmitError('')
    try {
      await onSubmit({
        ...formValues,
        unit: formValues.unit || null,
        base_price: billingMethod === 'TIERED' ? null : Number(formValues.base_price),
        vat_rate: Number(formValues.vat_rate || 0),
      })
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError)) setSubmitError(getApiErrorMessage(error))
    }
  }
  const { errors } = form.formState

  return <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 break-words space-y-4">
    <div><label className="block text-sm font-medium">Tên dịch vụ</label><input className={inputClass} {...form.register('name')} />{errors.name && <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>}</div>
    <div className="grid gap-3 sm:grid-cols-2">
      <div><label className="block text-sm font-medium">Loại dịch vụ</label><select className={inputClass} {...typeRegistration} onChange={(event) => { typeRegistration.onChange(event); setServiceType(event.target.value); suggestUnit(event.target.value, form.getValues('billing_method')) }}>{SERVICE_TYPES.map((type) => <option key={type} value={type}>{SERVICE_TYPE_LABELS[type]}</option>)}</select>{errors.type && <p className="mt-1 text-sm text-red-600">{errors.type.message}</p>}</div>
      <div><label className="block text-sm font-medium">Cách tính</label><select className={inputClass} {...billingMethodRegistration} onChange={(event) => { billingMethodRegistration.onChange(event); setBillingMethod(event.target.value); suggestUnit(form.getValues('type'), event.target.value) }}>{BILLING_METHODS.map((method) => <option key={method} value={method}>{BILLING_METHOD_LABELS[method]}</option>)}</select>{errors.billing_method && <p className="mt-1 text-sm text-red-600">{errors.billing_method.message}</p>}</div>
    </div>
    {billingMethod !== 'TIERED' && <div><label className="block text-sm font-medium">{getBasePriceLabel(billingMethod)}</label><Controller name="base_price" control={form.control} render={({ field }) => <MoneyInput {...field} className={inputClass} placeholder="0" />} />{errors.base_price && <p className="mt-1 text-sm text-red-600">{errors.base_price.message}</p>}</div>}
    <div><label className="block text-sm font-medium">Đơn vị</label><input placeholder={getSuggestedServiceUnit(serviceType, billingMethod) || 'VD: tháng'} className={inputClass} {...unitRegistration} onChange={(event) => { unitRegistration.onChange(event); if (event.target.value !== autoUnitRef.current) autoUnitRef.current = '' }} />{errors.unit && <p className="mt-1 text-sm text-red-600">{errors.unit.message}</p>}</div>
    <label className="flex min-h-11 items-center gap-2 text-sm sm:min-h-0"><input className="h-4 w-4 shrink-0" type="checkbox" {...form.register('is_active')} /> Đang áp dụng</label>{errors.is_active && <p className="mt-1 text-sm text-red-600">{errors.is_active.message}</p>}
    <div><label htmlFor="service-vat" className="block text-sm font-medium">Thuế VAT (%)</label><input id="service-vat" type="number" min="0" max="100" step="0.01" placeholder="0" className={inputClass} {...form.register('vat_rate')} />{errors.vat_rate && <p className="mt-1 text-sm text-red-600">{errors.vat_rate.message}</p>}<p className="mt-1 text-xs text-slate-500">Mức VAT do chủ trọ cấu hình. Để trống là 0%, không có dòng thuế.</p></div>
    {submitError && <p role="alert" className="text-sm text-red-600">{submitError}</p>}
    <button disabled={form.formState.isSubmitting} className="w-full sm:w-auto min-h-11 sm:min-h-0 rounded bg-slate-900 px-4 py-2 text-sm text-white">{label}</button>
  </form>
}
