import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { deleteResidenceDeclaration, getResidenceDeclaration, residenceKeys, updateResidenceStatus } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDateForApi, formatDateForDisplay, isValidDisplayDate } from '../../utils/date'
import { residenceStatusLabels, residenceTransitions } from '../../utils/residence'
import Ct01Document from '../../components/residence/Ct01Document'
import StayConsentDocument from '../../components/residence/StayConsentDocument'
import DateInput from '../../components/ui/DateInput'

function StatusForm({ record }) {
  const client = useQueryClient()
  const [status, setStatus] = useState('')
  const [expires, setExpires] = useState(formatDateForDisplay(record.expires_at))
  const [dateError, setDateError] = useState('')
  const mutation = useMutation({ mutationFn: data => updateResidenceStatus(record.id, data), onSuccess: async () => {
    setStatus('')
    await Promise.all([client.invalidateQueries({ queryKey: residenceKeys.detail(record.id) }), client.invalidateQueries({ queryKey: residenceKeys.list })])
  } })
  const transitions = residenceTransitions[record.status] ?? []
  if (!transitions.length) return null
  return <form className="space-y-3 rounded-xl bg-white p-4 shadow-sm" onSubmit={event => {
    event.preventDefault(); setDateError('')
    if (expires && !isValidDisplayDate(expires)) { setDateError('Ngày hết hạn phải theo dd/mm/yyyy và hợp lệ.'); return }
    mutation.mutate({ status, expires_at: formatDateForApi(expires) })
  }}><h3 className="font-semibold">Theo dõi trạng thái hồ sơ</h3><p className="text-sm text-slate-500">Chỉ cập nhật khi bạn đã thực hiện thao tác tương ứng. BeeHouse không gửi hồ sơ tới cơ quan cư trú. Chuyển sang Đã nộp sẽ chốt dữ liệu, không sửa nội dung được nữa.</p><div className="grid gap-3 sm:grid-cols-2"><div><label htmlFor="ct01-status" className="text-sm">Trạng thái tiếp theo</label><select id="ct01-status" value={status} onChange={event => setStatus(event.target.value)} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2"><option value="">Chọn trạng thái</option>{transitions.map(value => <option key={value} value={value}>{residenceStatusLabels[value]}</option>)}</select></div><div><label className="text-sm">Ngày hết hạn (nếu có)</label><DateInput value={expires} onChange={setExpires} placeholder="dd/mm/yyyy" invalid={Boolean(dateError)} />{dateError && <p className="text-sm text-red-700">{dateError}</p>}</div></div>{mutation.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(mutation.error)}</p>}<button disabled={!status || mutation.isPending} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{mutation.isPending ? 'Đang cập nhật...' : 'Cập nhật trạng thái'}</button></form>
}

