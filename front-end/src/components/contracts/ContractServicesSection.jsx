import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adjustContractService, getContractServices, saveContractServices, stopContractService } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency, formatDate, formatUtilityValue } from '../../utils/formatters'
import { BILLING_METHOD_LABELS } from '../../utils/service'
import { termAppliesOn } from '../../utils/contractServices'
import ContractServiceChangeModal from './ContractServiceChangeModal'

function Price({ term }) {
  return <div className="space-y-1 text-sm"><p className="text-slate-500">{BILLING_METHOD_LABELS[term.billing_method]}</p>{term.billing_method === 'TIERED' ? <ul className="space-y-1">{(term.pricing_snapshot ?? []).map((tier, index) => <li key={index}>{formatUtilityValue(tier.from_quantity)}–{tier.to_quantity == null ? 'Không giới hạn' : formatUtilityValue(tier.to_quantity)} {term.unit} · {formatCurrency(tier.unit_price)}{term.unit ? ` / ${term.unit}` : ''}</li>)}</ul> : <p className="font-medium">{formatCurrency(term.base_price)}{term.unit ? ` / ${term.unit}` : ''}</p>}<p>VAT {Number(term.vat_rate ?? 0)}%</p></div>
}

export default function ContractServicesSection({ contract }) {
  const client = useQueryClient()
  const [action, setAction] = useState(null)
  const [message, setMessage] = useState('')
  const query = useQuery({ queryKey: contractKeys.services(contract.id), queryFn: () => getContractServices(contract.id) })
  const mutation = useMutation({ mutationFn: async ({ action: selected, payload }) => {
    if (selected.type === 'draft' || selected.type === 'sync') return saveContractServices(contract.id, payload)
    if (selected.type === 'stop') return stopContractService(contract.id, selected.term.id, payload)
    return adjustContractService(contract.id, payload)
  }, onSuccess: async () => {
    setAction(null); setMessage('Đã cập nhật dịch vụ hợp đồng.')
    await Promise.all([client.invalidateQueries({ queryKey: contractKeys.services(contract.id) }), client.invalidateQueries({ queryKey: contractKeys.detail(String(contract.id)) }), client.invalidateQueries({ queryKey: contractKeys.context(contract.room_id) }), client.invalidateQueries({ queryKey: ['invoices'] }), client.invalidateQueries({ queryKey: ['invoice'] })])
  } })
  const draft = contract.status === 'DRAFT'
  const active = contract.status === 'ACTIVE'
  const data = query.data?.data?.data
  const terms = data?.terms ?? []
  const current = terms.filter(term => termAppliesOn(term, data?.today))
  const scheduled = terms.filter(term => term.is_active && term.effective_from > data?.today)
  const cards = draft ? terms : [...current, ...scheduled]
  const openIds = new Set(terms.filter(term => term.is_active && !term.effective_to).map(term => Number(term.service_id)))
  const additions = (data?.available_services ?? []).filter(service => !openIds.has(Number(service.id)))
  const sync = async () => {
    if (!window.confirm('Đồng bộ sẽ thay danh sách và mức giá dịch vụ của bản nháp bằng dữ liệu hiện tại của phòng. Tiếp tục?')) return
    try { await mutation.mutateAsync({ action: { type: 'sync' }, payload: { sync_from_room: true } }) } catch { /* Render the mutation error below. */ }
  }
  return <section className="min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <header className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="text-lg font-semibold">Dịch vụ áp dụng</h3><p className="mt-1 text-sm text-slate-500">Các mức giá dưới đây là điều khoản đang áp dụng hoặc đã lên lịch cho hợp đồng này.</p></div><div className="flex flex-wrap gap-2">{data && draft && <><button type="button" disabled={mutation.isPending} onClick={() => setAction({ type: 'draft' })} className="min-h-11 rounded-lg border px-3 text-sm">Chỉnh sửa dịch vụ</button><button type="button" disabled={mutation.isPending} onClick={sync} className="min-h-11 rounded-lg border px-3 text-sm">Đồng bộ từ phòng</button></>}{data && active && !data.legacy_fallback && additions.length > 0 && <button type="button" onClick={() => setAction({ type: 'add' })} className="min-h-11 rounded-lg bg-slate-900 px-3 text-sm text-white">+ Thêm dịch vụ</button>}</div></header>
    {query.isPending ? <p role="status" className="animate-pulse text-sm text-slate-500">Đang tải dịch vụ hợp đồng...</p> : query.isError ? <div role="alert" className="text-sm text-red-700"><p>{getApiErrorMessage(query.error)}</p><button type="button" onClick={() => query.refetch()} className="min-h-11 underline">Thử lại</button></div> : <>
      {data.legacy_fallback && !draft && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Hợp đồng cũ chưa có bảng giá thỏa thuận được xác nhận. Hóa đơn tiếp tục dùng dịch vụ của phòng; vui lòng kiểm tra thỏa thuận trước khi lập hợp đồng mới.</p>}
      {!cards.length && <p className="text-sm text-slate-500">Chưa có dịch vụ áp dụng cho hợp đồng này.</p>}
      <div className="grid min-w-0 gap-3 sm:grid-cols-2">{cards.map(term => <article key={term.id} className="min-w-0 space-y-2 rounded-lg border p-3"><div className="flex flex-wrap items-start justify-between gap-2"><strong className="min-w-0 break-words">{term.service_name}</strong>{term.effective_from > data.today && !draft && <span className="rounded-full bg-blue-50 px-2 py-1 text-xs text-blue-800">Sắp áp dụng</span>}</div><Price term={term} /><p className="text-sm text-slate-500">{term.effective_from ? `Hiệu lực từ ${formatDate(term.effective_from)}` : 'Chốt ngày hiệu lực khi kích hoạt'}{term.effective_to ? ` đến ${formatDate(term.effective_to)}` : ''}</p>{active && !data.legacy_fallback && !term.effective_to && <div className="flex flex-wrap gap-2 pt-1"><button type="button" onClick={() => setAction({ type: 'adjust', term })} className="min-h-11 rounded-lg bg-slate-900 px-3 text-sm text-white">Điều chỉnh dịch vụ</button><button type="button" onClick={() => setAction({ type: 'stop', term })} className="min-h-11 rounded-lg border border-red-200 px-3 text-sm text-red-700">Ngừng áp dụng</button></div>}</article>)}</div>
      {!draft && <details className="min-w-0 rounded-lg border p-3"><summary className="cursor-pointer text-sm font-semibold">Lịch sử thay đổi dịch vụ ({terms.length})</summary><div className="mt-3 grid min-w-0 gap-3 sm:grid-cols-2">{terms.map(term => <article key={term.id} className="min-w-0 space-y-2 rounded-lg bg-slate-50 p-3"><strong className="break-words text-sm">{term.service_name}{!term.is_active ? ' — Ngừng áp dụng' : ''}</strong><p className="text-sm">{formatDate(term.effective_from)} → {term.effective_to ? formatDate(term.effective_to) : 'Chưa có ngày kết thúc'}</p>{term.is_active && <Price term={term} />}{term.adjustment_reason && <p className="break-words text-sm text-slate-600">Lý do: {term.adjustment_reason}</p>}</article>)}{!terms.length && <p className="text-sm text-slate-500">Chưa có lịch sử thay đổi dịch vụ.</p>}</div></details>}
    </>}
    {message && <p role="status" className="text-sm text-green-700">{message}</p>}
    {mutation.isError && !action && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(mutation.error)}</p>}
    {action && <ContractServiceChangeModal action={action} availableServices={action.type === 'add' ? additions : data.available_services} terms={terms} pending={mutation.isPending} onClose={() => setAction(null)} onSave={payload => mutation.mutateAsync({ action, payload })} />}
  </section>
}
