import { useQuery } from '@tanstack/react-query'
import { getContract } from '../../api/contractApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import ContractExitSection from '../contracts/ContractExitSection'

export default function RoomCheckoutCard({ room, contract }) {
  const query = useQuery({ queryKey: ['room-checkout-contract', contract?.id], queryFn: () => getContract(contract.id), enabled: Boolean(contract?.id) })
  return <section aria-label="Trả phòng" className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    {!contract ? <><h3 className="text-lg font-semibold">Trả phòng</h3><p className="mt-2 text-sm text-slate-600">Phòng không có hợp đồng đang thuê. Lịch sử hợp đồng vẫn được giữ.</p></>
    : query.isPending ? <p role="status">Đang tải thông tin trả phòng…</p>
    : query.isError ? <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p>
    : query.data?.data?.data?.operational_role !== 'OWNER' ? <><h3 className="text-lg font-semibold">Trả phòng</h3><p className="mt-2 text-sm text-slate-600">Chỉ chủ trọ có thể xác nhận trả phòng và xử lý tài chính.</p></>
    : <ContractExitSection contract={query.data.data.data} view="overview" />}
  </section>
}
