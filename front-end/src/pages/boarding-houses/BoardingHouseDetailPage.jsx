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

  return (
    <section className="max-w-5xl">
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link to="/boarding-houses" className="text-sm text-slate-600 hover:text-slate-950">← Quay lại danh sách</Link>
          <h2 className="mt-3 text-2xl font-bold">{house.name}</h2>
        </div>
        <div className="flex items-center gap-3">
          <Link to={`/boarding-houses/${id}/edit`} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><Pencil size={16} /> Sửa</Link>
          {isConfirmingDelete ? <span className="flex items-center gap-2"><button onClick={() => houseDeleteMutation.mutate()} disabled={houseDeleteMutation.isPending} className="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{houseDeleteMutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setIsConfirmingDelete(false)} className="text-sm text-slate-600">Hủy</button></span> : <button onClick={() => setIsConfirmingDelete(true)} className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"><Trash2 size={16} /> Xóa</button>}
        </div>
      </div>

      {location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}
      {deleteError && <p className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{deleteError}</p>}

      <div className="rounded-xl bg-white p-6 shadow-sm">
        <dl className="space-y-5"><div><dt className="text-sm font-medium text-slate-500">Địa chỉ</dt><dd className="mt-1 text-slate-900">{house.address}</dd></div><div><dt className="text-sm font-medium text-slate-500">Mô tả</dt><dd className="mt-1 whitespace-pre-wrap text-slate-900">{house.description || 'Chưa có mô tả.'}</dd></div></dl>
      </div>

      <div className="mt-6 rounded-xl bg-white p-6 shadow-sm"><div className="flex items-center justify-between gap-3"><div><h3 className="text-lg font-semibold">Dịch vụ</h3><p className="mt-1 text-sm text-slate-600">Quản lý các khoản thu của nhà trọ.</p></div><Link to={`/boarding-houses/${id}/services`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Quản lý dịch vụ</Link></div></div>

      <div className="mt-8">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-xl font-bold">Phòng</h3><p className="mt-1 text-sm text-slate-600">{roomsQuery.isPending ? 'Đang tải phòng...' : `${rooms.length} phòng`}</p></div><div className="flex gap-3"><Link to={`/boarding-houses/${id}/rooms`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Xem tất cả</Link><Link to={`/boarding-houses/${id}/rooms/new`} className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"><Plus size={17} /> Thêm phòng</Link></div></div>
        {roomsQuery.isError ? <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{getApiErrorMessage(roomsQuery.error)}</p> : rooms.length === 0 && !roomsQuery.isPending ? <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center"><Building2 className="mx-auto mb-3 text-slate-400" size={32} /><p className="font-medium">Nhà trọ này chưa có phòng.</p></div> : <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{rooms.map((room) => <RoomCard key={room.id} room={room} actions={confirmingRoomId === room.id ? <div className="flex items-center gap-3 text-sm"><button onClick={() => roomDeleteMutation.mutate(room.id)} disabled={roomDeleteMutation.isPending} className="font-medium text-red-700 disabled:opacity-60">{roomDeleteMutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setConfirmingRoomId(null)} className="text-slate-500">Hủy</button></div> : <div className="flex gap-4 text-sm font-medium"><Link to={`/rooms/${room.id}`} className="text-slate-700 hover:text-slate-950">Xem</Link><Link to={`/rooms/${room.id}/edit`} className="text-slate-700 hover:text-slate-950">Sửa</Link><button onClick={() => setConfirmingRoomId(room.id)} className="text-red-700 hover:text-red-900">Xóa</button></div>} />)}</div>}
      </div>
    </section>
  )
}
