import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { createRoom } from '../../api/roomApi'
import RoomForm from '../../components/rooms/RoomForm'

export default function RoomCreatePage() {
  const { boardingHouseId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const boardingHouseQuery = useQuery({ queryKey: boardingHouseKeys.detail(boardingHouseId), queryFn: () => getBoardingHouse(boardingHouseId) })
  const mutation = useMutation({ mutationFn: (payload) => createRoom(boardingHouseId, payload) })

  const handleSubmit = async (payload) => {
    const response = await mutation.mutateAsync(payload)
    const room = response.data.data
    queryClient.setQueryData(roomKeys.detail(room.id), response)
    await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(boardingHouseId) })
    navigate(`/rooms/${room.id}`, { replace: true, state: { message: 'Tạo phòng thành công.' } })
  }

  if (boardingHouseQuery.isPending) return <p>Đang tải nhà trọ...</p>
  if (boardingHouseQuery.isError) return <p className="text-red-600">{getApiErrorMessage(boardingHouseQuery.error)}</p>

  return (
    <section className="max-w-3xl">
      <Link to={`/boarding-houses/${boardingHouseId}/rooms`} className="text-sm text-slate-600 hover:text-slate-950">← Quay lại danh sách phòng</Link>
      <h2 className="mb-1 mt-3 text-2xl font-bold">Thêm phòng</h2>
      <p className="mb-6 text-sm text-slate-600">{boardingHouseQuery.data.data.data.name}</p>
      <RoomForm onSubmit={handleSubmit} submitLabel="Tạo phòng" />
    </section>
  )
}
