import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { createResidenceDeclaration, getResidenceContext, getResidenceDeclaration, getResidenceHouses, getResidenceRooms, residenceKeys, updateResidenceDeclaration, updateResidenceStatus } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import ResidenceDeclarationForm from '../../components/residence/ResidenceDeclarationForm'
import ResidenceHouseSettings from '../../components/residence/ResidenceHouseSettings'

export default function ResidenceDeclarationEditPage() {
  const { id } = useParams()
  const [search] = useSearchParams()
  const [selectedRoom, setSelectedRoom] = useState(null)
  const [selectedHouse, setSelectedHouse] = useState(null)
  const client = useQueryClient()
  const navigate = useNavigate()
  const recordQuery = useQuery({ queryKey: residenceKeys.detail(id), queryFn: () => getResidenceDeclaration(id), enabled: Boolean(id) })
  const original = recordQuery.data?.data?.data
  const roomId = selectedRoom ?? original?.declaration.room_id ?? search.get('room_id') ?? ''
  const contractId = !id && selectedRoom === null ? search.get('contract_id') : null
  const contextQuery = useQuery({ queryKey: residenceKeys.context(roomId, contractId), queryFn: () => getResidenceContext(roomId, contractId), enabled: Boolean(roomId) })
  const context = contextQuery.data?.data?.data
  const houseId = selectedHouse ?? context?.room.boarding_house_id ?? search.get('boarding_house_id') ?? ''
  const houses = useQuery({ queryKey: residenceKeys.houses, queryFn: getResidenceHouses })
  const rooms = useQuery({ queryKey: residenceKeys.rooms(houseId), queryFn: () => getResidenceRooms(houseId), enabled: Boolean(houseId) })
  const save = useMutation({ mutationFn: async ({ payload, intent }) => {
    const response = await (id ? updateResidenceDeclaration(id, payload) : createResidenceDeclaration(payload))
    const recordId = id ?? response.data.data.id
    let finishError = null
    if (intent === 'finish') {
      try { await updateResidenceStatus(recordId, { status: 'SUBMITTED' }) } catch (error) { finishError = getApiErrorMessage(error) }
    }
    return { recordId, finishError }
  }, onSuccess: async ({ recordId, finishError }) => {
    await Promise.all([client.invalidateQueries({ queryKey: residenceKeys.list }), client.invalidateQueries({ queryKey: residenceKeys.detail(recordId) })])
    navigate(`/residence-declarations/${recordId}`, { replace: true, state: finishError ? { finishError } : null })
  } })
  if (id && recordQuery.isPending) return <p>Đang tải hồ sơ...</p>
  if (id && recordQuery.isError) return <p className="text-sm text-red-700">{getApiErrorMessage(recordQuery.error)}</p>
  if (original && (original.is_frozen || !['DRAFT', 'PENDING'].includes(original.declaration.status))) return <p>Hồ sơ đã chốt không được sửa nội dung. <Link to={`/residence-declarations/${id}`} className="underline">Quay lại hồ sơ</Link></p>
  return <section className="min-w-0 max-w-4xl space-y-5"><Link to={id ? `/residence-declarations/${id}` : '/residence-declarations'} className="inline-flex min-h-11 items-center text-sm underline">← Quay lại</Link><h2 className="text-xl font-bold sm:text-2xl">{id ? 'Chỉnh sửa hồ sơ cư trú' : 'Tạo hồ sơ tạm trú'}</h2>
    {!id && <div className="grid gap-4 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2"><div><label htmlFor="ct01-house" className="text-sm font-medium">Nhà trọ *</label><select id="ct01-house" value={String(houseId)} onChange={event => { setSelectedHouse(event.target.value); setSelectedRoom('') }} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2"><option value="">Chọn nhà trọ</option>{(houses.data?.data?.data ?? []).map(house => <option key={house.id} value={String(house.id)}>{house.name} · {house.operational_role === 'OWNER' ? 'Chủ workspace' : 'Được phân công'}</option>)}</select></div><div><label htmlFor="ct01-room" className="text-sm font-medium">Phòng *</label><select id="ct01-room" value={String(roomId)} disabled={!houseId || rooms.isPending || rooms.isError} onChange={event => setSelectedRoom(event.target.value)} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2 disabled:bg-slate-50"><option value="">Chọn phòng</option>{(rooms.data?.data?.data ?? []).map(room => <option key={room.id} value={String(room.id)}>{room.room_code}{room.room_name ? ` — ${room.room_name}` : ''}</option>)}</select></div>
      {houses.isError && <p className="text-sm text-red-700">{getApiErrorMessage(houses.error)}</p>}{rooms.isError && <p className="text-sm text-red-700">{getApiErrorMessage(rooms.error)}</p>}{houseId && !rooms.isPending && !rooms.isError && rooms.data && rooms.data.data.data.length === 0 && <p className="text-sm text-slate-500">Nhà trọ này chưa có phòng.</p>}
    </div>}
    {roomId && (contextQuery.isPending ? <p>Đang tải người đang ở...</p> : contextQuery.isError ? <p className="text-sm text-red-700">{getApiErrorMessage(contextQuery.error)}</p> : <><p className="break-words text-sm text-slate-500">{context.room.boarding_house.name} · Phòng {context.room.room_code}</p><ResidenceDeclarationForm key={`${id ?? 'new'}-${roomId}`} context={context} initial={original?.declaration ?? context.suggested_declaration} initialPeople={original?.ct01.members} onSubmit={(payload, intent) => save.mutateAsync({ payload, intent })} /><ResidenceHouseSettings key={`settings-${houseId}`} houseId={context.room.boarding_house_id} role={context.operational_role} /></>)}
  </section>
}
