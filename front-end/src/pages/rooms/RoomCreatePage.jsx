import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { createFloor, createRoom, createRoomsBulk, getFloors } from '../../api/roomApi'
import BulkRoomForm from '../../components/boarding-houses/BulkRoomForm'
import DormRoomCreateForm from '../../components/rooms/DormRoomCreateForm'

export default function RoomCreatePage() {
  const { boardingHouseId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [floorId, setFloorId] = useState('')
  const [newFloorName, setNewFloorName] = useState('')
  const [floorError, setFloorError] = useState('')
  const [roomType, setRoomType] = useState('PRIVATE_ROOM')
  const boardingHouseQuery = useQuery({ queryKey: boardingHouseKeys.detail(boardingHouseId), queryFn: () => getBoardingHouse(boardingHouseId) })
  const floorsQuery = useQuery({ queryKey: ['floors', boardingHouseId], queryFn: () => getFloors(boardingHouseId) })
  const mutation = useMutation({ mutationFn: (rooms) => createRoomsBulk(boardingHouseId, rooms, floorId ? Number(floorId) : null) })

  const addFloor = async () => {
    if (!newFloorName.trim()) return
    try {
      const response = await createFloor(boardingHouseId, { name: newFloorName.trim() })
      setFloorId(String(response.data.data.id)); setNewFloorName(''); setFloorError('')
      await queryClient.invalidateQueries({ queryKey: ['floors', boardingHouseId] })
    } catch (error) { setFloorError(getApiErrorMessage(error)) }
  }

  const handleSubmit = async (rooms) => {
    const response = await mutation.mutateAsync(rooms)
    await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(boardingHouseId) })
    navigate(`/boarding-houses/${boardingHouseId}/rooms`, { replace: true, state: { message: response.data.message } })
  }

  const handleDormSubmit = async (room) => {
    const response = await createRoom(boardingHouseId, { ...room, floor_id: floorId ? Number(floorId) : null })
    await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(boardingHouseId) })
    navigate(`/rooms/${response.data.data.id}`, { replace: true })
  }

  if (boardingHouseQuery.isPending) return <p>Đang tải nhà trọ...</p>
  if (boardingHouseQuery.isError) return <p className="text-red-600">{getApiErrorMessage(boardingHouseQuery.error)}</p>

  return (
    <section className="min-w-0 w-full max-w-5xl">
      <Link to={`/boarding-houses/${boardingHouseId}/rooms`} className="text-sm text-slate-600 hover:text-slate-950 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại danh sách phòng</Link>
      <h2 className="mb-1 mt-3 break-words text-xl font-bold sm:text-2xl">Thêm phòng</h2>
      <p className="mb-6 break-words text-sm text-slate-600">{boardingHouseQuery.data.data.data.name}</p>
      <div className="mb-5 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
        <div><label htmlFor="floor-select" className="mb-1 block text-sm font-medium">Tầng</label><select id="floor-select" value={floorId} onChange={(event) => setFloorId(event.target.value)} className="min-h-11 w-full rounded-lg border px-3 py-2 sm:max-w-sm"><option value="">Chưa thiết lập tầng</option>{(floorsQuery.data?.data?.data || []).map((floor) => <option key={floor.id} value={String(floor.id)}>{floor.name}</option>)}</select>{floorsQuery.isError && <p className="text-sm text-red-700">{getApiErrorMessage(floorsQuery.error)}</p>}</div>
        <div className="flex flex-col gap-2 sm:flex-row"><input aria-label="Tên tầng mới" placeholder="Tên tầng mới, ví dụ Tầng 2" value={newFloorName} onChange={(event) => setNewFloorName(event.target.value)} className="min-h-11 rounded-lg border px-3 py-2 sm:w-72" /><button type="button" onClick={addFloor} className="min-h-11 rounded-lg border px-3 py-2 text-sm">+ Thêm tầng</button></div>
        {floorError && <p role="alert" className="text-sm text-red-700">{floorError}</p>}
        <fieldset><legend className="mb-2 text-sm font-medium">Loại phòng</legend><div className="flex flex-wrap gap-5 text-sm"><label className="flex items-center gap-2"><input type="radio" checked={roomType === 'PRIVATE_ROOM'} onChange={() => setRoomType('PRIVATE_ROOM')} />Phòng trọ</label><label className="flex items-center gap-2"><input type="radio" checked={roomType === 'DORMITORY'} onChange={() => setRoomType('DORMITORY')} />Phòng KTX</label></div></fieldset>
      </div>
      {roomType === 'PRIVATE_ROOM' ? <BulkRoomForm initialRooms={[]} onSubmit={handleSubmit} submitLabel={(count) => `Thêm ${count} phòng`} /> : <DormRoomCreateForm onSubmit={handleDormSubmit} />}
    </section>
  )
}
import { useState } from 'react'
