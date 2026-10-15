import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getContract, getContractContext, updateContract } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractWizard from '../../components/contracts/ContractWizard'
import ContractDraftRoomSelector from '../../components/contracts/ContractDraftRoomSelector'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'

function InternalNote({ contract, save }) {
  const [note, setNote] = useState(contract.note ?? '')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  return <form className="space-y-3 rounded-xl bg-white p-4 shadow-sm" onSubmit={async event => { event.preventDefault(); setPending(true); setError(''); try { await save({ note: note.trim() || null }) } catch (failure) { setError(getApiErrorMessage(failure)) } finally { setPending(false) } }}><p className="text-sm text-slate-500">Hợp đồng đang hiệu lực đã khóa điều khoản. Chỉ có thể sửa ghi chú nội bộ; ghi chú này không thay đổi bản chốt lúc ký.</p><label htmlFor="contract-note" className="block text-sm font-medium">Ghi chú nội bộ</label><textarea id="contract-note" rows={4} value={note} onChange={event => setNote(event.target.value)} className="w-full min-w-0 rounded-lg border p-3" />{error && <p role="alert" className="text-sm text-red-700">{error}</p>}<button disabled={pending} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">Lưu ghi chú</button></form>
}

export default function ContractEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const query = useQuery({ queryKey: contractKeys.detail(id), queryFn: () => getContract(id) })
  const contract = query.data?.data?.data
  const context = useQuery({ queryKey: contractKeys.context(contract?.room_id), queryFn: () => getContractContext(contract.room_id), enabled: contract?.status === 'DRAFT' })
  const update = useMutation({ mutationFn: payload => updateContract(id, payload) })
  if (query.isPending || (contract?.status === 'DRAFT' && context.isPending)) return <p>Đang tải hợp đồng...</p>
  if (query.isError || (contract?.status === 'DRAFT' && context.isError)) return <p className="text-red-700">{getApiErrorMessage(query.error || context.error)}</p>
  const submit = async payload => {
    await update.mutateAsync(payload)
    await Promise.all([client.invalidateQueries({ queryKey: contractKeys.detail(id) }), client.invalidateQueries({ queryKey: contractKeys.byRoom(contract.room_id) }), client.invalidateQueries({ queryKey: roomKeys.detail(contract.room_id) }), client.invalidateQueries({ queryKey: contractKeys.context(contract.room_id) }), client.invalidateQueries({ queryKey: ['room-tenants'] }), client.invalidateQueries({ queryKey: ['residence-context'] })])
    navigate(`/contracts/${id}`, { replace: true })
  }
  return <section className="min-w-0 max-w-5xl space-y-4"><Link to={`/contracts/${id}`} className="inline-flex min-h-11 items-center text-sm underline">← Quay lại hợp đồng</Link><h2 className="text-xl font-bold sm:text-2xl">Sửa hợp đồng</h2>{contract.status === 'DRAFT' ? <><ContractDraftRoomSelector key={`room-${id}-${contract.room_id}`} contract={contract} /><ContractWizard key={`${id}-${contract.room_id}`} context={context.data.data.data} initial={contract} onSubmit={submit} /></> : contract.status === 'ACTIVE' ? <InternalNote key={id} contract={contract} save={submit} /> : <p>Hợp đồng đã kết thúc chỉ được xem.</p>}</section>
}
