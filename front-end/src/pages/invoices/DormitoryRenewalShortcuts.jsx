import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getContractsByRoom } from '../../api/contractApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

// Visible from the KTX invoice-creation page. Each bed/contract can be
// extended only through the auditable contract-renewal flow.
const dateOnly = value => typeof value === 'string' ? value.slice(0, 10) : ''
const dateLabel = value => {
  const date = dateOnly(value)
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date.split('-').reverse().join('/') : 'Chưa có ngày'
}
const todayInVietnam = () => new Intl.DateTimeFormat('sv-SE', {
  timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit',
}).format(new Date())

export default function DormitoryRenewalShortcuts({ roomId }) {
  const query = useQuery({
    queryKey: ['contracts', String(roomId)],
    queryFn: () => getContractsByRoom(roomId),
    staleTime: 15_000,
  })
  if (query.isPending) return <p className="text-sm text-slate-600" role="status">Đang kiểm tra thời hạn hợp đồng giường...</p>
  if (query.isError) return <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
    Không tải được thời hạn hợp đồng giường: {getApiErrorMessage(query.error)}
    {' '}<Link to={`/rooms/${roomId}/contracts`} className="font-semibold underline">Xem danh sách hợp đồng</Link>
  </p>

  const response = query.data?.data?.data
  const records = Array.isArray(response) ? response : []
  const leases = records.filter(contract =>
    contract.contract_type === 'DORM_BED' &&
    ['ACTIVE', 'EXPIRED'].includes(contract.status) &&
    dateOnly(contract.end_date)
  ).sort((a, b) => dateOnly(a.end_date).localeCompare(dateOnly(b.end_date)))
  const today = todayInVietnam()
  // Suggest reviewing all fixed-term bed leases. The period picker lives in
  // DormitoryInvoicePanel, so we cannot claim a particular cycle has expired here.
  const early = leases.filter(contract => dateOnly(contract.end_date) <= today || contract.status === 'EXPIRED').length
  const displayed = leases.slice(0, 12)

  return <section aria-label="Kiểm tra hạn hợp đồng giường" className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm sm:p-4">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h3 className="font-bold text-amber-950">Kiểm tra hạn hợp đồng giường trước khi lập hóa đơn</h3>
      <Link to={`/rooms/${roomId}/contracts`} className="font-semibold text-teal-800 underline">Tất cả hợp đồng</Link>
    </div>
    <p className="text-amber-950">Nếu kỳ thu bạn chọn vượt ngày kết thúc hợp đồng, hệ thống sẽ không tạo hóa đơn mới. Chủ trọ cần gia hạn hợp lệ hoặc bỏ qua kỳ thu; không nhập tiền thủ công để vượt chặn.</p>
    {leases.length === 0
      ? <p className="text-amber-900">Không có hợp đồng giường đang hoạt động hoặc ở trạng thái hết hạn với ngày kết thúc xác định.</p>
      : <details open={early > 0 || leases.length <= 3} className="rounded-lg border border-amber-200 bg-white p-3">
        <summary className="cursor-pointer font-semibold text-slate-900">Hợp đồng có thời hạn ({leases.length}){early > 0 ? ` · ${early} hợp đồng đã đến hạn` : ''}</summary>
        <ul className="mt-3 space-y-2">
          {displayed.map(contract => {
            const ended = dateOnly(contract.end_date) <= today || contract.status === 'EXPIRED'
            const name = contract.tenant?.full_name || `Người thuê #${contract.tenant_id}`
            return <li key={contract.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
              <div className="min-w-0">
                <p className="font-semibold text-slate-900">{contract.bed?.bed_code || 'Giường'} · {name}</p>
                <p className="text-slate-700">{contract.contract_code || `Hợp đồng #${contract.id}`} · Kết thúc {dateLabel(contract.end_date)}</p>
                <p className={ended ? 'font-semibold text-red-700' : 'text-slate-600'}>
                  {ended ? 'Đã đến ngày kết thúc theo hợp đồng' : 'Cần gia hạn nếu lập hóa đơn cho kỳ vượt thời hạn này'}
                </p>
              </div>
              <Link
                to={`/contracts/${contract.id}#contract-renewal`}
                state={{ returnTo: `/rooms/${roomId}/invoices/new` }}
                className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white"
              >Gia hạn / xem hợp đồng</Link>
            </li>
          })}
        </ul>
        {leases.length > displayed.length && <p className="mt-3 text-slate-700">Còn {leases.length - displayed.length} hợp đồng. <Link to={`/rooms/${roomId}/contracts`} className="font-semibold underline">Xem danh sách đầy đủ</Link>.</p>}
      </details>}
    <p className="text-xs text-amber-900">Ngày kết thúc được lấy từ hợp đồng, không phải từ tháng đang chọn trong bảng tính hóa đơn bên dưới. Chỉ chủ trọ có quyền ghi nhận gia hạn.</p>
  </section>
}
