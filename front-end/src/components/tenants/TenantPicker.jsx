import { notifyApiError } from '../../api/notificationTransport'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { lookupTenants } from '../../api/tenantLookupApi'
import { tenantKeys } from '../../api/tenantKeys'
import TenantPickerDialog from './TenantPickerDialog'

export default function TenantPicker({ value, onChange, multiple = false, initialTenants = [], contextRoomId, scope = 'WORKSPACE', excludeIds = [], label = 'Người thuê', allowClear = false, disabled = false, invalid = false, readOnly = false }) {
  const [open, setOpen] = useState(false)
  const [picked, setPicked] = useState([])
  const ids = (multiple ? value ?? [] : value ? [value] : []).map(Number)
  const known = new Map([...initialTenants, ...picked].filter(Boolean).map(tenant => [Number(tenant.id ?? tenant.tenant_id), { ...tenant, id: Number(tenant.id ?? tenant.tenant_id) }]))
  const missing = ids.filter(id => !known.has(id))
  const selectedQuery = useQuery({ queryKey: [...tenantKeys.all, 'lookup-selected', contextRoomId, scope, missing], queryFn: ({ signal }) => lookupTenants({ ids: missing, context_room_id: contextRoomId || undefined, scope, per_page: 100 }, signal), enabled: missing.length > 0, retry: false, staleTime: 30000, refetchOnWindowFocus: false })
  useEffect(() => { if (selectedQuery.error) notifyApiError(selectedQuery.error, 'Không thể tải người thuê đã chọn.') }, [selectedQuery.error])
  for (const tenant of selectedQuery.data?.data?.data?.tenants ?? []) known.set(tenant.id, tenant)
  const selected = ids.map(id => known.get(id) ?? { id, full_name: `Người thuê #${id}` })
  return <div className="min-w-0 space-y-2">
    {selected.length > 0 && <ul className="space-y-1 text-sm">{selected.map(tenant => <li key={tenant.id} className="break-words rounded-lg bg-slate-50 p-2">{tenant.full_name}{tenant.phone ? ` · ${tenant.phone}` : ''}</li>)}</ul>}
    {!readOnly && <div className="flex flex-wrap gap-2"><button type="button" disabled={disabled} aria-label={label} aria-haspopup="dialog" aria-expanded={open} aria-invalid={invalid || undefined} onClick={() => setOpen(true)} className="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:opacity-50">{selected.length ? 'Thay đổi' : 'Chọn người thuê'}</button>{allowClear && selected.length > 0 && <button type="button" disabled={disabled} onClick={() => onChange(multiple ? [] : null, [])} className="min-h-11 px-3 text-sm underline">Bỏ chọn</button>}</div>}
    {selectedQuery.isError && <p className="text-sm text-red-700">Không thể tải tên người thuê đã chọn. <button type="button" onClick={() => selectedQuery.refetch()} className="underline">Thử lại</button></p>}
    {open && <TenantPickerDialog selected={selected} multiple={multiple} onClose={() => setOpen(false)} onConfirm={tenants => { setPicked(tenants); onChange(multiple ? tenants.map(tenant => tenant.id) : tenants[0]?.id ?? null, tenants); setOpen(false) }} contextRoomId={contextRoomId} scope={scope} excludeIds={excludeIds} allowClear={allowClear} />}
  </div>
}
