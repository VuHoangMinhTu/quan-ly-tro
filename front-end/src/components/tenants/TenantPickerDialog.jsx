import { useEffect, useId, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery } from '@tanstack/react-query'
import { getTenantLookupOptions, lookupTenants } from '../../api/tenantLookupApi'
import { tenantKeys } from '../../api/tenantKeys'
import { notifyApiError } from '../../api/notificationTransport'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { Link } from 'react-router-dom'

const statusLabels = { ACTIVE: 'Đang ở', UNASSIGNED: 'Chưa có phòng', MOVED_OUT: 'Đã chuyển đi' }
const inputClass = 'min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-base sm:text-sm'
const buttonClass = 'min-h-11 rounded-lg border px-3 py-2 text-sm disabled:opacity-40'

export default function TenantPickerDialog({ selected, multiple, onConfirm, onClose, contextRoomId, scope = 'WORKSPACE', excludeIds = [], allowClear = false }) {
  const dialog = useRef(null)
  const title = useId()
  const [draft, setDraft] = useState(() => new Map(selected.map(tenant => [Number(tenant.id), tenant])))
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [houseId, setHouseId] = useState('')
  const [roomId, setRoomId] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  useEffect(() => {
    const previous = document.activeElement
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const app = document.getElementById('root')
    const wasInert = app?.inert
    if (app) app.inert = true
    dialog.current.querySelector('input')?.focus()
    return () => { document.body.style.overflow = overflow; if (app) app.inert = wasInert; previous?.focus?.() }
  }, [])
  useEffect(() => { if (search.trim() === debounced) return; const timer = setTimeout(() => { setDebounced(search.trim()); setPage(1) }, 300); return () => clearTimeout(timer) }, [search, debounced])
  const base = { context_room_id: contextRoomId || undefined, scope }
  const filters = { ...base, search: debounced || undefined, boarding_house_id: houseId || undefined, room_id: roomId || undefined, occupancy_status: status || undefined, page, per_page: 20, exclude_ids: excludeIds.map(Number) }
  const query = useQuery({ queryKey: [...tenantKeys.all, 'lookup', filters], queryFn: ({ signal }) => lookupTenants(filters, signal), retry: false, staleTime: 15000, refetchOnWindowFocus: false })
  const options = useQuery({ queryKey: [...tenantKeys.all, 'lookup-options', contextRoomId, scope, houseId], queryFn: ({ signal }) => getTenantLookupOptions({ ...base, boarding_house_id: houseId || undefined }, signal), enabled: scope !== 'ACTIVE_ROOM', retry: false, staleTime: 30000, refetchOnWindowFocus: false })
  useEffect(() => { if (query.error) notifyApiError(query.error, 'Không thể tải danh sách người thuê. Vui lòng thử lại.') }, [query.error])
  useEffect(() => { if (options.error) notifyApiError(options.error, 'Không thể tải bộ lọc người thuê. Vui lòng thử lại.') }, [options.error])
  const data = query.data?.data?.data
  const choices = options.data?.data?.data
  const toggle = tenant => setDraft(current => {
    const next = multiple ? new Map(current) : new Map()
    if (multiple && next.has(tenant.id)) next.delete(tenant.id)
    else next.set(tenant.id, tenant)
    return next
  })
  const trapFocus = event => {
    if (event.key === 'Escape') { event.preventDefault(); onClose(); return }
    if (event.key !== 'Tab') return
    const controls = [...dialog.current.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href]')].filter(control => control.getClientRects().length)
    const first = controls[0], last = controls.at(-1)
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
  }
  return createPortal(<div onClick={event => { if (event.target === event.currentTarget) onClose() }} className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-2 sm:p-4">
    <div ref={dialog} role="dialog" aria-modal="true" aria-labelledby={title} onKeyDown={trapFocus} className="flex h-[94dvh] max-h-[94dvh] min-h-0 w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl sm:h-auto sm:max-h-[90dvh]">
      <header className="flex shrink-0 items-center justify-between gap-3 border-b p-4"><h2 id={title} className="text-lg font-bold">Chọn người thuê</h2><button type="button" onClick={onClose} aria-label="Đóng chọn người thuê" className={buttonClass}>✕</button></header>
      <div className="shrink-0 space-y-3 border-b p-4">
        <label className="block text-sm">Tìm người thuê<input type="search" value={search} onChange={event => setSearch(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') event.preventDefault() }} placeholder={data?.can_search_identity ? 'Tên, số điện thoại hoặc CCCD' : 'Tên hoặc số điện thoại'} className={inputClass} /></label>
        {scope === 'ACTIVE_ROOM' ? <p className="text-sm text-slate-600">Chỉ hiển thị người đang ở phòng đã chọn.</p> : <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          <label className="text-sm">Nhà trọ<select aria-label="Nhà trọ" value={houseId} onChange={event => { setHouseId(event.target.value); setRoomId(''); setPage(1) }} className={inputClass}><option value="">Tất cả nhà trọ</option>{choices?.houses.map(house => <option key={house.id} value={house.id}>{house.name}</option>)}</select></label>
          <label className="text-sm">Phòng<select aria-label="Phòng" value={roomId} disabled={!houseId || options.isFetching} onChange={event => { setRoomId(event.target.value); setPage(1) }} className={inputClass}><option value="">Tất cả phòng</option>{choices?.rooms.map(room => <option key={room.id} value={room.id}>{room.room_code}</option>)}</select></label>
          <label className="col-span-2 text-sm sm:col-span-1">Trạng thái cư trú<select aria-label="Trạng thái cư trú" value={status} onChange={event => { setStatus(event.target.value); setPage(1) }} className={inputClass}><option value="">Tất cả người thuê</option>{Object.entries(statusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
        </div>}
        {options.isError && <p role="alert" className="text-sm text-red-700">Không thể tải bộ lọc. <button type="button" onClick={() => options.refetch()} className="underline">Thử lại bộ lọc</button></p>}
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4" aria-busy={query.isFetching}>
        {query.isPending ? <p role="status">Đang tải người thuê...</p> : query.isError ? <div role="alert" className="space-y-2 text-sm text-red-700"><p>{getApiErrorMessage(query.error)}</p><button type="button" onClick={() => query.refetch()} className={buttonClass}>Thử lại</button></div> : <>
          {data.tenants.length === 0 && <div className="space-y-2"><p>Không tìm thấy người thuê phù hợp.</p>{choices?.can_create && scope !== 'ACTIVE_ROOM' && <Link to="/tenants/new" target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center underline">Thêm người thuê</Link>}<button type="button" onClick={() => query.refetch()} className={buttonClass}>Cập nhật danh sách</button></div>}
          <div className="space-y-2">{data.tenants.map(tenant => <label key={tenant.id} className={`flex min-h-16 cursor-pointer items-start gap-3 rounded-lg border p-3 ${draft.has(tenant.id) ? 'border-green-700 bg-green-50' : 'border-slate-200'}`}>
            <input className="mt-1 size-5 shrink-0" type={multiple ? 'checkbox' : 'radio'} name={title} checked={draft.has(tenant.id)} onChange={() => toggle(tenant)} />
            <span className="min-w-0 break-words text-sm"><strong className="block">{tenant.full_name}</strong><span className="block text-slate-600">{tenant.phone || 'Chưa có số điện thoại'}</span><span className="block text-slate-600">{statusLabels[tenant.occupancy_status]}{tenant.current_residence ? ` · ${tenant.current_residence.boarding_house_name} / Phòng ${tenant.current_residence.room_code}` : ''}</span></span>
          </label>)}</div>
        </>}
      </div>
      <footer className="shrink-0 space-y-3 border-t bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><span>{data?.total ?? 0} kết quả · Đã chọn {draft.size} <button type="button" disabled={query.isFetching} onClick={() => query.refetch()} className="underline disabled:opacity-40">Làm mới</button></span><div className="flex items-center gap-2"><button type="button" disabled={page <= 1 || query.isFetching} onClick={() => setPage(page - 1)} className={buttonClass}>Trước</button><span>Trang {page}/{data?.last_page ?? 1}</span><button type="button" disabled={!data || page >= data.last_page || query.isFetching} onClick={() => setPage(page + 1)} className={buttonClass}>Sau</button></div></div>
        <div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={buttonClass}>Hủy</button><button type="button" disabled={!multiple && !allowClear && draft.size === 0} onClick={() => onConfirm([...draft.values()])} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-40">Xác nhận chọn</button></div>
      </footer>
    </div>
  </div>, document.body)
}
