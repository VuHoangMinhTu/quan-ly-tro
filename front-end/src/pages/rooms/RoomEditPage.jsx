import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { getRoom, updateRoom } from '../../api/roomApi'
import RoomForm from '../../components/rooms/RoomForm'

export default function RoomEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const roomQuery = useQuery({ queryKey: roomKeys.detail(id), queryFn: () => getRoom(id) })
  const mutation = useMutation({ mutationFn: (payload) => updateRoom(id, payload) })

  const handleSubmit = async (payload) => {
    const room = roomQuery.data.data.data
    await mutation.mutateAsync(payload)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: roomKeys.detail(id) }),
      queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(room.boarding_house_id) }),
    ])
    navigate(`/rooms/${id}`, { replace: true, state: { message: 'Cập nhật phòng thành công.' } })
  }

  if (roomQuery.isPending) return <p>Đang tải thông tin phòng...</p>
  if (roomQuery.isError) return <p className="text-red-600">{getApiErrorMessage(roomQuery.error)}</p>

  const room = roomQuery.data.data.data
  return (
    <section className="max-w-3xl">
      <Link to={`/rooms/${id}`} className="text-sm text-slate-600 hover:text-slate-950">← Quay lại chi tiết phòng</Link>
      <h2 className="mb-6 mt-3 text-2xl font-bold">Sửa phòng {room.room_code}</h2>
      <RoomForm initialValues={room} onSubmit={handleSubmit} submitLabel="Lưu thay đổi" />
    </section>
  )
}
