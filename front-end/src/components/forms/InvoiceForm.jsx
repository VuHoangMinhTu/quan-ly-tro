import { handleFormSubmit } from '../../notifications/formFeedback'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { invoiceFormSchema } from '../../utils/formValidation'
import { getContractsByRoom } from '../../api/contractApi'
import { getInvoiceContracts } from '../../api/invoiceApi'
import { contractKeys } from '../../api/contractKeys'
import { toFormString } from '../../utils/form'
import DateInput from '../ui/DateInput'
import MoneyInput from '../ui/MoneyInput'
import { invoiceStatusLabels } from '../../utils/invoiceStatus'
import { formatBillingPeriodForApi, formatBillingPeriodForDisplay, formatDateForApi, formatDateForDisplay, isValidBillingPeriod, isValidDisplayDate } from '../../utils/date'

const defaults = { contract_id: '', auto_code: true, invoice_code: '', billing_period: '', discount_amount: 0, status: 'DRAFT', issued_at: '', due_date: '', note: '' }
const inputClass = 'mt-1 min-h-11 w-full min-w-0 sm:min-h-0 rounded-lg border border-slate-300 px-3 py-2'

export default function InvoiceForm({ roomId, initialValues = defaults, onSubmit, label, contractOptions, canEditFinancials = true }) {
  const creating = !initialValues.id
  const codeLocked = Boolean(initialValues.id && (initialValues.status !== 'DRAFT' || initialValues.issued_at || Number(initialValues.paid_amount) > 0))
  const [submitError, setSubmitError] = useState('')
  const form = useForm({ resolver: zodResolver(invoiceFormSchema(canEditFinancials, creating)), defaultValues: defaults, mode: 'onSubmit' })
  const autoCode = useWatch({ control: form.control, name: 'auto_code' })
  const billingPeriod = useWatch({ control: form.control, name: 'billing_period' })
  const selectedContract = useWatch({ control: form.control, name: 'contract_id' })
  const validPeriod = isValidBillingPeriod(billingPeriod)
  const apiPeriod = validPeriod ? formatBillingPeriodForApi(billingPeriod) : ''
  const contractsQuery = useQuery({
    queryKey: creating ? [...contractKeys.byRoom(roomId), 'invoice-eligible', apiPeriod] : contractKeys.byRoom(roomId),
    queryFn: () => creating ? getInvoiceContracts(roomId, apiPeriod) : getContractsByRoom(roomId),
    enabled: creating ? Boolean(roomId && validPeriod) : contractOptions === undefined,
    refetchOnWindowFocus: creating,
  })
  const contracts = creating ? contractsQuery.data?.data?.data?.contracts ?? [] : contractOptions ?? contractsQuery.data?.data?.data ?? []
  const warnings = creating ? contractsQuery.data?.data?.data?.warnings ?? [] : []
  const currentContract = initialValues.contract
  const options = !creating && currentContract && !contracts.some(contract => String(contract.id) === String(currentContract.id)) ? [currentContract, ...contracts] : contracts
  const currentSelection = options.find(contract => String(contract.id) === String(selectedContract))
  const displayedContract = currentSelection || (creating && options.length === 1 ? options[0] : null)
  const roomContext = creating ? contractsQuery.data?.data?.data?.room : null
  useEffect(() => {
    form.reset({
      ...defaults,
      ...initialValues,
      auto_code: !initialValues.invoice_code,
      contract_id: initialValues.contract_id != null ? String(initialValues.contract_id) : '',
      billing_period: formatBillingPeriodForDisplay(initialValues.billing_period),
      issued_at: formatDateForDisplay(initialValues.issued_at),
      due_date: formatDateForDisplay(initialValues.due_date),
      note: toFormString(initialValues.note),
    })
  }, [form, initialValues, roomId])
  const submit = async (values) => {
    setSubmitError('')
    try {
      const { auto_code: automatic, ...payload } = values
      await onSubmit({ ...payload, invoice_code: automatic ? null : values.invoice_code?.trim(), contract_id: values.contract_id ? Number(values.contract_id) : null, billing_period: formatBillingPeriodForApi(values.billing_period), discount_amount: Number(values.discount_amount || 0), issued_at: formatDateForApi(values.issued_at), due_date: formatDateForApi(values.due_date), note: values.note || null })
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError)) setSubmitError(getApiErrorMessage(error))
    }
  }
  return <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 break-words space-y-5 rounded-xl bg-white p-4 sm:p-6 shadow-sm">
    {!canEditFinancials && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Hóa đơn đã có thanh toán. Bạn chỉ có thể cập nhật ngày đến hạn hoặc ghi chú.</p>}
    {creating && (displayedContract || roomContext) && <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><p>Phòng {displayedContract?.room_code || roomContext?.room_code}{displayedContract?.bed && ` · Giường ${displayedContract.bed.bed_code}`}</p>{displayedContract && <><p>Người thuê hiện tại: <strong>{displayedContract.tenant?.full_name}</strong></p><p>Hợp đồng: {displayedContract.contract_code}</p></>}</div>}
    <div><label className="text-sm font-medium">Hợp đồng</label><select disabled={!canEditFinancials || (creating && (!validPeriod || contractsQuery.isPending || contractsQuery.isFetching || contractsQuery.isError))} className={inputClass} {...form.register('contract_id')}><option value="">{creating && !validPeriod ? 'Chọn kỳ hóa đơn trước' : creating ? 'Chọn người thuê/hợp đồng hiện tại' : 'Không chọn hợp đồng'}</option>{options.map(contract => <option key={contract.id} value={String(contract.id)}>{contract.contract_code} · {contract.tenant?.full_name}{creating ? ` · Phòng ${contract.room_code}${contract.bed ? ` / Giường ${contract.bed.bed_code}` : ''} · ${{ ACTIVE: 'Đang hiệu lực', TERMINATED: 'Đã chấm dứt', EXPIRED: 'Đã hết hạn' }[contract.status] || contract.status} · ${formatDateForDisplay(contract.start_date)} – ${formatDateForDisplay(contract.end_date) || 'Không thời hạn'}` : ''}</option>)}</select>{creating && <p className="mt-1 text-xs text-slate-500">Chỉ chọn người thuê hiện tại có hợp đồng và cư trú đang hoạt động. Hóa đơn lịch sử vẫn được giữ nguyên.</p>}{creating && validPeriod && contractsQuery.isSuccess && options.length === 0 && <p role="status" className="mt-2 text-sm text-slate-600">Phòng hiện chưa có người thuê/hợp đồng đang hoạt động.</p>}{warnings.map(warning => <p key={warning.contract_id} role="status" className="mt-2 text-sm text-amber-800">{warning.contract_code}: {warning.message}</p>)}{form.formState.errors.contract_id && <p className="mt-1 text-sm text-red-600">{form.formState.errors.contract_id.message}</p>}</div>
    <div><label className="text-sm font-medium">Mã hóa đơn</label>{!initialValues.id && <label className="my-2 flex items-center gap-2 text-sm"><input type="checkbox" {...form.register('auto_code')} />Tự động tạo mã</label>}<input readOnly={autoCode || codeLocked} placeholder={autoCode ? 'Tự động tạo khi lưu' : 'VD: INV-CUSTOM'} className={inputClass} {...form.register('invoice_code', { validate: value => autoCode || Boolean(value?.trim()) || 'Vui lòng nhập mã hóa đơn.' })}/>{form.formState.errors.invoice_code && <p className="mt-1 text-sm text-red-600">{form.formState.errors.invoice_code.message}</p>}</div>
    <div className="grid gap-4 sm:gap-5 md:grid-cols-2"><div><label className="text-sm font-medium">Kỳ hóa đơn *</label><Controller name="billing_period" control={form.control} rules={{ required: 'Vui lòng nhập kỳ hóa đơn theo định dạng mm/yyyy.', validate: (value) => isValidBillingPeriod(value) || 'Vui lòng nhập kỳ hóa đơn theo định dạng mm/yyyy.' }} render={({ field }) => <DateInput {...field} onChange={value => { field.onChange(value); if (creating) form.setValue('contract_id', '') }} disabled={!canEditFinancials} mode="month" placeholder="mm/yyyy" invalid={Boolean(form.formState.errors.billing_period)} />}/>{form.formState.errors.billing_period && <p className="mt-1 text-sm text-red-600">{form.formState.errors.billing_period.message}</p>}</div><div><label className="text-sm font-medium">Giảm giá</label><Controller name="discount_amount" control={form.control} render={({ field }) => <MoneyInput {...field} disabled={!canEditFinancials} placeholder="0" className={inputClass} />} />{form.formState.errors.discount_amount && <p className="mt-1 text-sm text-red-600">{form.formState.errors.discount_amount.message}</p>}<p className="mt-1 text-xs text-slate-500">đ</p></div><div><label className="text-sm font-medium">Trạng thái *</label><select disabled={!canEditFinancials} className={inputClass} {...form.register('status')}>{Object.entries(invoiceStatusLabels).map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select>{form.formState.errors.status && <p className="mt-1 text-sm text-red-600">{form.formState.errors.status.message}</p>}</div><div><label className="text-sm font-medium">Ngày phát hành</label><Controller name="issued_at" control={form.control} rules={{ validate: (value) => !value || isValidDisplayDate(value) || 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.issued_at)} />}/>{form.formState.errors.issued_at && <p className="mt-1 text-sm text-red-600">{form.formState.errors.issued_at.message}</p>}</div><div><label className="text-sm font-medium">Ngày đến hạn</label><Controller name="due_date" control={form.control} rules={{ validate: (value) => !value || isValidDisplayDate(value) || 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.due_date)} />}/>{form.formState.errors.due_date && <p className="mt-1 text-sm text-red-600">{form.formState.errors.due_date.message}</p>}</div></div>
    <div><label className="text-sm font-medium">Ghi chú</label><textarea placeholder="Ghi chú thêm (không bắt buộc)" className={inputClass} rows="3" {...form.register('note')}/>{form.formState.errors.note && <p className="mt-1 text-sm text-red-600">{form.formState.errors.note.message}</p>}</div>
    {contractsQuery.isError && <p className="text-sm text-red-600">{getApiErrorMessage(contractsQuery.error)}</p>}
    {submitError && <p role="alert" className="text-sm text-red-600">{submitError}</p>}
    <button disabled={form.formState.isSubmitting || (creating && (!currentSelection || !validPeriod || contractsQuery.isPending || contractsQuery.isFetching || contractsQuery.isError || options.length === 0))} className="w-full sm:w-auto min-h-11 sm:min-h-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">{label}</button>
  </form>
}
