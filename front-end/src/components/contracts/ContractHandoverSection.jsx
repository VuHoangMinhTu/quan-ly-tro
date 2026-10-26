import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { confirmHandoverCheckIn, createHandoverCheckIn, getHandoverCheckIn, updateHandoverCheckIn } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { contractDocumentKeys } from '../../api/contractTemplateApi'
import { dormHandoverItems } from '../../utils/contractHandoverPrint'
import { notifyApiError } from '../../api/notificationTransport'

const conditions = { GOOD: 'Tốt', DAMAGED: 'Hư hỏng', MISSING: 'Thiếu', OTHER: 'Khác' }
const roomRentalItems = [
  'Phòng mới không có lỗ đinh, Tường trắng sạch sẽ', 'Ballet, nệm.', 'Thiết bị nhà vệ sinh', 'Tivi',
  'Bàn ghế, sofa.', 'Tủ quần áo, giá treo đồ', 'Cục phát wifi', 'Khóa cửa', 'Máy lạnh',
  'Tủ lạnh', 'Máy nước nóng', 'Rèm cửa', 'Tủ, kệ bếp',
]
const canonicalName = name => ({ 'Nệm': 'Ballet, nệm.', 'Khóa/chìa khóa': 'Khóa cửa' })[name] ?? name
function roomItemsWithWordDefaults(existing) {
  const byName = new Map(existing.map(item => [canonicalName(item.name), item]))
  const defaults = roomRentalItems.map(name => {
    const item = byName.get(name)
    return item ? { ...item, name, key_count: item.key_count ?? (item.name === 'Khóa/chìa khóa' ? item.quantity : null) } : { name, quantity: 1, condition: null, note: null, key_count: null, remote_count: null }
  })
  return [...defaults, ...existing.filter(item => !roomRentalItems.includes(canonicalName(item.name)))]
}

