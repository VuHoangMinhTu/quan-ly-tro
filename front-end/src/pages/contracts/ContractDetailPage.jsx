import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Trash2 } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { deleteContract, getContract } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractStatusBadge from '../../components/contracts/ContractStatusBadge'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { formatCurrency, formatDate } from '../../utils/formatters'

const Field = ({ label, value }) => <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value || 'Chưa cập nhật'}</dd></div>

export default function ContractDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const client = useQueryClient()
  const [confirm, setConfirm] = useState(false)
  const [error, setError] = useState('')
  const query = useQuery({ queryKey: contractKeys.detail(id), queryFn: () => getContract(id) })
  const mutation = useMutation({
    mutationFn: () => deleteContract(id),
    onSuccess: async () => {
      const contract = query.data.data.data
      await client.invalidateQueries({ queryKey: contractKeys.byRoom(contract.room_id) })
      await client.invalidateQueries({ queryKey: roomKeys.detail(contract.room_id) })
      navigate(`/rooms/${contract.room_id}`, { replace: true, state: { message: 'Xóa hợp đồng thành công.' } })
    },
    onError: (requestError) => setError(getApiErrorMessage(requestError)),
  })

  if (query.isPending) return <p>Đang tải hợp đồng...</p>
  if (query.isError) return <p className="text-red-600">{getApiErrorMessage(query.error)}</p>

  const contract = query.data.data.data

  return <section className="min-w-0 w-full max-w-4xl">
    <div className="mb-6 flex flex-col items-start gap-3 sm:flex-row sm:justify-between"><div className="min-w-0 max-w-full sm:flex-1"><Link to={`/rooms/${contract.room_id}`} className="text-sm text-slate-600 inline-flex min-h-11 items-center lg:min-h-0">← Quay lại phòng</Link><h2 className="mt-3 break-words text-xl font-bold sm:text-2xl">{contract.contract_code}</h2></div><div className="flex flex-wrap items-center gap-3"><Link to={`/contracts/${id}/edit`} className="inline-flex min-h-11 items-center gap-2 rounded-lg border px-4 py-2 text-sm lg:min-h-0"><Pencil size={16} />Sửa</Link>{confirm ? <><button onClick={() => mutation.mutate()} disabled={mutation.isPending} className="min-h-11 sm:min-h-0 rounded-lg bg-red-700 px-4 py-2 text-sm text-white">{mutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setConfirm(false)}>Hủy</button></> : <button onClick={() => setConfirm(true)} className="inline-flex items-center gap-2 min-h-11 sm:min-h-0 rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700"><Trash2 size={16} />Xóa</button>}</div></div>
    {location.state?.message && <p className="mb-4 rounded bg-green-50 p-3 text-sm text-green-700">{location.state.message}</p>}
    {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
    <div className="grid gap-6 md:grid-cols-2"><section className="min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm"><div className="flex items-center justify-between"><h3 className="text-lg font-semibold">Thông tin hợp đồng</h3><ContractStatusBadge status={contract.status} /></div><dl className="mt-5 grid gap-5"><Field label="Ngày ký" value={formatDate(contract.signed_date)} /><Field label="Ngày bắt đầu" value={formatDate(contract.start_date)} /><Field label="Ngày kết thúc" value={formatDate(contract.end_date)} /><Field label="Giá thuê" value={formatCurrency(contract.monthly_rent)} /><Field label="Tiền cọc" value={formatCurrency(contract.deposit_amount)} /><Field label="Ghi chú" value={contract.note} /></dl></section><section className="min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm"><h3 className="text-lg font-semibold">Người đứng tên</h3><dl className="mt-5 grid gap-5"><Field label="Họ tên" value={contract.tenant?.full_name} /><Field label="SĐT" value={contract.tenant?.phone} /><Field label="CCCD" value={contract.tenant?.identity_number} /></dl><Link to={`/tenants/${contract.tenant?.id}`} className="mt-5 inline-flex min-h-11 items-center text-sm underline lg:min-h-0">Xem người thuê</Link></section><section className="min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm md:col-span-2"><h3 className="text-lg font-semibold">Phòng</h3><p className="mt-3">{contract.room?.room_code} {contract.room?.room_name || ''}</p><Link to={`/rooms/${contract.room?.id}`} className="mt-3 inline-flex min-h-11 items-center text-sm underline lg:min-h-0">Xem phòng</Link></section></div>
  </section>
}