export default function ResidenceDeclarationDetailPage() {
  const { id } = useParams()
  const client = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const query = useQuery({ queryKey: residenceKeys.detail(id), queryFn: () => getResidenceDeclaration(id) })
  useEffect(() => {
    if (!query.isSuccess || !location.hash) return
    const target = document.getElementById(location.hash.slice(1))
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [query.isSuccess, location.hash])
  const remove = useMutation({ mutationFn: () => deleteResidenceDeclaration(id), onSuccess: async () => {
    await client.invalidateQueries({ queryKey: residenceKeys.list }); client.removeQueries({ queryKey: residenceKeys.detail(id) }); navigate('/residence-declarations', { replace: true })
  } })
  if (query.isPending) return <p>Đang tải hồ sơ...</p>
  if (query.isError) return <p className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p>
  const data = query.data.data.data
  const record = data.declaration
  const editable = !data.is_frozen && ['DRAFT', 'PENDING'].includes(record.status)
  return <section className="min-w-0 max-w-5xl space-y-5"><Link to="/residence-declarations" className="inline-flex min-h-11 items-center text-sm underline">← Danh sách hồ sơ</Link><div className="flex flex-col items-start justify-between gap-3 sm:flex-row"><div><h2 className="text-xl font-bold sm:text-2xl">CT01 #{record.id}</h2><p className="mt-1 text-sm text-slate-500">{residenceStatusLabels[record.status]}{data.is_frozen ? ' · Dữ liệu đã chốt theo snapshot' : ' · Bản nháp dùng dữ liệu hiện tại'}</p></div><div className="flex flex-wrap gap-3">{editable && <Link to={`/residence-declarations/${id}/edit`} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Chỉnh sửa dữ liệu</Link>}<a href="#ct01-preview" className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Xem CT01</a>{data.stay_consent && <a href="#stay-consent-preview" className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Xem văn bản ở nhờ</a>}{data.missing_fields.length === 0 && <Link to={`/residence-declarations/${id}/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-3 text-sm text-white">In CT01</Link>}{data.stay_consent && Object.keys(data.stay_consent_missing_fields ?? {}).length === 0 && <Link to={`/residence-declarations/${id}/stay-consent/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-3 text-sm text-white">In văn bản ở nhờ</Link>}{record.status === 'DRAFT' && <button disabled={remove.isPending} onClick={() => window.confirm('Xóa hồ sơ nháp này?') && remove.mutate()} className="min-h-11 rounded-lg border border-red-200 px-3 text-sm text-red-700">Xóa nháp</button>}</div></div>
    {location.state?.finishError && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Đã lưu bản nháp nhưng chưa thể hoàn tất: {location.state.finishError}</p>}
    {data.missing_fields.length > 0 && <Link to={`/residence-declarations/${id}/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">In CT01 với các ô còn thiếu để trống</Link>}
    {data.stay_consent && Object.keys(data.stay_consent_missing_fields ?? {}).length > 0 && <Link to={`/residence-declarations/${id}/stay-consent/print`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">In văn bản ở nhờ với các ô còn thiếu để trống</Link>}
    {remove.isError && <p className="text-sm text-red-700">{getApiErrorMessage(remove.error)}</p>}
    {data.missing_fields.length > 0 && <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4"><h3 className="font-semibold">Cần bổ sung trước khi xuất / chốt CT01</h3><ul className="space-y-2 text-sm">{data.missing_fields.map(missing => <li key={missing.field} className="break-words">{missing.message}{missing.target === 'tenant' && missing.tenant_id && data.operational_role === 'OWNER' ? <Link to={`/tenants/${missing.tenant_id}/edit`} className="ml-2 inline-flex min-h-11 items-center underline">Chỉnh hồ sơ người thuê</Link> : editable && (missing.target !== 'tenant' || !missing.tenant_id) ? <Link to={`/residence-declarations/${id}/edit`} className="ml-2 inline-flex min-h-11 items-center underline">Bổ sung dữ liệu</Link> : missing.target === 'tenant' && data.operational_role === 'MANAGER' ? <span className="ml-2">Vui lòng yêu cầu chủ workspace cập nhật người thuê.</span> : null}</li>)}</ul></div>}
    <p className="text-sm text-slate-500">Bản xem trước hỗ trợ điền CT01. Kiểm tra nội dung, giấy tờ và các ý kiến/chữ ký cần thiết trước khi sử dụng.</p>
    <div id="ct01-preview" className="min-w-0 overflow-hidden rounded-xl border border-slate-200"><Ct01Document data={data.ct01} /></div>
    <div id="stay-consent-preview" className="space-y-3"><h3 className="text-lg font-semibold">Văn bản đồng ý cho ở nhờ nhà</h3>{data.stay_consent ? <><p className="text-sm text-slate-500">{Object.keys(data.stay_consent_missing_fields ?? {}).length ? 'Bản xem trước còn thiếu thông tin; bổ sung trong hồ sơ người thuê, cấu hình nhà trọ hoặc liên kết hợp đồng trước khi in.' : 'Dữ liệu đã đủ để in. Chữ ký vẫn cần được các bên thực hiện.'}</p>{Object.keys(data.stay_consent_missing_fields ?? {}).length > 0 && <ul className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{Object.entries(data.stay_consent_missing_fields).map(([field, message]) => <li key={field}>• {message}{field === 'guest.permanent_address' && data.operational_role === 'OWNER' && record.declarant_tenant_id ? <Link to={`/tenants/${record.declarant_tenant_id}/edit`} className="ml-2 underline">Cập nhật người thuê</Link> : null}{field === 'document_place' ? <Link to={`/boarding-houses/${record.boarding_house_id}`} className="ml-2 underline">Cấu hình nhà trọ</Link> : null}</li>)}</ul>}<div className="min-w-0 overflow-hidden rounded-xl border border-slate-200"><StayConsentDocument data={data.stay_consent} /></div></> : <p className="rounded-lg border p-4 text-sm text-slate-600">Hồ sơ này đã chốt trước khi có văn bản ở nhờ. Không tự ghép dữ liệu hiện tại vào hồ sơ cũ.</p>}</div>
    <StatusForm key={`${record.id}-${record.status}`} record={record} />
  </section>
}