export default function ContractHandoverSection({ contract }) {
  const id = contract.id
  const client = useQueryClient()
  const [draftItems, setDraftItems] = useState(null)
  const [error, setError] = useState('')
  const section = useRef(null)
  const pending = useRef(false)
  const query = useQuery({ queryKey: contractKeys.handoverCheckIn(id), queryFn: () => getHandoverCheckIn(id) })
  const report = query.data?.data?.data
  const isRoomRental = contract.contract_type === 'ROOM_RENTAL'
  const initialItems = isRoomRental ? roomItemsWithWordDefaults(report?.items ?? []) : report?.items ?? []
  const items = draftItems ?? initialItems
  const setItems = updater => setDraftItems(current => updater(current ?? initialItems))
  const normalizeItems = () => items.map(item => ({ ...item, quantity: Number(item.quantity ?? 1), condition: item.condition || null, note: item.note || null,
      key_count: item.key_count === '' || item.key_count == null ? null : Number(item.key_count), remote_count: item.remote_count === '' || item.remote_count == null ? null : Number(item.remote_count) }))
  const onSuccess = async () => {
    setError('')
    setDraftItems(null)
    await Promise.all([client.invalidateQueries({ queryKey: contractKeys.handoverCheckIn(id) }), client.invalidateQueries({ queryKey: contractKeys.detail(id) }), client.invalidateQueries({ queryKey: contractDocumentKeys.preview(id) })])
  }
  const onError = failure => {
    setError(getApiErrorMessage(failure))
    notifyApiError(failure, 'Không thể lưu bàn giao. Vui lòng thử lại.')
    const field = Object.keys(failure.response?.data?.errors || {}).find(key => /^items\.\d+\.[a-z_]+$/.test(key))
    const input = field ? section.current?.querySelector(`[name="${field}"]`) : null
    input?.focus()
    input?.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
  }
  const callbacks = { onSuccess, onError, onSettled: () => { pending.current = false } }
  const create = useMutation({ ...callbacks, mutationFn: () => createHandoverCheckIn(id) })
  const save = useMutation({ ...callbacks, mutationFn: () => updateHandoverCheckIn(id, normalizeItems()) })
  const confirm = useMutation({ ...callbacks, mutationFn: async () => {
    await updateHandoverCheckIn(id, normalizeItems(), { toast: { success: false } })
    return confirmHandoverCheckIn(id)
  } })
  const busy = create.isPending || save.isPending || confirm.isPending
  const submit = mutation => { if (pending.current) return; pending.current = true; setError(''); mutation.mutate() }
  const change = (index, key, value) => setItems(current => current.map((item, position) => position === index ? { ...item, [key]: value } : item))

  return <section ref={section} id="contract-handover" className="scroll-mt-4 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <div><h3 className="text-lg font-semibold">Biên bản bàn giao CHECK_IN</h3><p className="text-sm text-slate-500">Dữ liệu đã xác nhận sẽ được đưa vào phụ lục bàn giao của hợp đồng khi chốt. Hợp đồng đã chốt hoặc đã ký không tự thay đổi.</p></div>
    {query.isPending && <p>Đang tải biên bản...</p>}
    {query.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p>}
    {!query.isPending && !query.isError && !report && <div className="space-y-3"><p className="text-sm text-slate-500">Chưa có biên bản bàn giao.</p><button type="button" disabled={busy} onClick={() => submit(create)} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">{create.isPending ? 'Đang tạo...' : 'Tạo biên bản bàn giao'}</button></div>}
    {report && <>
      <p className="text-sm font-medium">{report.status === 'CONFIRMED' ? 'Đã xác nhận bàn giao' : 'Bản nháp — có thể chỉnh sửa'}</p>
      <div className="space-y-3">{items.map((item, index) => isRoomRental ? <div key={index} className="space-y-3 rounded-lg border p-3 text-sm">
        <p className="font-semibold">{index + 1}. {item.name}</p>
        {index >= roomRentalItems.length && report.status === 'DRAFT' && <label className="block">Thiết bị và dụng cụ<input name={`items.${index}.name`} value={item.name} onChange={event => change(index, 'name', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>}
        {item.name === 'Khóa cửa' && <label className="block">Số lượng chìa<input disabled={report.status !== 'DRAFT'} type="number" min="0" value={item.key_count ?? ''} onChange={event => change(index, 'key_count', event.target.value)} className="mt-1 w-full rounded border p-2 sm:max-w-32" /></label>}
        {item.name === 'Máy lạnh' && <label className="block">Remote<input disabled={report.status !== 'DRAFT'} type="number" min="0" value={item.remote_count ?? ''} onChange={event => change(index, 'remote_count', event.target.value)} className="mt-1 w-full rounded border p-2 sm:max-w-32" /></label>}
        <fieldset disabled={report.status !== 'DRAFT'}><legend>Tình trạng khi nhận</legend><div className="mt-1 flex flex-wrap gap-4">{[['', 'Chưa chọn'], ['GOOD', 'Mới, tốt'], ['DAMAGED', 'Cũ, hư']].map(([value, label]) => <label key={value} className="inline-flex min-h-11 items-center gap-2"><input type="radio" name={`handover-condition-${id}-${index}`} checked={(item.condition ?? '') === value} onChange={() => change(index, 'condition', value)} />{label}</label>)}</div></fieldset>
        {item.condition && !['GOOD', 'DAMAGED'].includes(item.condition) && <p className="text-slate-500">Trạng thái cũ: {conditions[item.condition] ?? item.condition}</p>}
        <label className="block">Ghi chú<input disabled={report.status !== 'DRAFT'} value={item.note ?? ''} onChange={event => change(index, 'note', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        {index >= roomRentalItems.length && report.status === 'DRAFT' && <button type="button" onClick={() => setItems(current => current.filter((_, position) => position !== index))} className="min-h-11 text-red-700">Xóa</button>}
      </div> : <div key={index} className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[2fr_100px_150px_2fr_auto]">
        <label className="text-sm">Hạng mục<input name={`items.${index}.name`} disabled={report.status !== 'DRAFT'} value={item.name} onChange={event => change(index, 'name', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        <label className="text-sm">Số lượng{contract.contract_type === 'DORM_BED' && dormHandoverItems.find(([name]) => name === item.name)?.[1] ? ` (${dormHandoverItems.find(([name]) => name === item.name)[1]})` : ''}<input disabled={report.status !== 'DRAFT'} type="number" min="0" value={item.quantity ?? 1} onChange={event => change(index, 'quantity', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        <label className="text-sm">Tình trạng<select disabled={report.status !== 'DRAFT'} value={item.condition ?? ''} onChange={event => change(index, 'condition', event.target.value)} className="mt-1 w-full rounded border p-2"><option value="">Chưa chọn</option>{Object.entries(conditions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label className="text-sm">Ghi chú<input disabled={report.status !== 'DRAFT'} value={item.note ?? ''} onChange={event => change(index, 'note', event.target.value)} className="mt-1 w-full rounded border p-2" /></label>
        {report.status === 'DRAFT' && <button type="button" onClick={() => setItems(current => current.filter((_, position) => position !== index))} className="min-h-11 text-sm text-red-700">Xóa</button>}
      </div>)}</div>
      {report.status === 'DRAFT' && <div className="flex flex-wrap gap-2"><button type="button" disabled={busy} onClick={() => setItems(current => [...current, { name: '', quantity: 1, condition: null, note: '' }])} className="min-h-11 rounded-lg border px-4 text-sm">Thêm hạng mục</button><button type="button" disabled={busy || items.length === 0} onClick={() => submit(save)} className="min-h-11 rounded-lg border px-4 text-sm disabled:opacity-50">{save.isPending ? 'Đang lưu nháp...' : 'Lưu nháp'}</button><button type="button" disabled={busy || items.length === 0} onClick={() => { if (window.confirm('Xác nhận bàn giao? Sau đó không thể sửa biên bản.')) submit(confirm) }} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">{confirm.isPending ? 'Đang xác nhận...' : 'Xác nhận bàn giao'}</button></div>}
      {report.status === 'DRAFT' && <p className="text-xs text-slate-500">Khi xác nhận, các chỉnh sửa hiện tại sẽ được lưu trước. Tài liệu hợp đồng đã chốt vẫn giữ nguyên.</p>}
    </>}
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
  </section>
}
