import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { confirmHandoverCheckIn, createHandoverCheckIn, getHandoverCheckIn, updateHandoverCheckIn } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { contractDocumentKeys } from '../../api/contractTemplateApi'

const conditions = { GOOD: 'Tốt', DAMAGED: 'Hư hỏng', MISSING: 'Thiếu', OTHER: 'Khác' }

export default function ContractHandoverSection({ contract }) {
  const id = contract.id
  const client = useQueryClient()
  const [draftItems, setDraftItems] = useState(null)
  const [error, setError] = useState('')
  const query = useQuery({ queryKey: contractKeys.handoverCheckIn(id), queryFn: () => getHandoverCheckIn(id) })
  const report = query.data?.data?.data
  const items = draftItems ?? report?.items ?? []
  const setItems = updater => setDraftItems(current => updater(current ?? report?.items ?? []))
  const mutation = useMutation({ mutationFn: async action => {
    if (action === 'create') return createHandoverCheckIn(id)
    const normalizedItems = items.map(item => ({ ...item, quantity: Number(item.quantity), note: item.note || null }))
    if (action === 'confirm') {
      await updateHandoverCheckIn(id, normalizedItems)
      return confirmHandoverCheckIn(id)
    }
    return updateHandoverCheckIn(id, normalizedItems)
  }, onSuccess: async () => {
    setError('')
    setDraftItems(null)
    await Promise.all([client.invalidateQueries({ queryKey: contractKeys.handoverCheckIn(id) }), client.invalidateQueries({ queryKey: contractKeys.detail(id) }), client.invalidateQueries({ queryKey: contractDocumentKeys.preview(id) })])
  }, onError: failure => setError(getApiErrorMessage(failure)) })
  const change = (index, key, value) => setItems(current => current.map((item, position) => position === index ? { ...item, [key]: value } : item))

  return <section id="contract-handover" className="scroll-mt-4 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <div><h3 className="text-lg font-semibold">Biên bản bàn giao CHECK_IN</h3><p className="text-sm text-slate-500">Dữ liệu đã xác nhận sẽ được đưa vào phụ lục bàn giao của hợp đồng khi chốt. Hợp đồng đã chốt hoặc đã ký không tự thay đổi.</p></div>
    {query.isPending && <p>Đang tải biên bản...</p>}
    {query.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p>}
    {!query.isPending && !query.isError && !report && <div className="space-y-3"><p className="text-sm text-slate-500">Chưa có biên bản bàn giao.</p><button type="button" disabled={mutation.isPending} onClick={() => mutation.mutate('create')} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">Tạo biên bản bàn giao</button></div>}
    {report && <>
      <p className="text-sm font-medium">{report.status === 'CONFIRMED' ? 'Đã xác nhận bàn giao' : 'Bản nháp — có thể chỉnh sửa'}</p>
      <div className="space-y-3">{items.map((item, index) => <div key={index} className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[2fr_100px_150px_2fr_auto]">
        <label className="text-sm">Hạng mục<input disabled={report.status !== 'DRAFT'} value={item.name} onChange={event => change(index, 'name', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        <label className="text-sm">Số lượng<input disabled={report.status !== 'DRAFT'} type="number" min="0" value={item.quantity} onChange={event => change(index, 'quantity', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        <label className="text-sm">Tình trạng<select disabled={report.status !== 'DRAFT'} value={item.condition} onChange={event => change(index, 'condition', event.target.value)} className="mt-1 w-full rounded border p-2">{Object.entries(conditions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label className="text-sm">Ghi chú<input disabled={report.status !== 'DRAFT'} value={item.note ?? ''} onChange={event => change(index, 'note', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        {report.status === 'DRAFT' && <button type="button" onClick={() => setItems(current => current.filter((_, position) => position !== index))} className="min-h-11 text-sm text-red-700">Xóa</button>}
      </div>)}</div>
      {report.status === 'DRAFT' && <div className="flex flex-wrap gap-2"><button type="button" onClick={() => setItems(current => [...current, { name: '', quantity: 1, condition: 'GOOD', note: '' }])} className="min-h-11 rounded-lg border px-4 text-sm">Thêm hạng mục</button><button type="button" disabled={mutation.isPending || items.length === 0} onClick={() => mutation.mutate('save')} className="min-h-11 rounded-lg border px-4 text-sm disabled:opacity-50">Lưu nháp</button><button type="button" disabled={mutation.isPending || items.length === 0} onClick={() => { if (window.confirm('Xác nhận bàn giao? Sau đó không thể sửa biên bản.')) mutation.mutate('confirm') }} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">Xác nhận bàn giao</button></div>}
      {report.status === 'DRAFT' && <p className="text-xs text-slate-500">Khi xác nhận, các chỉnh sửa hiện tại sẽ được lưu trước. Tài liệu hợp đồng đã chốt vẫn giữ nguyên.</p>}
    </>}
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
  </section>
}
