import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil, Plus, Trash2 } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { deleteBoardingHouse, getBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { deleteRoom, getRoomsByBoardingHouse } from '../../api/roomApi'
import RoomCard from '../../components/rooms/RoomCard'
import ResidenceHouseSettings from '../../components/residence/ResidenceHouseSettings'
import ContractScheduleSettings from '../../components/contracts/ContractScheduleSettings'

export default function BoardingHouseDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [isConfirmingDelete, setIsConfirmingDelete] = useState(false)
  const [confirmingRoomId, setConfirmingRoomId] = useState(null)
  const [deleteError, setDeleteError] = useState('')
  const houseQuery = useQuery({ queryKey: boardingHouseKeys.detail(id), queryFn: () => getBoardingHouse(id) })
  const roomsQuery = useQuery({ queryKey: roomKeys.byBoardingHouse(id), queryFn: () => getRoomsByBoardingHouse(id) })
  const houseDeleteMutation = useMutation({
    mutationFn: () => deleteBoardingHouse(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all })
      queryClient.removeQueries({ queryKey: boardingHouseKeys.detail(id) })
      navigate('/boarding-houses', { replace: true, state: { message: 'Xóa nhà trọ thành công.' } })
    },
    onError: (error) => setDeleteError(getApiErrorMessage(error)),
  })
  const roomDeleteMutation = useMutation({
    mutationFn: deleteRoom,
    onSuccess: async () => {
      setConfirmingRoomId(null)
      await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(id) })
    },
    onError: (error) => setDeleteError(getApiErrorMessage(error)),
  })

  if (houseQuery.isPending) return <p>Đang tải thông tin nhà trọ...</p>
  if (houseQuery.isError) return <p className="text-red-600">{getApiErrorMessage(houseQuery.error)}</p>

  const house = houseQuery.data.data.data
  const rooms = roomsQuery.data?.data?.data || []
  const roomGroups = rooms.reduce((groups, room) => {
    const floorName = room.floor?.name || 'Chưa thiết lập tầng'
    groups[floorName] = [...(groups[floorName] || []), room]
    return groups
  }, {})

  return (
    <section className="min-w-0 w-full max-w-5xl">
      <div className="mb-6 flex flex-col items-start gap-4 sm:flex-row sm:justify-between">
        <div className="min-w-0 max-w-full sm:flex-1">
          <Link to="/boarding-houses" className="text-sm text-slate-600 hover:text-slate-950 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại danh sách</Link>
          <h2 className="mt-3 break-words text-xl font-bold sm:text-2xl">{house.name}</h2>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Link to={`/boarding-houses/${id}/edit`} className="inline-flex items-center gap-2 min-h-11 sm:min-h-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><Pencil size={16} /> Sửa</Link>
          {isConfirmingDelete ? <span className="flex flex-wrap items-center gap-2"><button onClick={() => houseDeleteMutation.mutate()} disabled={houseDeleteMutation.isPending} className="min-h-11 sm:min-h-0 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{houseDeleteMutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setIsConfirmingDelete(false)} className="min-h-11 sm:min-h-0 px-2 text-sm text-slate-600">Hủy</button></span> : <button onClick={() => setIsConfirmingDelete(true)} className="inline-flex items-center gap-2 min-h-11 sm:min-h-0 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"><Trash2 size={16} /> Xóa</button>}
        </div>
      </div>

      {location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}
      {deleteError && <p className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{deleteError}</p>}

      <div className="min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm">
        <dl className="space-y-5"><div><dt className="text-sm font-medium text-slate-500">Địa chỉ</dt><dd className="mt-1 break-words text-slate-900">{house.address}</dd></div><div><dt className="text-sm font-medium text-slate-500">Mô tả</dt><dd className="mt-1 whitespace-pre-wrap break-words text-slate-900">{house.description || 'Chưa có mô tả.'}</dd></div></dl>
      </div>

      <div className="mt-6 min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm"><div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 className="text-lg font-semibold">Dịch vụ</h3><p className="mt-1 text-sm text-slate-600">Quản lý các khoản thu của nhà trọ.</p></div><Link to={`/boarding-houses/${id}/services`} className="min-h-11 sm:min-h-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Quản lý dịch vụ</Link></div></div>

      <div className="mt-8">
        <ContractScheduleSettings key={house.id} house={house} />
        <section className="mb-6 space-y-3"><div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-semibold">Khai báo cư trú</h3><Link to={`/residence-declarations/new?boarding_house_id=${id}`} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Tạo hồ sơ tạm trú</Link></div><ResidenceHouseSettings houseId={id} /></section>
        <div className="mb-4 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 className="text-xl font-bold">Phòng</h3><p className="mt-1 text-sm text-slate-600">{roomsQuery.isPending ? 'Đang tải phòng...' : `${rooms.length} phòng`}</p></div><div className="flex flex-wrap items-center gap-3"><Link to={`/boarding-houses/${id}/rooms`} className="min-h-11 sm:min-h-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Xem tất cả</Link><Link to={`/boarding-houses/${id}/rooms/new`} className="inline-flex min-h-11 sm:min-h-0 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"><Plus size={17} /> Thêm phòng</Link></div></div>
        {roomsQuery.isError ? <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{getApiErrorMessage(roomsQuery.error)}</p> : rooms.length === 0 && !roomsQuery.isPending ? <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center"><Building2 className="mx-auto mb-3 text-slate-400" size={32} /><p className="font-medium">Nhà trọ này chưa có phòng.</p></div> : Object.entries(roomGroups).map(([floorName, floorRooms]) => <div key={floorName} className="mb-6"><h4 className="mb-3 font-semibold">{floorName}</h4><div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{floorRooms.map((room) => <RoomCard key={room.id} room={room} actions={<div className="flex flex-wrap gap-3 text-sm"><Link to={`/rooms/${room.id}`} className="underline">Xem</Link><Link to={`/rooms/${room.id}/edit`} className="underline">Sửa</Link>{room.status !== 'INACTIVE' && (confirmingRoomId === room.id ? <><span className="w-full text-slate-600">{room.has_history ? 'Phòng này đã có dữ liệu lịch sử nên không thể xóa vĩnh viễn.' : 'Phòng chưa có dữ liệu phát sinh và sẽ bị xóa vĩnh viễn.'}</span><button onClick={() => roomDeleteMutation.mutate(room.id)} className="text-red-700">{room.has_history ? 'Ngừng sử dụng phòng' : 'Xác nhận xóa'}</button><button onClick={() => setConfirmingRoomId(null)}>Hủy</button></> : <button onClick={() => setConfirmingRoomId(room.id)} className="text-red-700">{room.has_history ? 'Ngừng sử dụng' : 'Xóa'}</button>)}</div>} />)}</div></div>)}
      </div>
    </section>
  )
}
