import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { deleteResidenceDeclaration, getResidenceDeclarations, residenceKeys } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { residenceStatusLabels } from '../../utils/residence'

export default function ResidenceDeclarationListPage() {
  const client = useQueryClient()
  const [search] = useSearchParams()
  const roomId = search.get('room_id')
  const query = useQuery({ queryKey: [...residenceKeys.list, roomId || 'all'], queryFn: () => getResidenceDeclarations(roomId) })
  const remove = useMutation({ mutationFn: deleteResidenceDeclaration, onSuccess: async () => {
    await client.invalidateQueries({ queryKey: residenceKeys.list })
  } })
  const records = query.data?.data?.data ?? []
  return <section className="min-w-0 space-y-5"><div className="flex flex-col items-start justify-between gap-3 sm:flex-row"><div><h2 className="text-xl font-bold sm:text-2xl">Khai báo cư trú</h2><p className="mt-1 text-sm text-slate-500">Chuẩn bị dữ liệu, xem trước và in CT01. BeeHouse không tự gửi hồ sơ đến cơ quan cư trú.</p></div><Link className="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-slate-900 px-4 py-2 text-sm text-white" to={`/residence-declarations/new${roomId ? `?room_id=${roomId}` : ''}`}>Tạo hồ sơ tạm trú</Link></div>
    {roomId && <Link to="/residence-declarations" className="inline-flex min-h-11 items-center text-sm underline">Xem hồ sơ của tất cả phòng được phép</Link>}
    {remove.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(remove.error)}</p>}
    {query.isPending ? <p>Đang tải hồ sơ...</p> : query.isError ? <p className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p> : records.length === 0 ? <p className="rounded-xl border border-dashed p-6 text-sm text-slate-500">Chưa có hồ sơ cư trú.</p> : <div className="grid gap-3 md:grid-cols-2">{records.map(record => <article key={record.id} className="min-w-0 space-y-2 rounded-xl bg-white p-4 shadow-sm"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-semibold">CT01 #{record.id}</h3><span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{residenceStatusLabels[record.status]}</span></div><p className="break-words text-sm">{record.declarant_name || record.snapshot?.declarant?.full_name || record.declarant?.full_name || 'Chưa chọn người kê khai'}</p><p className="break-words text-sm text-slate-500">{record.boarding_house_name || record.snapshot?.residence?.boarding_house_name || record.room?.boarding_house?.name} · Phòng {record.room_code || record.snapshot?.residence?.room_code || record.room?.room_code}</p><div className="flex flex-wrap items-center gap-3"><Link to={`/residence-declarations/${record.id}`} className="inline-flex min-h-11 items-center text-sm underline">Xem hồ sơ / Preview CT01</Link>{record.status === 'DRAFT' && !record.is_frozen && <button type="button" disabled={remove.isPending} onClick={() => window.confirm(`Xóa hồ sơ CT01 #${record.id}? Thao tác này không thể hoàn tác.`) && remove.mutate(record.id)} className="min-h-11 rounded-lg border border-red-200 px-3 text-sm text-red-700 disabled:opacity-50">Xóa nháp</button>}</div></article>)}</div>}
  </section>
}
