import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createBed, getBeds, updateBed } from '../../api/roomApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'
import MoneyInput from '../ui/MoneyInput'

const labels = { AVAILABLE: 'Trống', OCCUPIED: 'Đang sử dụng', MAINTENANCE: 'Bảo trì' }

export default function RoomBedsSection({ room }) {
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState('')
  const queryClient = useQueryClient()
  const queryKey = ['beds', room.id]
  const bedsQuery = useQuery({ queryKey, queryFn: () => getBeds(room.id) })
  const mutation = useMutation({ mutationFn: (payload) => payload.id ? updateBed(payload.id, payload) : createBed(room.id, payload), onSuccess: async () => { setEditing(null); setError(''); await queryClient.invalidateQueries({ queryKey }) } })
  const beds = bedsQuery.data?.data?.data || []
  const occupied = beds.filter((bed) => bed.room_tenants?.some((membership) => membership.status === 'ACTIVE')).length
  const available = beds.filter((bed) => bed.status === 'AVAILABLE' && !bed.room_tenants?.some((membership) => membership.status === 'ACTIVE')).length

  const save = async (event) => {
    event.preventDefault()
    try { await mutation.mutateAsync({ ...editing, monthly_rent: editing.monthly_rent === '' ? null : Number(editing.monthly_rent) }) }
    catch (apiError) { setError(getApiErrorMessage(apiError)) }
  }

  return <section className="mt-6 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-lg font-semibold">Giường / Chỗ ở</h3><p className="text-sm text-slate-600">{beds.length} giường · {occupied} đang sử dụng · {available} còn trống</p></div><button type="button" onClick={() => setEditing({ bed_code: '', monthly_rent: '', status: 'AVAILABLE', note: '' })} className="rounded-lg border px-3 py-2 text-sm">+ Thêm giường</button></div>
    {bedsQuery.isError && <p className="mt-3 text-sm text-red-700">{getApiErrorMessage(bedsQuery.error)}</p>}
      <div className="mt-4 grid gap-3 sm:grid-cols-2">{beds.map((bed) => { const occupant = bed.room_tenants?.find((membership) => membership.status === 'ACTIVE')?.tenant; return <article key={bed.id} className="rounded-lg border p-4"><div className="flex justify-between gap-3"><p className="font-semibold">{bed.bed_code}</p><span className="text-sm">{occupant ? labels.OCCUPIED : labels[bed.status] || bed.status}</span></div><p className="mt-2 text-sm">{bed.monthly_rent == null ? 'Chưa thiết lập giá' : `${formatCurrency(bed.monthly_rent)}/tháng`}</p>{occupant && <p className="mt-1 text-sm text-slate-600">{occupant.full_name}</p>}{bed.note && <p className="mt-1 text-sm text-slate-500">{bed.note}</p>}<div className="mt-3 flex flex-wrap gap-3"><button type="button" onClick={() => setEditing({ id: bed.id, bed_code: bed.bed_code, monthly_rent: bed.monthly_rent ?? '', status: bed.status, note: bed.note ?? '' })} className="text-sm underline">Sửa</button>{!occupant && bed.status === 'AVAILABLE' && <button type="button" onClick={async () => { try { await mutation.mutateAsync({ id: bed.id, status: 'MAINTENANCE' }) } catch (apiError) { setError(getApiErrorMessage(apiError)) } }} className="text-sm underline">Đánh dấu bảo trì</button>}</div></article> })}</div>
    {editing && <form onSubmit={save} className="mt-4 grid gap-3 rounded-lg border p-4 sm:grid-cols-2"><label className="text-sm">Mã giường<input required value={editing.bed_code} onChange={(event) => setEditing({ ...editing, bed_code: event.target.value })} className="mt-1 min-h-11 w-full rounded border px-3" /></label><label className="text-sm">Giá thuê/tháng<MoneyInput value={editing.monthly_rent} onChange={(value) => setEditing({ ...editing, monthly_rent: value })} className="mt-1 min-h-11 w-full rounded border px-3" /></label><label className="text-sm">Trạng thái<select disabled={editing.status === 'OCCUPIED'} value={editing.status} onChange={(event) => setEditing({ ...editing, status: event.target.value })} className="mt-1 min-h-11 w-full rounded border px-3"><option value="AVAILABLE">Trống</option><option value="OCCUPIED">Đang sử dụng</option><option value="MAINTENANCE">Bảo trì</option></select></label><label className="text-sm">Ghi chú<input value={editing.note} onChange={(event) => setEditing({ ...editing, note: event.target.value })} className="mt-1 min-h-11 w-full rounded border px-3" /></label><div className="flex gap-3 sm:col-span-2"><button disabled={mutation.isPending} className="rounded bg-slate-900 px-4 py-2 text-sm text-white">Lưu</button><button type="button" onClick={() => setEditing(null)} className="rounded border px-4 py-2 text-sm">Hủy</button></div></form>}
    {error && <p role="alert" className="mt-2 text-sm text-red-700">{error}</p>}
  </section>
}
