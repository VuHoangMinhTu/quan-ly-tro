import { useEffect, useMemo, useRef, useState } from 'react'
import { getServices } from '../../api/serviceApi'
import { getRoomServices, updateRoomServices } from '../../api/roomServiceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'
import { BILLING_METHOD_LABELS } from '../../utils/service'
import { additiveServiceIds, apiRows, assignedServiceIds } from '../../utils/contractRoomServicePicker.mjs'

const isAvailable = row => row && row.is_active !== false && row.is_active !== 0 && row.deleted_at == null

// Add-only modal: editing the room's existing assignments is deliberately left
// to the full room management screen, so adding a service cannot remove others.
export default function ContractHouseServicesModal({ roomId, houseId, roomCode, onApplied, onClose }) {
  const [catalog, setCatalog] = useState(null)
  const [assignedIds, setAssignedIds] = useState([])
  const [selectedIds, setSelectedIds] = useState([])
  const [search, setSearch] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const searchRef = useRef(null)

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    searchRef.current?.focus()
    return () => { document.body.style.overflow = previousOverflow }
  }, [])

  useEffect(() => {
    const onKeyDown = event => { if (event.key === 'Escape' && !saving) onClose() }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [onClose, saving])

  useEffect(() => {
    let alive = true
    Promise.all([getServices(houseId), getRoomServices(roomId)])
      .then(([houseResponse, roomResponse]) => {
        if (!alive) return
        const all = apiRows(houseResponse).filter(isAvailable)
        const current = assignedServiceIds(apiRows(roomResponse))
        setCatalog(all)
        setAssignedIds(current)
      })
      .catch(failure => {
        if (alive) setError(failure?.response ? getApiErrorMessage(failure) : failure.message || getApiErrorMessage(failure))
      })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [houseId, roomId])

  const current = useMemo(() => new Set(assignedIds.map(Number)), [assignedIds])
  const filtered = (catalog || []).filter(row => `${row.name || ''} ${row.type || ''}`.toLocaleLowerCase('vi').includes(search.toLocaleLowerCase('vi').trim()))
  const added = selectedIds.filter(id => !current.has(Number(id)))
  const toggle = id => setSelectedIds(previous => previous.includes(id) ? previous.filter(item => item !== id) : [...previous, id])

  const submit = async () => {
    if (!added.length || saving) return
    setSaving(true); setError('')
    try {
      // Re-read right before writing so another tab's assignments are kept.
      const currentRoom = assignedServiceIds(apiRows(await getRoomServices(roomId)))
      const combined = additiveServiceIds(currentRoom, added)
      await updateRoomServices(roomId, { service_ids: combined })
      // Room was persisted first; onApplied refreshes contract context.
      await onApplied(added)
      onClose()
    } catch (failure) {
      setError(failure?.response ? getApiErrorMessage(failure) : failure.message || getApiErrorMessage(failure))
    } finally { setSaving(false) }
  }

  return <div className="fixed inset-0 z-[100] flex items-end justify-center bg-slate-900/60 sm:items-center sm:p-4" onMouseDown={event => { if (event.target === event.currentTarget && !saving) onClose() }}>
    <section role="dialog" aria-modal="true" aria-labelledby="house-service-modal-title" className="flex h-[92vh] h-[92dvh] w-full max-w-xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:h-auto sm:max-h-[85vh] sm:rounded-2xl">
      <header className="flex shrink-0 items-start justify-between gap-3 border-b px-4 py-3 sm:p-5">
        <div className="min-w-0">
          <h3 id="house-service-modal-title" className="text-lg font-bold">Thêm dịch vụ vào phòng {roomCode}</h3>
          <p className="mt-1 text-sm text-slate-600">Lấy dịch vụ có sẵn trong nhà trọ. Không rời hợp đồng và không xóa dịch vụ hiện có.</p>
        </div>
        <button type="button" aria-label="Đóng popup" disabled={saving} onClick={onClose} className="min-h-11 min-w-11 shrink-0 rounded-lg border text-xl disabled:opacity-50">×</button>
      </header>
      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4 sm:p-5">
        <label className="block text-sm font-medium">Tìm dịch vụ
          <input ref={searchRef} type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Điện, nước, Internet..." className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        {loading && <p role="status" className="text-sm text-slate-600">Đang tải dịch vụ của nhà trọ...</p>}
        {!loading && catalog?.length === 0 && !error && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Nhà trọ chưa có dịch vụ đang hoạt động. Hãy tạo dịch vụ ở mục Dịch vụ trước khi thêm vào phòng.</p>}
        {!loading && catalog?.length > 0 && filtered.length === 0 && <p className="text-sm text-slate-600">Không tìm thấy dịch vụ phù hợp.</p>}
        {filtered.map(service => {
          const id = Number(service.id)
          const exists = current.has(id)
          const checked = exists || selectedIds.includes(id)
          return <label key={id} className={`flex min-h-14 items-start gap-3 rounded-xl border p-3 text-sm ${exists ? 'border-teal-200 bg-teal-50' : 'bg-white'}`}>
            <input type="checkbox" className="mt-1 h-4 w-4" checked={checked} disabled={exists || saving} onChange={() => toggle(id)} />
            <span className="min-w-0 flex-1">
              <span className="block break-words font-semibold">{service.name}</span>
              <span className="mt-0.5 block text-slate-600">{BILLING_METHOD_LABELS[service.billing_method] || service.billing_method} · {service.base_price == null ? 'Theo bảng giá' : formatCurrency(service.base_price)}</span>
              {exists && <span className="mt-1 block text-xs font-semibold text-teal-700">Đã áp dụng cho phòng</span>}
            </span>
          </label>
        })}
        {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      </div>
      <footer className="flex shrink-0 flex-col-reverse gap-2 border-t bg-white p-3 sm:flex-row sm:justify-end sm:p-4">
        <button type="button" disabled={saving} onClick={onClose} className="min-h-11 rounded-lg border px-4 font-medium disabled:opacity-50">Hủy</button>
        <button type="button" disabled={loading || saving || !catalog || added.length === 0} onClick={submit} className="min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white disabled:opacity-50">
          {saving ? 'Đang lưu...' : `Thêm ${added.length} dịch vụ vào phòng`}
        </button>
      </footer>
    </section>
  </div>
}
