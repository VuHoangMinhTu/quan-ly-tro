import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getBoardingHouses } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getRoomsByBoardingHouse } from '../../api/roomApi'
import { roomKeys } from '../../api/roomKeys'

const labels = { rooms: 'Phòng', contracts: 'Hợp đồng', services: 'Dịch vụ', utilities: 'Điện nước', invoices: 'Hóa đơn' }
export default function ModuleNavigationPage({ module }) {
  const [boardingHouseId, setBoardingHouseId] = useState('')
  const housesQuery = useQuery({ queryKey: boardingHouseKeys.all, queryFn: getBoardingHouses })
  const roomsQuery = useQuery({ queryKey: roomKeys.byBoardingHouse(boardingHouseId), queryFn: () => getRoomsByBoardingHouse(boardingHouseId), enabled: Boolean(boardingHouseId) })
  const houses = housesQuery.data?.data?.data || []
  const rooms = roomsQuery.data?.data?.data || []
  const roomTarget = (room) => module === 'rooms' ? `/rooms/${room.id}` : module === 'contracts' ? `/rooms/${room.id}/contracts` : module === 'utilities' ? `/rooms/${room.id}` : `/rooms/${room.id}/invoices`
  if (housesQuery.isPending) return <p>Đang tải nhà trọ...</p>
  return <section className="max-w-3xl"><h2 className="text-2xl font-bold">{labels[module]}</h2><p className="mt-1 text-sm text-slate-600">Chọn nhà trọ để tiếp tục.</p><select value={boardingHouseId} onChange={(e) => setBoardingHouseId(e.target.value)} className="mt-5 w-full rounded-lg border bg-white p-3"><option value="">Chọn nhà trọ</option>{houses.map((house) => <option key={house.id} value={house.id}>{house.name}</option>)}</select>{module === 'services' && boardingHouseId && <Link to={`/boarding-houses/${boardingHouseId}/services`} className="mt-4 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Quản lý dịch vụ</Link>}{module !== 'services' && boardingHouseId && <div className="mt-6"><h3 className="font-semibold">Chọn phòng</h3>{roomsQuery.isPending ? <p className="mt-3">Đang tải phòng...</p> : <div className="mt-3 grid gap-3 sm:grid-cols-2">{rooms.map((room) => <Link key={room.id} to={roomTarget(room)} className="rounded-lg bg-white p-4 shadow-sm hover:bg-slate-50"><b>{room.room_code}</b><p className="text-sm text-slate-600">{room.room_name || 'Chưa đặt tên'}</p></Link>)}</div>}</div>}</section>
}
