import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, Navigate } from 'react-router-dom'
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
  if (housesQuery.isError) return <p className="text-red-600">{getApiErrorMessage(housesQuery.error)}</p>
  if (module === 'rooms') {
    const boardingHouse = houses[0]
    if (!boardingHouse) return <section className="min-w-0"><h2 className="text-xl font-bold sm:text-2xl">Phòng</h2><p className="mt-2 text-sm text-slate-600">Bạn chưa có nhà trọ để quản lý phòng.</p><Link to="/boarding-houses/new" className="mt-4 inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Tạo nhà trọ</Link></section>

    return <Navigate to={`/boarding-houses/${boardingHouse.id}/rooms`} replace />
  }
  return <section className="w-full min-w-0 max-w-3xl"><h2 className="text-xl font-bold sm:text-2xl">{labels[module]}</h2><p className="mt-1 text-sm text-slate-600">Chọn nhà trọ để tiếp tục.</p><select aria-label="Chọn nhà trọ" value={boardingHouseId} onChange={(e) => setBoardingHouseId(e.target.value)} className="mt-5 min-h-11 w-full min-w-0 rounded-lg border bg-white p-3"><option value="">Chọn nhà trọ</option>{houses.map((house) => <option key={house.id} value={house.id}>{house.name}</option>)}</select>{module === 'services' && boardingHouseId && <Link to={`/boarding-houses/${boardingHouseId}/services`} className="mt-4 inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Quản lý dịch vụ</Link>}{module !== 'services' && boardingHouseId && <div className="mt-6"><h3 className="font-semibold">Chọn phòng</h3>{roomsQuery.isPending ? <p className="mt-3">Đang tải phòng...</p> : roomsQuery.isError ? <p className="mt-3 text-sm text-red-600">{getApiErrorMessage(roomsQuery.error)}</p> : <div className="mt-3 grid gap-3 sm:grid-cols-2">{rooms.map((room) => <Link key={room.id} to={roomTarget(room)} className="min-w-0 break-words rounded-lg bg-white p-4 shadow-sm hover:bg-slate-50"><b>{room.room_code}</b><p className="text-sm text-slate-600">{room.room_name || 'Chưa đặt tên'}</p></Link>)}</div>}</div>}</section>
}
