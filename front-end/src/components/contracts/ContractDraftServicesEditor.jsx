import { useState } from 'react'
import { BILLING_METHOD_LABELS } from '../../utils/service'
import { editableContractService } from '../../utils/contractServices'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import ContractServicePriceEditor from './ContractServicePriceEditor'

export default function ContractDraftServicesEditor({ availableServices, value, onChange, onSync }) {
  const [syncing, setSyncing] = useState(false)
  const [error, setError] = useState('')
  const sync = async () => {
    if (!window.confirm('Đồng bộ sẽ thay toàn bộ lựa chọn và giá dịch vụ đang chỉnh bằng dịch vụ hiện tại của phòng. Bạn có muốn tiếp tục?')) return
    setSyncing(true); setError('')
    try { await onSync() } catch (failure) { setError(getApiErrorMessage(failure)) } finally { setSyncing(false) }
  }
  return <div className="min-w-0 space-y-3">
    <p className="text-sm text-slate-500">Chọn dịch vụ của phòng và kiểm tra mức giá đã thỏa thuận trước khi kích hoạt. Có thể chỉnh giá riêng cho hợp đồng này.</p>
    <button type="button" disabled={syncing} onClick={sync} className="min-h-11 rounded-lg border px-3 text-sm disabled:opacity-50">{syncing ? 'Đang đồng bộ...' : 'Đồng bộ từ dịch vụ của phòng'}</button>
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    <div className="grid min-w-0 gap-3 lg:grid-cols-2">{availableServices.map(service => {
      const selected = value.find(row => Number(row.service_id) === Number(service.id))
      return <article key={service.id} className="min-w-0 space-y-3 rounded-lg border p-3">
        <label className="flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" className="mt-1" checked={Boolean(selected)} onChange={() => onChange(selected ? value.filter(row => Number(row.service_id) !== Number(service.id)) : [...value, editableContractService(service)])} /><span className="min-w-0 break-words"><strong>{service.name}</strong><span className="block text-slate-500">{BILLING_METHOD_LABELS[selected?.billing_method ?? service.billing_method]}</span></span></label>
        {selected && <ContractServicePriceEditor value={selected} onChange={next => onChange(value.map(row => Number(row.service_id) === Number(service.id) ? next : row))} />}
      </article>
    })}</div>
    {!availableServices.length && <p className="text-sm text-slate-500">Phòng chưa có dịch vụ đang áp dụng.</p>}
    {value.filter(row => !availableServices.some(service => Number(service.id) === Number(row.service_id))).map(row => <div key={row.service_id} className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><p>{row.service_name ?? 'Dịch vụ đã chọn'} không còn áp dụng cho phòng.</p><button type="button" onClick={() => onChange(value.filter(item => item !== row))} className="min-h-11 underline">Bỏ khỏi bản nháp</button></div>)}
  </div>
}
