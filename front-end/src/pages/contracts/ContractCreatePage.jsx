import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { createContract } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractForm from '../../components/forms/ContractForm'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { getRoom } from '../../api/roomApi'
import { roomKeys } from '../../api/roomKeys'

export default function ContractCreatePage() {
  const { roomId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const roomQuery = useQuery({ queryKey: roomKeys.detail(roomId), queryFn: () => getRoom(roomId) })
  const createMutation = useMutation({ mutationFn: (payload) => createContract(roomId, payload) })

  if (roomQuery.isPending) return <p>Đang tải phòng...</p>
  if (roomQuery.isError) return <p className="text-red-600">{getApiErrorMessage(roomQuery.error)}</p>

  const room = roomQuery.data.data.data
  if (room.monthly_rent == null) return <section className="min-w-0 w-full max-w-3xl"><Link to={"/rooms/" + roomId} className="text-sm text-slate-600 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại phòng</Link><h2 className="mt-3 break-words text-xl font-bold sm:text-2xl">Thêm hợp đồng</h2><p className="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Phòng chưa được thiết lập giá thuê. Vui lòng cập nhật giá thuê trước.</p><Link to={"/rooms/" + roomId + "/edit"} className="mt-4 inline-block min-h-11 sm:min-h-0 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Cập nhật giá thuê</Link></section>
  const submit = async (payload) => {
    await createMutation.mutateAsync(payload)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: contractKeys.byRoom(roomId) }),
      queryClient.invalidateQueries({ queryKey: roomKeys.detail(roomId) }),
    ])
    navigate(`/rooms/${roomId}`, { replace: true, state: { message: 'Thêm hợp đồng thành công.' } })
  }

  return <section className="min-w-0 w-full max-w-3xl"><Link to={`/rooms/${roomId}`} className="text-sm text-slate-600 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại phòng</Link><h2 className="mt-3 break-words text-xl font-bold sm:text-2xl">Thêm hợp đồng</h2><div className="mb-6 mt-3 rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-700">Phòng đang chọn: <span className="font-semibold">{room.room_code}{room.room_name ? ` — ${room.room_name}` : ''}</span></div><ContractForm initialValues={{ monthly_rent: room.monthly_rent }} onSubmit={submit} submitLabel="Thêm hợp đồng" /></section>
}
