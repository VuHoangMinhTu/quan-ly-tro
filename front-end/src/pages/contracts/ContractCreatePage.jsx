// BEEHOUSE_NAV_CONTEXT_V1
import { backTarget } from '../../utils/navigationContext'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useLocation } from 'react-router-dom'
import { createContract, getContractContext } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractWizard from '../../components/contracts/ContractWizard'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'

export default function ContractCreatePage() {
  const { roomId } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const client = useQueryClient()
  const context = useQuery({ queryKey: contractKeys.context(roomId), queryFn: () => getContractContext(roomId) })
  const create = useMutation({ mutationFn: payload => createContract(roomId, payload) })
  if (context.isPending) return <p>Đang tải thông tin hợp đồng...</p>
  if (context.isError) return <p className="text-red-700">{getApiErrorMessage(context.error)}</p>
  const submit = async payload => {
    const response = await create.mutateAsync(payload)
    await Promise.all([client.invalidateQueries({ queryKey: contractKeys.byRoom(roomId) }), client.invalidateQueries({ queryKey: roomKeys.detail(roomId) }), client.invalidateQueries({ queryKey: contractKeys.context(roomId) }), client.invalidateQueries({ queryKey: ['room-tenants'] }), client.invalidateQueries({ queryKey: ['residence-context'] })])
    navigate(`/contracts/${response.data.data.id}#contract-handover`, { replace: true, state: { returnTo: backTarget(location, `/rooms/${roomId}/contracts`), returnLabel: 'Hợp đồng', message: 'Đã lưu nháp. Vui lòng xác nhận biên bản bàn giao trước khi chốt hợp đồng.' } })
  }
  return <section className="min-w-0 max-w-5xl space-y-4"><Link to={backTarget(location, `/rooms/${roomId}/contracts`)} className="inline-flex min-h-11 items-center text-sm underline">← Quay lại hợp đồng của phòng</Link><h2 className="text-xl font-bold sm:text-2xl">Tạo hợp đồng mới</h2><ContractWizard key={roomId} context={context.data.data.data} onSubmit={submit} allowActivation={false} /></section>
}
