import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { contractKeys } from '../../api/contractKeys'
import { getContractsByRoom, getContractContext } from '../../api/contractApi'
import ContractStatusBadge from '../../components/contracts/ContractStatusBadge'
import { formatCurrency, formatDate } from '../../utils/formatters'

export default function ContractListPage() {
  const { roomId } = useParams()
  const context = useQuery({ queryKey: contractKeys.context(roomId), queryFn: () => getContractContext(roomId) })
  const query = useQuery({ queryKey: contractKeys.byRoom(roomId), queryFn: () => getContractsByRoom(roomId) })
  if (context.isPending || query.isPending) return <p>Đang tải hợp đồng...</p>
  if (context.isError || query.isError) return <p className="text-red-700">{getApiErrorMessage(context.error || query.error)}</p>
  const contracts = query.data.data.data ?? []
  const room = context.data.data.data.room
  return <section className="min-w-0 space-y-5"><Link to="/contracts" className="inline-flex min-h-11 items-center text-sm underline">← Chọn nhà trọ / phòng</Link><div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-xl font-bold sm:text-2xl">Hợp đồng · Phòng {room.room_code}</h2><p className="break-words text-sm text-slate-500">{room.boarding_house.name}</p></div><Link to={`/rooms/${roomId}/contracts/new`} className="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Tạo hợp đồng mới</Link></div>{contracts.length === 0 ? <p className="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500">Chưa có hợp đồng nào cho phòng này.</p> : <div className="grid gap-4 md:grid-cols-2">{contracts.map(contract => <article key={contract.id} className="min-w-0 space-y-3 rounded-xl bg-white p-4 shadow-sm"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="break-words font-semibold">{contract.contract_code}</h3><ContractStatusBadge status={contract.status} /></div><p className="break-words text-sm">{contract.tenant?.full_name}</p><p className="text-sm">{contract.contract_type === 'DORM_BED' ? `Giường ${contract.bed?.bed_code ?? 'Chưa chọn'}` : 'Thuê cả phòng'}</p><p className="text-sm">{formatDate(contract.start_date)} → {formatDate(contract.end_date)}</p><p className="text-sm">Giá thuê: {contract.monthly_rent == null ? 'Chưa thiết lập' : formatCurrency(contract.monthly_rent)}</p><Link to={`/contracts/${contract.id}`} className="inline-flex min-h-11 items-center text-sm underline">Xem hợp đồng</Link></article>)}</div>}</section>
}
