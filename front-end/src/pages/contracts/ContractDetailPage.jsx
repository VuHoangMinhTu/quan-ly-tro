import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { deleteContract, getContract, moveOutContractOccupant, updateContractStatus } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractStatusBadge from '../../components/contracts/ContractStatusBadge'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency, formatDate, formatUtilityValue, genderLabels } from '../../utils/formatters'
import { formatDateForApi, getTodayForDisplay, isValidDisplayDate } from '../../utils/date'
import ContractServicesSection from '../../components/contracts/ContractServicesSection'
import ContractDocumentSection from '../../components/contracts/ContractDocumentSection'
import ContractSigningSection from '../../components/contracts/ContractSigningSection'
import { residenceStatusLabels } from '../../utils/residence'
import DateInput from '../../components/ui/DateInput'

const Field = ({ label, value }) => <div className="min-w-0"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value ?? 'Chưa cập nhật'}</dd></div>
const Card = ({ title, children }) => <section className="min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6"><h3 className="text-lg font-semibold">{title}</h3>{children}</section>

export default function ContractDetailPage() {
  const { id } = useParams()
  const client = useQueryClient()
  const navigate = useNavigate()
  const [error, setError] = useState('')
  const [action, setAction] = useState(null)
  const [date, setDate] = useState(getTodayForDisplay)
  const query = useQuery({ queryKey: contractKeys.detail(id), queryFn: () => getContract(id) })
  const mutate = useMutation({ mutationFn: async request => {
    if (request.type === 'delete') return deleteContract(id)
    if (request.type === 'move-out') return moveOutContractOccupant(id, request.occupantId, { move_out_date: formatDateForApi(date) })
    return updateContractStatus(id, { status: request.status, ...(request.status !== 'ACTIVE' ? { move_out_date: formatDateForApi(date) } : {}) })
  }, onSuccess: async (_, request) => {
    setAction(null); setError('')
    await Promise.all([client.invalidateQueries({ queryKey: ['contract'] }), client.invalidateQueries({ queryKey: ['contracts'] }), client.invalidateQueries({ queryKey: ['contract-context'] }), client.invalidateQueries({ queryKey: ['room-tenants'] }), client.invalidateQueries({ queryKey: ['residence-context'] })])
    if (request.type === 'delete') navigate(`/rooms/${query.data.data.data.room_id}/contracts`, { replace: true })
  }, onError: failure => setError(getApiErrorMessage(failure)) })
  if (query.isPending) return <p>Đang tải hợp đồng...</p>
  if (query.isError) return <p className="text-red-700">{getApiErrorMessage(query.error)}</p>
  const contract = query.data.data.data
  const snapshot = contract.signing_snapshot
  const tenant = snapshot?.tenant ?? contract.tenant ?? {}
  const lessor = snapshot?.lessor
  const room = snapshot?.room
  const core = snapshot?.contract ?? contract
  const openings = snapshot?.utility_openings ?? contract.utility_openings ?? []
  const occupants = contract.occupants ?? []
  const declarations = contract.residence_declarations ?? []
  const draft = contract.status === 'DRAFT'
  const active = contract.status === 'ACTIVE'
  const confirmAction = event => {
    event.preventDefault()
    if ((action.type === 'move-out' || (action.type === 'status' && action.status !== 'ACTIVE')) && !isValidDisplayDate(date)) { setError('Vui lòng nhập ngày chuyển đi hợp lệ theo dd/mm/yyyy.'); return }
    mutate.mutate(action)
  }
  return <section className="min-w-0 max-w-5xl space-y-5">
    <header className="flex flex-col items-start justify-between gap-3 sm:flex-row"><div className="min-w-0"><Link to={`/rooms/${contract.room_id}/contracts`} className="inline-flex min-h-11 items-center text-sm underline">← Danh sách hợp đồng của phòng</Link><h2 className="break-words text-xl font-bold sm:text-2xl">{contract.contract_code}</h2><div className="mt-2"><ContractStatusBadge status={contract.status} /></div></div><div className="flex flex-wrap gap-2">{(draft || active) && <Link to={`/contracts/${id}/edit`} className="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm">{draft ? 'Sửa' : 'Sửa ghi chú nội bộ'}</Link>}{draft && <><button type="button" onClick={() => setAction({ type: 'status', status: 'ACTIVE' })} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white">Kích hoạt</button><button type="button" onClick={() => setAction({ type: 'delete' })} className="min-h-11 rounded-lg border border-red-200 px-4 text-sm text-red-700">Xóa nháp</button></>}{active && <><button type="button" onClick={() => setAction({ type: 'status', status: 'TERMINATED' })} className="min-h-11 rounded-lg border px-4 text-sm">Chấm dứt</button><button type="button" onClick={() => setAction({ type: 'status', status: 'EXPIRED' })} className="min-h-11 rounded-lg border px-4 text-sm">Đánh dấu hết hạn</button></>}</div></header>
    {contract.is_legacy && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Dữ liệu hợp đồng cũ chưa có bản snapshot. Thông tin người thuê và phòng bên dưới là dữ liệu hiện tại, không phải bản chốt lúc ký.</p>}
    {snapshot && <p className="rounded-lg bg-green-50 p-3 text-sm text-green-800">Thông tin hai bên, giá thuê, dịch vụ và chỉ số đầu hiển thị từ bản chốt khi kích hoạt. Không tự thay đổi theo dữ liệu danh mục hiện tại.</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <div className="grid min-w-0 gap-5 md:grid-cols-2"><Card title="Tổng quan"><dl className="grid gap-4 sm:grid-cols-2"><Field label="Nhà trọ" value={room?.boarding_house_name ?? contract.room?.boarding_house?.name} /><Field label="Phòng" value={room?.room_code ?? contract.room?.room_code} /><Field label="Ngày ký" value={formatDate(core.signed_date)} /><Field label="Ngày bắt đầu" value={formatDate(core.start_date)} /><Field label="Ngày kết thúc" value={formatDate(core.end_date)} /><Field label="Ghi chú nội bộ" value={contract.note || 'Không có ghi chú.'} /></dl></Card>
    <Card title="Bên A — đại diện bên cho thuê">{lessor ? <dl className="grid gap-4"><Field label="Họ tên" value={lessor.full_name} /><Field label="Chức vụ lúc ký" value={lessor.title} /><Field label="Điện thoại" value={lessor.phone} /></dl> : <p className="text-sm text-slate-500">{draft ? 'Đại diện được chọn trong bước xác nhận và chốt khi kích hoạt.' : 'Chưa có snapshot đại diện. Không tự lấy OWNER làm người đã ký.'}</p>}</Card>
    <Card title="Bên B — người đứng tên"><dl className="grid gap-4 sm:grid-cols-2">{[['Họ tên', tenant.full_name], ['Số điện thoại', tenant.phone], ['Email', tenant.email], ['Số định danh cá nhân', tenant.identity_number], ['Ngày sinh', formatDate(tenant.date_of_birth)], ['Giới tính', genderLabels[tenant.gender]], ['Ngày cấp', formatDate(tenant.identity_issue_date)], ['Nơi cấp', tenant.identity_issue_place], ['Địa chỉ thường trú', tenant.permanent_address]].map(([label, value]) => <Field key={label} label={label} value={value} />)}</dl></Card>
    <Card title="Tài chính & lịch thanh toán"><dl className="grid gap-4 sm:grid-cols-2"><Field label="Giá thuê thỏa thuận" value={core.monthly_rent == null ? 'Chưa thiết lập' : formatCurrency(core.monthly_rent)} /><Field label="Tiền cọc theo thỏa thuận" value={formatCurrency(core.deposit_amount)} /><Field label="Ngày thanh toán hàng tháng" value={core.payment_due_day ? `Ngày ${String(core.payment_due_day).padStart(2, '0')} hàng tháng` : 'Chưa thiết lập'} /></dl><p className="text-sm text-slate-500">Nếu tháng không có ngày thanh toán đã chọn, dùng ngày cuối cùng của tháng.</p><p className="text-sm text-slate-500">Tiền cọc trên hợp đồng không có nghĩa đã thu. Giao dịch cọc và hóa đơn ban đầu chưa được tự tạo trong lượt này.</p>{contract.operational_role === 'OWNER' && <Link to={`/rooms/${contract.room_id}/invoices`} className="inline-flex min-h-11 items-center text-sm underline">Xem hóa đơn của phòng</Link>}</Card></div>
    <Card title="Người ở & lịch sử"><p className="text-sm">Người đang đăng ký ở: <strong>{contract.active_occupants_count ?? occupants.filter(person => person.status === 'ACTIVE').length}</strong> / Tối đa của phòng hiện tại: <strong>{contract.room?.max_tenants ?? 'Chưa thiết lập'}</strong></p>{occupants.length ? <div className="grid gap-3 sm:grid-cols-2">{occupants.map(person => <article key={person.id} className="min-w-0 rounded-lg border p-3 text-sm"><strong className="break-words">{snapshot?.occupants?.find(saved => Number(saved.tenant_id) === Number(person.tenant_id))?.full_name ?? person.tenant?.full_name ?? 'Người thuê không còn trong danh mục'}</strong><p>{person.is_primary ? 'Người đứng tên' : 'Người ở cùng'} · {person.status === 'ACTIVE' ? 'Đang ở' : 'Đã chuyển đi'}</p><p>{formatDate(person.move_in_date)} → {person.move_out_date ? formatDate(person.move_out_date) : 'Chưa chuyển đi'}</p>{active && person.status === 'ACTIVE' && <button type="button" onClick={() => { setDate(getTodayForDisplay()); setAction({ type: 'move-out', occupantId: person.id }) }} className="mt-2 min-h-11 underline">Ghi nhận chuyển đi</button>}</article>)}</div> : <p className="text-sm text-slate-500">{draft ? 'Người ở sẽ được ghi nhận khi kích hoạt.' : 'Hợp đồng cũ chưa liên kết lịch sử người ở. Không tự gán ngược dữ liệu.'}</p>}</Card>
    <ContractDocumentSection contract={contract} />
    <ContractSigningSection contract={contract} />
    <ContractServicesSection key={id} contract={contract} />
    <Card title="Chỉ số đầu hợp đồng"><div className="grid gap-3 sm:grid-cols-2">{openings.map(opening => <article key={opening.id ?? opening.utility_meter_id} className="rounded-lg border p-3 text-sm"><strong>{opening.service_name}</strong><p>{formatUtilityValue(opening.opening_reading)} {opening.unit}</p><p>Ngày ghi: {formatDate(opening.reading_date)}</p></article>)}</div>{openings.length === 0 && <p className="text-sm text-slate-500">Chưa có chỉ số đầu được ghi nhận cho hợp đồng này.</p>}</Card>
    <Card title="Khai báo cư trú">{declarations.length ? <ul className="space-y-2">{declarations.map(record => <li key={record.id}><Link to={`/residence-declarations/${record.id}`} className="inline-flex min-h-11 items-center text-sm underline">Hồ sơ #{record.id} · {residenceStatusLabels[record.status]}</Link></li>)}</ul> : <p className="text-sm text-slate-500">Tạm trú: Chưa khai báo.</p>}{active && <Link to={`/residence-declarations/new?room_id=${contract.room_id}&contract_id=${id}`} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Tạo hồ sơ tạm trú</Link>}</Card>
    {action && <div role="dialog" aria-modal="true" aria-label="Xác nhận thao tác hợp đồng" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3"><form onSubmit={confirmAction} className="max-h-[90dvh] w-full max-w-md space-y-4 overflow-y-auto rounded-xl bg-white p-4 sm:p-6"><h3 className="font-semibold">Xác nhận thao tác</h3><p className="text-sm">{action.type === 'delete' ? 'Xóa hợp đồng nháp này?' : action.type === 'move-out' ? 'Ghi nhận chuyển đi và giữ lịch sử người ở.' : action.status === 'ACTIVE' ? 'Kích hoạt sẽ chốt dữ liệu thỏa thuận và đồng bộ người ở. Không tự tạo hóa đơn hoặc thu tiền cọc.' : 'Kết thúc hợp đồng và ghi nhận ngày chuyển đi cho những người còn đang ở theo hợp đồng này.'}</p>{(action.type === 'move-out' || (action.type === 'status' && action.status !== 'ACTIVE')) && <label className="block text-sm">Ngày chuyển đi<DateInput value={date} onChange={setDate} /></label>}<div className="flex flex-wrap gap-3"><button disabled={mutate.isPending} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">Xác nhận</button><button type="button" disabled={mutate.isPending} onClick={() => setAction(null)} className="min-h-11 px-4 text-sm">Hủy</button></div>{error && <p role="alert" className="text-sm text-red-700">{error}</p>}</form></div>}
  </section>
}
