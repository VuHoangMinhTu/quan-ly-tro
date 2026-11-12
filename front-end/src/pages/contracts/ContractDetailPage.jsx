// BEEHOUSE_NAV_CONTEXT_V1
import { backTarget, safeReturnTo, forwardContext } from '../../utils/navigationContext'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { deleteContract, getContract, getHandoverCheckIn, moveOutContractOccupant, updateContractStatus } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractStatusBadge from '../../components/contracts/ContractStatusBadge'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency, formatDate, formatUtilityValue, genderLabels } from '../../utils/formatters'
import { formatDateForApi, getTodayForDisplay, isValidDisplayDate } from '../../utils/date'
import ContractServicesSection from '../../components/contracts/ContractServicesSection'
import ContractDocumentSection from '../../components/contracts/ContractDocumentSection'
import ContractSigningSection from '../../components/contracts/ContractSigningSection'
import ContractHandoverSection from '../../components/contracts/ContractHandoverSection'
import { residenceStatusLabels } from '../../utils/residence'
import DateInput from '../../components/ui/DateInput'

const Field = ({ label, value }) => <div className="min-w-0"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 break-words font-medium">{value ?? 'Chưa cập nhật'}</dd></div>
const Card = ({ title, children }) => <section className="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6"><h3 className="text-lg font-semibold">{title}</h3>{children}</section>

