import { useEffect, useRef, useState } from 'react'
import DateInput from '../ui/DateInput'
import ContractServicePriceEditor from './ContractServicePriceEditor'
import ContractDraftServicesEditor from './ContractDraftServicesEditor'
import { formatCurrency } from '../../utils/formatters'
import { formatBillingPeriodForApi, isValidBillingPeriod } from '../../utils/date'
import { editableContractService, contractServicesPayload, contractServicePricingPayload, contractServicePricingError } from '../../utils/contractServices'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage'

export default function ContractServiceChangeModal({ action, availableServices, terms, pending, onClose, onSave }) {
  const [price, setPrice] = useState(() => editableContractService(action.term ?? availableServices[0] ?? {}))
  const [rows, setRows] = useState(() => terms.map(editableContractService))
  const [period, setPeriod] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const dialog = useRef(null)
  const draft = action.type === 'draft'
  const stop = action.type === 'stop'
  const add = action.type === 'add'
  const title = draft ? 'Chỉnh sửa dịch vụ' : stop ? 'Ngừng áp dụng dịch vụ' : add ? 'Thêm dịch vụ' : 'Điều chỉnh dịch vụ'
  useEffect(() => {
    const previous = document.activeElement
    dialog.current?.focus()
    const keydown = event => {
      if (event.key === 'Escape' && !pending) { event.preventDefault(); onClose() }
      if (event.key !== 'Tab') return
      const fields = Array.from(dialog.current?.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)') ?? [])
      if (!fields.length) return
      const first = fields[0]; const last = fields.at(-1)
      if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) { event.preventDefault(); last.focus() }
      else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.current)) { event.preventDefault(); first.focus() }
    }
    document.addEventListener('keydown', keydown)
    return () => { document.removeEventListener('keydown', keydown); previous?.focus?.() }
  }, [onClose, pending])
  const fieldError = key => fieldErrors[key] && <p className="mt-1 text-sm text-red-700">{fieldErrors[key]}</p>
  const submit = async event => {
    event.preventDefault()
    if (pending) return
    setError(''); setFieldErrors({})
    if (!draft && !isValidBillingPeriod(period)) { setError('Vui lòng chọn kỳ áp dụng theo định dạng mm/yyyy.'); return }
    if (!draft && !reason.trim()) { setError('Vui lòng nhập lý do thay đổi dịch vụ.'); return }
    if (add && !Number.isInteger(Number(price.service_id))) { setError('Vui lòng chọn dịch vụ của phòng.'); return }
    const pricingError = draft ? rows.map(contractServicePricingError).find(Boolean) : !stop && contractServicePricingError(price)
    if (pricingError) { setError(pricingError); return }
    const payload = draft ? { services: contractServicesPayload(rows) } : {
      effective_from: formatBillingPeriodForApi(period), reason: reason.trim(),
      ...(!stop ? { ...(add ? { service_id: Number(price.service_id) } : { contract_service_id: action.term.id }), ...contractServicePricingPayload(price) } : {}),
    }
    try { await onSave(payload) } catch (failure) { setError(getApiErrorMessage(failure)); setFieldErrors(getApiFieldErrors(failure)) }
  }
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3" onClick={event => { if (event.target === event.currentTarget && !pending) onClose() }}>
    <section ref={dialog} tabIndex={-1} role="dialog" aria-modal="true" aria-label={title} className="max-h-[92dvh] w-full min-w-0 max-w-2xl overflow-y-auto rounded-xl bg-white p-4 shadow-xl outline-none sm:p-6">
      <div className="mb-4 flex items-start justify-between gap-3"><h3 className="text-lg font-semibold">{title}</h3><button type="button" disabled={pending} onClick={onClose} aria-label="Đóng" className="min-h-11 shrink-0 rounded-lg border px-3 disabled:opacity-50">Đóng</button></div>
      <form onSubmit={submit} className="min-w-0 space-y-4">
        {draft ? <ContractDraftServicesEditor availableServices={availableServices} value={rows} onChange={setRows} onSync={async () => setRows(availableServices.map(editableContractService))} /> : <>
          {add ? <label className="block text-sm font-medium">Dịch vụ<select value={price.service_id || ''} onChange={event => setPrice(editableContractService(availableServices.find(service => Number(service.id) === Number(event.target.value)) ?? {}))} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2"><option value="">Chọn dịch vụ</option>{availableServices.map(service => <option key={service.id} value={service.id}>{service.name}</option>)}</select>{fieldError('service_id')}</label> : <div className="rounded-lg bg-slate-50 p-3 text-sm"><strong className="break-words">{action.term.service_name}</strong><p>{action.term.billing_method === 'TIERED' ? 'Theo bảng giá bậc thang đã thỏa thuận' : `Mức hiện tại: ${formatCurrency(action.term.base_price)}${action.term.unit ? ` / ${action.term.unit}` : ''}`}</p></div>}
          {!stop && <ContractServicePriceEditor value={price} onChange={setPrice} />}
          {fieldError('base_price')}{fieldError('vat_rate')}{fieldError('pricing_snapshot')}
          <div><label className="text-sm font-medium">{stop ? 'Ngừng từ kỳ *' : 'Áp dụng từ kỳ *'}</label><DateInput mode="month" value={period} onChange={setPeriod} placeholder="mm/yyyy" invalid={Boolean(fieldErrors.effective_from)} />{fieldError('effective_from')}<p className="mt-1 text-xs text-slate-500">Áp dụng từ đầu tháng đã chọn. Không thay đổi giá của các kỳ trước.</p></div>
          <label className="block text-sm font-medium">Lý do *<textarea value={reason} onChange={event => setReason(event.target.value)} rows={3} maxLength={2000} className="mt-1 w-full min-w-0 rounded-lg border p-3" />{fieldError('reason')}</label>
          <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Kỳ đã có thanh toán không thể điều chỉnh. Nếu kỳ bị ảnh hưởng đã có hóa đơn nháp hoặc chưa thanh toán, cần xử lý hóa đơn đó trước.</p>
        </>}
        {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        <div className="flex flex-wrap gap-3"><button disabled={pending} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{pending ? 'Đang lưu...' : draft ? 'Lưu dịch vụ' : stop ? 'Xác nhận ngừng áp dụng' : 'Xác nhận điều chỉnh'}</button><button type="button" disabled={pending} onClick={onClose} className="min-h-11 rounded-lg border px-4 text-sm disabled:opacity-50">Hủy</button></div>
      </form>
    </section>
  </div>
}
