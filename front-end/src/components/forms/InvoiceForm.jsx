import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Controller, useForm } from 'react-hook-form'
import { getContractsByRoom } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import DateInput from '../ui/DateInput'
import MoneyInput from '../ui/MoneyInput'
import { invoiceStatusLabels } from '../../utils/invoiceStatus'
import { formatBillingPeriodForApi, formatBillingPeriodForDisplay, formatDateForApi, formatDateForDisplay, isValidBillingPeriod, isValidDisplayDate } from '../../utils/date'

const defaults = { contract_id: '', invoice_code: '', billing_period: '', discount_amount: 0, status: 'DRAFT', issued_at: '', due_date: '', note: '' }
const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2'

export default function InvoiceForm({ roomId, initialValues = defaults, onSubmit, label, contractOptions, canEditFinancials = true }) {
  const contractsQuery = useQuery({ queryKey: contractKeys.byRoom(roomId), queryFn: () => getContractsByRoom(roomId), enabled: contractOptions === undefined })
  const contracts = contractOptions ?? contractsQuery.data?.data?.data ?? []
  const currentContract = initialValues.contract
  const options = currentContract && !contracts.some((contract) => String(contract.id) === String(currentContract.id)) ? [currentContract, ...contracts] : contracts
  const form = useForm({ defaultValues: defaults })
  useEffect(() => {
    form.reset({
      ...defaults,
      ...initialValues,
      contract_id: initialValues.contract_id != null ? String(initialValues.contract_id) : '',
      billing_period: formatBillingPeriodForDisplay(initialValues.billing_period),
      issued_at: formatDateForDisplay(initialValues.issued_at),
      due_date: formatDateForDisplay(initialValues.due_date),
    })
  }, [form, initialValues])
  const submit = (values) => {
    onSubmit({ ...values, contract_id: values.contract_id ? Number(values.contract_id) : null, billing_period: formatBillingPeriodForApi(values.billing_period), discount_amount: Number(values.discount_amount || 0), issued_at: formatDateForApi(values.issued_at), due_date: formatDateForApi(values.due_date), note: values.note || null })
  }
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-5 rounded-xl bg-white p-6 shadow-sm">
    {!canEditFinancials && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Hóa đơn đã có thanh toán. Bạn chỉ có thể cập nhật ngày đến hạn hoặc ghi chú.</p>}
    <div><label className="text-sm font-medium">Hợp đồng</label><select disabled={!canEditFinancials} className={inputClass} {...form.register('contract_id')}><option value="">Không chọn hợp đồng</option>{options.map(contract => <option key={contract.id} value={String(contract.id)}>{contract.contract_code} · {contract.tenant?.full_name}</option>)}</select></div>
    <div><label className="text-sm font-medium">Mã hóa đơn *</label><input placeholder="VD: HD-09-2026-001" className={inputClass} {...form.register('invoice_code', { required: true })}/></div>
    <div className="grid gap-5 md:grid-cols-2"><div><label className="text-sm font-medium">Kỳ hóa đơn *</label><Controller name="billing_period" control={form.control} rules={{ required: 'Vui lòng nhập kỳ hóa đơn theo định dạng mm/yyyy.', validate: (value) => isValidBillingPeriod(value) || 'Vui lòng nhập kỳ hóa đơn theo định dạng mm/yyyy.' }} render={({ field }) => <DateInput {...field} disabled={!canEditFinancials} mode="month" placeholder="mm/yyyy" invalid={Boolean(form.formState.errors.billing_period)} />}/>{form.formState.errors.billing_period && <p className="mt-1 text-sm text-red-600">{form.formState.errors.billing_period.message}</p>}</div><div><label className="text-sm font-medium">Giảm giá</label><Controller name="discount_amount" control={form.control} render={({ field }) => <MoneyInput {...field} disabled={!canEditFinancials} placeholder="0" className={inputClass} />} /><p className="mt-1 text-xs text-slate-500">đ</p></div><div><label className="text-sm font-medium">Trạng thái *</label><select disabled={!canEditFinancials} className={inputClass} {...form.register('status')}>{Object.entries(invoiceStatusLabels).map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></div><div><label className="text-sm font-medium">Ngày phát hành</label><Controller name="issued_at" control={form.control} rules={{ validate: (value) => !value || isValidDisplayDate(value) || 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.issued_at)} />}/>{form.formState.errors.issued_at && <p className="mt-1 text-sm text-red-600">{form.formState.errors.issued_at.message}</p>}</div><div><label className="text-sm font-medium">Ngày đến hạn</label><Controller name="due_date" control={form.control} rules={{ validate: (value) => !value || isValidDisplayDate(value) || 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.due_date)} />}/>{form.formState.errors.due_date && <p className="mt-1 text-sm text-red-600">{form.formState.errors.due_date.message}</p>}</div></div>
    <div><label className="text-sm font-medium">Ghi chú</label><textarea placeholder="Ghi chú thêm (không bắt buộc)" className={inputClass} rows="3" {...form.register('note')}/></div>
    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">{label}</button>
  </form>
}