export default function ContractDetailPage() {
  const { id } = useParams()
  const client = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState('')
  const [action, setAction] = useState(null)
  const [date, setDate] = useState(getTodayForDisplay)
  const query = useQuery({ queryKey: contractKeys.detail(id), queryFn: () => getContract(id) })
  const handover = useQuery({ queryKey: contractKeys.handoverCheckIn(id), queryFn: () => getHandoverCheckIn(id), enabled: query.data?.data?.data?.status === 'DRAFT' })
  const mutate = useMutation({ mutationFn: async request => {
    if (request.type === 'delete') return deleteContract(id)
    if (request.type === 'move-out') return moveOutContractOccupant(id, request.occupantId, { move_out_date: formatDateForApi(date) })
    return updateContractStatus(id, { status: request.status, ...(request.status !== 'ACTIVE' && request.moveOut ? { move_out_date: formatDateForApi(date) } : {}) })
  }, onSuccess: async (_, request) => {
    setAction(null); setError('')
    await Promise.all([client.invalidateQueries({ queryKey: ['contract'] }), client.invalidateQueries({ queryKey: ['contracts'] }), client.invalidateQueries({ queryKey: ['contract-context'] }), client.invalidateQueries({ queryKey: ['room-tenants'] }), client.invalidateQueries({ queryKey: ['residence-context'] })])
    if (request.type === 'delete') {
      const roomId = query.data.data.data.room_id
      const fromList = location.state?.returnTo === `/rooms/${roomId}/contracts`
      navigate(backTarget(location, `/rooms/${roomId}`), {
        replace: true,
        state: fromList ? {
          returnTo: location.state?.listReturnTo === '/contracts'
            ? '/contracts' : `/rooms/${roomId}`
        } : undefined,
      })
    }
  }, onError: failure => setError(getApiErrorMessage(failure)) })
  if (query.isPending) return <p>Đang tải hợp đồng...</p>
  if (query.isError) return <p className="text-red-700">{getApiErrorMessage(query.error)}</p>
  const contract = query.data.data.data
  const contractListPath = `/rooms/${contract.room_id}/contracts`
  const backTo = backTarget(location, `/rooms/${contract.room_id}`)
  const isFromList = backTo.startsWith(contractListPath)
  const backState = isFromList ? { returnTo: safeReturnTo(location.state?.parentReturnTo || location.state?.listReturnTo, `/rooms/${contract.room_id}`) } : undefined
  const snapshot = contract.signing_snapshot
  const tenant = snapshot?.tenant ?? contract.tenant ?? {}
  const lessor = snapshot?.lessor
  const room = snapshot?.room
  const core = snapshot?.contract ?? contract
  const openings = snapshot?.utility_openings ?? contract.utility_openings ?? []
  const occupants = contract.occupants ?? []
  const declarations = contract.residence_declarations ?? []
  const draft = contract.status === 'DRAFT'
  const handoverConfirmed = handover.data?.data?.data?.status === 'CONFIRMED'
  const active = contract.status === 'ACTIVE'
  const confirmAction = event => {
    event.preventDefault()
    if ((action.type === 'move-out' || (action.type === 'status' && action.status !== 'ACTIVE' && action.moveOut)) && !isValidDisplayDate(date)) { setError('Vui lòng nhập ngày chuyển đi hợp lệ theo dd/mm/yyyy.'); return }
    mutate.mutate(action)
  }
  return <section className="min-w-0 max-w-5xl space-y-5">
    <header className="flex min-w-0 flex-col items-start justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:p-5"><div className="min-w-0"><Link to={backTo} state={backState} className="inline-flex min-h-11 items-center text-sm font-medium text-teal-800 hover:underline">← {isFromList ? 'Danh sách hợp đồng' : 'Quay lại'}</Link><h2 className="mt-2 break-words text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{contract.contract_code}</h2><div className="mt-2"><ContractStatusBadge status={contract.status} />{contract.is_demo && <p className="mt-2 text-sm text-amber-800">DEMO — chỉ lưu nháp và in/PDF, không phải hợp đồng đầy đủ; không thể kích hoạt.</p>}</div></div><div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap">{(draft || active) && <Link to={`/contracts/${id}/edit`} state={forwardContext(location, `/rooms/${contract.room_id}`)} className="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm">{draft ? 'Sửa' : 'Sửa ghi chú nội bộ'}</Link>}{draft && <><button type="button" disabled={!handoverConfirmed || contract.is_demo} onClick={() => setAction({ type: 'status', status: 'ACTIVE' })} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:cursor-not-allowed disabled:opacity-50">Kích hoạt</button><button type="button" onClick={() => setAction({ type: 'delete' })} className="min-h-11 rounded-lg border border-red-200 px-4 text-sm text-red-700">Xóa nháp</button></>}{active && <><button type="button" onClick={() => setAction({ type: 'status', status: 'TERMINATED' })} className="min-h-11 rounded-lg border px-4 text-sm">Chấm dứt</button><button type="button" onClick={() => setAction({ type: 'status', status: 'EXPIRED' })} className="min-h-11 rounded-lg border px-4 text-sm">Đánh dấu hết hạn</button></>}</div></header>
    {draft && <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-800">Đang lập hợp đồng: cần <Link to={`/contracts/${id}/edit`} state={forwardContext(location, `/rooms/${contract.room_id}`)} className="font-semibold underline">ghi chỉ số đầu và ngày bàn giao ở bước 2 khi sửa hợp đồng</Link> trước khi kích hoạt. Sau kích hoạt mới xác nhận CHECK_IN V2. Không cần chỉ số cuối kỳ.</p>}
    {draft && !handoverConfirmed && <p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Hãy <a href="#contract-handover" className="font-medium underline">xác nhận biên bản bàn giao</a> trước khi kích hoạt hoặc chốt hợp đồng.</p>}
    {active && <p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Đã kích hoạt hợp đồng. <a className="font-semibold underline" href="#contract-meter-checkin">Kiểm tra chỉ số bàn giao điện nước</a> trước khi lập hóa đơn. Nếu đã xác nhận đủ, các tháng sau không cần CHECK_IN lại.</p>}
    {contract.is_legacy && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Dữ liệu hợp đồng cũ chưa có bản snapshot. Thông tin người thuê và phòng bên dưới là dữ liệu hiện tại, không phải bản chốt lúc ký.</p>}
    {snapshot && <p className="rounded-lg bg-green-50 p-3 text-sm text-green-800">Thông tin hai bên, giá thuê, dịch vụ và chỉ số đầu hiển thị từ bản chốt khi kích hoạt. Không tự thay đổi theo dữ liệu danh mục hiện tại.</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <div className="grid min-w-0 gap-5 md:grid-cols-2"><Card title="Tổng quan"><dl className="grid gap-4 sm:grid-cols-2"><Field label="Nhà trọ" value={room?.boarding_house_name ?? contract.room?.boarding_house?.name} /><Field label="Phòng" value={room?.room_code ?? contract.room?.room_code} /><Field label="Ngày ký" value={formatDate(core.signed_date)} /><Field label="Ngày bắt đầu" value={formatDate(core.start_date)} /><Field label="Ngày kết thúc" value={formatDate(core.end_date)} /><Field label="Ghi chú nội bộ" value={contract.note || 'Không có ghi chú.'} /></dl></Card>
    <Card title="Bên A — đại diện bên cho thuê">{lessor ? <dl className="grid gap-4"><Field label="Họ tên" value={lessor.full_name} /><Field label="Chức vụ lúc ký" value={lessor.title} /><Field label="Điện thoại" value={lessor.phone} /></dl> : <p className="text-sm text-slate-500">{draft ? 'Đại diện được chọn trong bước xác nhận và chốt khi kích hoạt.' : 'Chưa có snapshot đại diện. Không tự lấy OWNER làm người đã ký.'}</p>}</Card>
    <Card title="Bên B — người đứng tên"><dl className="grid gap-4 sm:grid-cols-2">{[['Họ tên', tenant.full_name], ['Số điện thoại', tenant.phone], ['Email', tenant.email], ['Số định danh cá nhân', tenant.identity_number], ['Ngày sinh', formatDate(tenant.date_of_birth)], ['Giới tính', genderLabels[tenant.gender]], ['Ngày cấp', formatDate(tenant.identity_issue_date)], ['Nơi cấp', tenant.identity_issue_place], ['Địa chỉ thường trú', tenant.permanent_address]].map(([label, value]) => <Field key={label} label={label} value={value} />)}</dl></Card>
    <Card title="Tài chính & lịch thanh toán"><dl className="grid gap-4 sm:grid-cols-2"><Field label="Giá thuê thỏa thuận" value={core.monthly_rent == null ? 'Chưa thiết lập' : formatCurrency(core.monthly_rent)} /><Field label="Tiền cọc theo thỏa thuận" value={formatCurrency(core.deposit_amount)} /><Field label="Ngày thanh toán hàng tháng" value={core.payment_due_day ? `Ngày ${String(core.payment_due_day).padStart(2, '0')} hàng tháng` : 'Chưa thiết lập'} /></dl><p className="text-sm text-slate-500">Nếu tháng không có ngày thanh toán đã chọn, dùng ngày cuối cùng của tháng.</p><p className="text-sm text-slate-500">Tiền cọc trên hợp đồng không có nghĩa đã thu. Giao dịch cọc và hóa đơn ban đầu chưa được tự tạo trong lượt này.</p>{contract.operational_role === 'OWNER' && <Link to={`/rooms/${contract.room_id}/invoices`} className="inline-flex min-h-11 items-center text-sm underline">Xem hóa đơn của phòng</Link>}</Card></div>
    <Card title="Người ở & lịch sử"><p className="text-sm">Người đang đăng ký ở: <strong>{contract.active_occupants_count ?? occupants.filter(person => person.status === 'ACTIVE').length}</strong> / Tối đa của phòng hiện tại: <strong>{contract.room?.max_tenants ?? 'Chưa thiết lập'}</strong></p>{occupants.length ? <div className="grid gap-3 sm:grid-cols-2">{occupants.map(person => <article key={person.id} className="min-w-0 rounded-lg border p-3 text-sm"><strong className="break-words">{snapshot?.occupants?.find(saved => Number(saved.tenant_id) === Number(person.tenant_id))?.full_name ?? person.tenant?.full_name ?? 'Người thuê không còn trong danh mục'}</strong><p>{person.is_primary ? 'Người đứng tên' : 'Người ở cùng'} · {person.status === 'ACTIVE' ? 'Đang ở' : 'Đã chuyển đi'}</p><p>{formatDate(person.move_in_date)} → {person.move_out_date ? formatDate(person.move_out_date) : 'Chưa chuyển đi'}</p>{person.status === 'ACTIVE' && <button type="button" onClick={() => { setDate(getTodayForDisplay()); setAction({ type: 'move-out', occupantId: person.id }) }} className="mt-2 min-h-11 underline">Ghi nhận chuyển đi</button>}</article>)}</div> : <p className="text-sm text-slate-500">{draft ? 'Người ở sẽ được ghi nhận khi kích hoạt.' : 'Hợp đồng cũ chưa liên kết lịch sử người ở. Không tự gán ngược dữ liệu.'}</p>}</Card>
    <ContractDocumentSection contract={contract} handoverConfirmed={handoverConfirmed} />
    <ContractSigningSection contract={contract} />
    <ContractHandoverSection contract={contract} />
    <ContractServicesSection key={id} contract={contract} />
    <Card title="Chỉ số đầu hợp đồng (dữ liệu chốt theo hợp đồng)"><p className="text-sm text-slate-600">{draft ? <>Phải ghi số đọc thực tế và ngày bàn giao trong thời hạn thuê trước khi kích hoạt. <Link to={`/contracts/${id}/edit`} state={forwardContext(location, `/rooms/${contract.room_id}`)} className="font-medium underline">Sửa tại bước 2. Người ở & dịch vụ</Link>.</> : <>Đây là chỉ số đầu đã chốt trong hợp đồng; Mốc CHECK_IN V2 được đồng bộ từ chỉ số đầu khi kích hoạt hợp đồng. Với hợp đồng cũ, cần đối soát và bổ sung mốc an toàn.</>}</p><div className="grid gap-3 sm:grid-cols-2">{openings.map(opening => <article key={opening.id ?? opening.utility_meter_id} className="rounded-lg border p-3 text-sm"><strong>{opening.service_name}</strong><p>{formatUtilityValue(opening.opening_reading)} {opening.unit}</p><p>Ngày ghi: {formatDate(opening.reading_date)}</p></article>)}</div>{openings.length === 0 && <p className="text-sm text-slate-500">Chưa có chỉ số đầu được ghi nhận cho hợp đồng này.</p>}</Card>
    <Card title="Khai báo cư trú">{declarations.length ? <ul className="space-y-2">{declarations.map(record => <li key={record.id}><Link to={`/residence-declarations/${record.id}`} className="inline-flex min-h-11 items-center text-sm underline">Hồ sơ #{record.id} · {residenceStatusLabels[record.status]}</Link></li>)}</ul> : <p className="text-sm text-slate-500">Tạm trú: Chưa khai báo.</p>}{active && <Link to={`/residence-declarations/new?room_id=${contract.room_id}&contract_id=${id}`} state={{ returnTo: `/contracts/${id}`, returnLabel: 'Hợp đồng' }} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Tạo hồ sơ tạm trú</Link>}</Card>
    {action && <div role="dialog" aria-modal="true" aria-label="Xác nhận thao tác hợp đồng" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3"><form onSubmit={confirmAction} className="max-h-[90dvh] w-full max-w-md space-y-4 overflow-y-auto rounded-xl bg-white p-4 sm:p-6"><h3 className="font-semibold">Xác nhận thao tác</h3><p className="text-sm">{action.type === 'delete' ? 'Xóa hợp đồng nháp này?' : action.type === 'move-out' ? 'Ghi nhận chuyển đi và giữ lịch sử người ở.' : action.status === 'ACTIVE' ? 'Kích hoạt sẽ chốt dữ liệu thỏa thuận và đồng bộ người ở. Không tự tạo hóa đơn hoặc thu tiền cọc.' : 'Kết thúc hợp đồng. Hãy xác nhận người thuê có chuyển khỏi phòng hay không.'}</p>{action.type === 'status' && action.status !== 'ACTIVE' && <fieldset className="space-y-2 text-sm"><legend className="font-medium">Người thuê có chuyển khỏi phòng không?</legend><label className="flex gap-2"><input type="radio" checked={action.moveOut === true} onChange={() => setAction({ ...action, moveOut: true })} />Có, ghi nhận chuyển đi</label><label className="flex gap-2"><input type="radio" checked={action.moveOut === false} onChange={() => setAction({ ...action, moveOut: false })} />Không, vẫn tiếp tục ở</label></fieldset>}{(action.type === 'move-out' || (action.type === 'status' && action.status !== 'ACTIVE' && action.moveOut)) && <label className="block text-sm">Ngày chuyển đi<DateInput value={date} onChange={setDate} /></label>}<div className="flex flex-wrap gap-3"><button disabled={mutate.isPending || (action.type === 'status' && action.status !== 'ACTIVE' && action.moveOut == null)} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">Xác nhận</button><button type="button" disabled={mutate.isPending} onClick={() => setAction(null)} className="min-h-11 px-4 text-sm">Hủy</button></div>{error && <p role="alert" className="text-sm text-red-700">{error}</p>}</form></div>}
  </section>
}
