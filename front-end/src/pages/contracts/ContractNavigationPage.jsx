import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getResidenceHouses, getResidenceRooms, residenceKeys } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

export default function ContractNavigationPage() {
  const [houseId, setHouseId] = useState('')
  // Reuse the existing OWNER/MANAGER-scoped house/room lookup, not owner-only catalogue APIs.
  const houses = useQuery({ queryKey: residenceKeys.houses, queryFn: getResidenceHouses })
  const rooms = useQuery({ queryKey: residenceKeys.rooms(houseId), queryFn: () => getResidenceRooms(houseId), enabled: Boolean(houseId) })
  return <section className="min-w-0 max-w-5xl space-y-5"><h2 className="text-xl font-bold sm:text-2xl">Hợp đồng</h2><p className="text-sm text-slate-500">Chọn nhà trọ và phòng để xem hợp đồng. Quản lý chỉ thấy nhà trọ được phân công.</p><label className="block text-sm font-medium" htmlFor="contract-house">Nhà trọ</label><select id="contract-house" value={houseId} onChange={event => setHouseId(event.target.value)} className="min-h-11 w-full min-w-0 rounded-lg border bg-white p-3"><option value="">Chọn nhà trọ</option>{(houses.data?.data?.data ?? []).map(house => <option key={house.id} value={String(house.id)}>{house.name}</option>)}</select>{houses.isPending && <p>Đang tải nhà trọ...</p>}{(houses.isError || (houseId && rooms.isError)) && <p className="text-sm text-red-700">{getApiErrorMessage(houses.error || rooms.error)}</p>}{houseId && rooms.isPending && <p>Đang tải phòng...</p>}{houseId && !rooms.isPending && !rooms.isError && <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{(rooms.data?.data?.data ?? []).map(room => <Link key={room.id} to={`/rooms/${room.id}/contracts`} className="min-h-11 min-w-0 break-words rounded-xl bg-white p-4 shadow-sm hover:bg-slate-50">Phòng {room.room_code}<span className="mt-2 block text-sm text-slate-500">Xem danh sách hợp đồng →</span></Link>)}{rooms.data?.data?.data?.length === 0 && <p>Nhà trọ này chưa có phòng.</p>}</div>}</section>
}
