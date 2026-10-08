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
  const submit = async (payload) => {
    await createMutation.mutateAsync(payload)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: contractKeys.byRoom(roomId) }),
      queryClient.invalidateQueries({ queryKey: roomKeys.detail(roomId) }),
    ])
    navigate(`/rooms/${roomId}`, { replace: true, state: { message: 'Thêm hợp đồng thành công.' } })
  }

  return <section className="max-w-3xl"><Link to={`/rooms/${roomId}`} className="text-sm text-slate-600">← Quay lại phòng</Link><h2 className="mt-3 text-2xl font-bold">Thêm hợp đồng</h2><div className="mb-6 mt-3 rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-700">Phòng đang chọn: <span className="font-semibold">{room.room_code}{room.room_name ? ` — ${room.room_name}` : ''}</span></div><ContractForm initialValues={{ monthly_rent: room.monthly_rent }} onSubmit={submit} submitLabel="Thêm hợp đồng" /></section>
}
