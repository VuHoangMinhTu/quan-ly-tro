import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { createRoomsBulk } from '../../api/roomApi'
import BulkRoomForm from '../../components/boarding-houses/BulkRoomForm'

export default function RoomCreatePage() {
  const { boardingHouseId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const boardingHouseQuery = useQuery({ queryKey: boardingHouseKeys.detail(boardingHouseId), queryFn: () => getBoardingHouse(boardingHouseId) })
  const mutation = useMutation({ mutationFn: (rooms) => createRoomsBulk(boardingHouseId, rooms) })

  const handleSubmit = async (rooms) => {
    const response = await mutation.mutateAsync(rooms)
    await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(boardingHouseId) })
    navigate(`/boarding-houses/${boardingHouseId}/rooms`, { replace: true, state: { message: response.data.message } })
  }

  if (boardingHouseQuery.isPending) return <p>Đang tải nhà trọ...</p>
  if (boardingHouseQuery.isError) return <p className="text-red-600">{getApiErrorMessage(boardingHouseQuery.error)}</p>

  return (
    <section className="min-w-0 w-full max-w-5xl">
      <Link to={`/boarding-houses/${boardingHouseId}/rooms`} className="text-sm text-slate-600 hover:text-slate-950 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại danh sách phòng</Link>
      <h2 className="mb-1 mt-3 break-words text-xl font-bold sm:text-2xl">Thêm phòng hàng loạt</h2>
      <p className="mb-6 break-words text-sm text-slate-600">{boardingHouseQuery.data.data.data.name}</p>
      <BulkRoomForm initialRooms={[]} onSubmit={handleSubmit} submitLabel={(count) => `Thêm ${count} phòng`} />
    </section>
  )
}
