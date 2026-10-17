import { useState } from 'react'
import ContractForm from '../forms/ContractForm'
import DateInput from '../ui/DateInput'
import { formatDateForApi, formatDateForDisplay, isValidDisplayDate } from '../../utils/date'
import { formatCurrency, formatUtilityValue } from '../../utils/formatters'
import { BILLING_METHOD_LABELS } from '../../utils/service'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage'

const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50'

export default function ContractWizard({ context, initial, onSubmit }) {
  const [step, setStep] = useState(0)
  const [core, setCore] = useState(() => initial ?? { monthly_rent: context.room.monthly_rent ?? '', deposit_amount: '', status: 'DRAFT' })
  const [people, setPeople] = useState(() => (initial?.occupant_tenant_ids ?? []).map(String))
  const [services, setServices] = useState(() => (initial ? initial.agreed_services ?? [] : context.services).map(service => String(service.service_id ?? service.id)))
  const [openings, setOpenings] = useState(() => (initial ? initial.utility_openings ?? [] : context.utility_openings).map(opening => ({ ...opening, reading_date: formatDateForDisplay(opening.reading_date) })))
  const [representative, setRepresentative] = useState(() => String(initial?.lessor_representative_user_id ?? ''))
  const [paymentDueDay, setPaymentDueDay] = useState(() => initial ? initial.payment_due_day ?? '' : context.room.boarding_house.default_payment_due_day ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const toggle = (setter, values, id) => setter(values.includes(String(id)) ? values.filter(value => value !== String(id)) : [...values, String(id)])
  const errorFor = field => fieldErrors[field] && <p className="mt-1 text-sm text-red-700">{fieldErrors[field]}</p>
  const save = async status => {
    setError(''); setFieldErrors({})
    if (status === 'ACTIVE' && (!core.start_date || core.monthly_rent == null || !representative)) {
      setError('Để kích hoạt, vui lòng bổ sung ngày bắt đầu, giá thuê thỏa thuận và người đại diện Bên A.'); return
    }
    if (paymentDueDay !== '' && (!Number.isInteger(Number(paymentDueDay)) || Number(paymentDueDay) < 1 || Number(paymentDueDay) > 31)) {
      setFieldErrors({ payment_due_day: 'Ngày thanh toán hàng tháng phải là số nguyên từ 1 đến 31.' })
      setError('Vui lòng kiểm tra ngày thanh toán hàng tháng.'); return
    }
    if (openings.some(opening => !isValidDisplayDate(opening.reading_date) || opening.opening_reading === '' || !Number.isFinite(Number(opening.opening_reading)) || Number(opening.opening_reading) < 0)) {
      setError('Vui lòng kiểm tra chỉ số đầu không âm và ngày ghi theo dd/mm/yyyy.'); return
    }
    setSaving(true)
    try {
      const contractValues = { ...core }
      // Older API records can still contain the retired invoice issue range.
      delete contractValues.invoice_issue_day_from
      delete contractValues.invoice_issue_day_to
      await onSubmit({ ...contractValues, status, lessor_representative_user_id: representative ? Number(representative) : null,
        payment_due_day: paymentDueDay === '' ? null : Number(paymentDueDay),
        occupant_tenant_ids: people.filter(id => Number(id) !== Number(core.tenant_id)).map(Number), service_ids: services.map(Number),
        utility_openings: openings.map(opening => ({ utility_meter_id: Number(opening.utility_meter_id), opening_reading: Number(opening.opening_reading), reading_date: formatDateForApi(opening.reading_date) })),
      })
    } catch (requestError) { setError(getApiErrorMessage(requestError)); setFieldErrors(getApiFieldErrors(requestError)) } finally { setSaving(false) }
  }
  const titles = ['Thông tin & tài chính', 'Người ở & dịch vụ', 'Đại diện & lịch thanh toán', 'Kiểm tra và lưu']
  return <div className="min-w-0 space-y-4">
    <ol aria-label="Các bước tạo hợp đồng" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{titles.map((title, index) => <li key={title} aria-current={step === index ? 'step' : undefined} className={`rounded-lg p-3 text-sm ${step === index ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'}`}>{index + 1}. {title}</li>)}</ol>
    <p className="text-sm text-slate-500">Phòng {context.room.room_code} · {context.room.boarding_house.name}. Bản nháp có thể chưa có giá thuê/ngày bắt đầu. Kích hoạt sẽ chốt điều khoản và ghi nhận người ở.</p>
    {step === 0 && <ContractForm key="core" initialValues={core} tenantOptions={context.tenants} wizard onSubmit={async values => { setCore(values); setStep(1) }} submitLabel="Tiếp tục" />}
    {step === 1 && <div className="space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
      <fieldset><legend className="font-semibold">Người ở cùng</legend><p className="my-2 text-sm text-slate-500">Người đứng tên được tự động thêm khi kích hoạt. Tối đa của phòng: {context.room.max_tenants ?? 'Chưa thiết lập'}.</p><div className="grid gap-2 sm:grid-cols-2">{context.tenants.filter(tenant => Number(tenant.id) !== Number(core.tenant_id)).map(tenant => <label key={tenant.id} className="flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm"><input type="checkbox" checked={people.includes(String(tenant.id))} onChange={() => toggle(setPeople, people, tenant.id)} />{tenant.full_name}</label>)}</div>{errorFor('occupant_tenant_ids')}</fieldset>
      <fieldset><legend className="font-semibold">Dịch vụ thỏa thuận</legend><p className="my-2 text-sm text-slate-500">Chọn từ dịch vụ đang áp dụng cho phòng. Giá và bậc giá được chốt khi kích hoạt; Billing hiện vẫn dùng RoomServices.</p><div className="grid gap-2 sm:grid-cols-2">{context.services.map(service => <label key={service.id} className="flex items-start gap-3 rounded-lg border p-3 text-sm"><input type="checkbox" className="mt-1" checked={services.includes(String(service.id))} onChange={() => toggle(setServices, services, service.id)} /><span className="min-w-0 break-words"><strong>{service.name}</strong><span className="block text-slate-500">{BILLING_METHOD_LABELS[service.billing_method]} · {service.billing_method === 'TIERED' ? 'Theo bảng bậc giá' : formatCurrency(service.base_price)}{service.unit ? ` / ${service.unit}` : ''} · VAT {Number(service.vat_rate ?? 0)}%</span></span></label>)}</div>{context.services.length === 0 && <p className="text-sm text-slate-500">Phòng chưa có dịch vụ đang áp dụng.</p>}{services.filter(id => !context.services.some(service => String(service.id) === id)).map(id => <div key={id} className="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><span>Dịch vụ đã chọn #{id} không còn áp dụng cho phòng.</span><button type="button" className="min-h-11 underline" onClick={() => setServices(services.filter(value => value !== id))}>Bỏ khỏi bản nháp</button></div>)}{errorFor('service_ids')}</fieldset>
      <section className="space-y-3"><h3 className="font-semibold">Chỉ số đầu hợp đồng</h3><button type="button" onClick={() => setOpenings(context.utility_openings.map(opening => ({ ...opening, reading_date: formatDateForDisplay(opening.reading_date) })))} className="min-h-11 text-sm underline">Lấy lại chỉ số gợi ý hiện tại</button><p className="text-sm text-slate-500">Gợi ý từ chỉ số mới nhất, hoặc chỉ số khi lắp đồng hồ nếu chưa có lần ghi. Có thể kiểm tra/chỉnh trước khi chốt; không tạo mức tiêu thụ.</p>{openings.map((opening, index) => <div key={opening.utility_meter_id} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2"><strong className="break-words text-sm sm:col-span-2">{opening.service_name} · {formatUtilityValue(opening.opening_reading)} {opening.unit}</strong><label className="text-sm">Chỉ số đầu<input type="number" min="0" step="0.01" value={opening.opening_reading} onChange={event => setOpenings(openings.map((row, at) => at === index ? { ...row, opening_reading: event.target.value } : row))} className={inputClass} /></label><label className="text-sm">Ngày ghi<DateInput value={opening.reading_date} onChange={value => setOpenings(openings.map((row, at) => at === index ? { ...row, reading_date: value } : row))} /></label><button type="button" onClick={() => setOpenings(openings.filter((_, at) => at !== index))} className="min-h-11 text-left text-sm text-red-700 underline sm:col-span-2">Bỏ chỉ số này khỏi bản nháp</button></div>)}{openings.length === 0 && <p className="text-sm text-slate-500">Phòng chưa có đồng hồ điện/nước đang hoạt động.</p>}</section>
    </div>}
    {step === 2 && <div className="grid gap-5 rounded-xl bg-white p-4 shadow-sm sm:p-6 md:grid-cols-2"><div><label htmlFor="contract-representative" className="text-sm font-medium">Người đại diện Bên A (bắt buộc khi kích hoạt)</label><select id="contract-representative" value={representative} onChange={event => setRepresentative(event.target.value)} className={inputClass}><option value="">Chọn người đại diện</option>{context.representatives.map(person => <option key={person.user_id} value={String(person.user_id)}>{person.full_name} — {person.title}</option>)}</select>{errorFor('lessor_representative_user_id')}<p className="mt-2 text-sm text-slate-500">Chỉ gồm chủ trọ và quản lý đang được phân công. Không đồng nghĩa với chủ hộ pháp lý trong CT01.</p></div><div><label htmlFor="contract-payment-due-day" className="text-sm font-medium">Ngày thanh toán hàng tháng</label><input id="contract-payment-due-day" type="number" min="1" max="31" step="1" value={paymentDueDay} onChange={event => setPaymentDueDay(event.target.value)} className={inputClass} placeholder="1–31 (không bắt buộc)" aria-describedby="contract-payment-due-help" aria-invalid={Boolean(fieldErrors.payment_due_day)} />{errorFor('payment_due_day')}<p id="contract-payment-due-help" className="mt-2 text-sm text-slate-500">{paymentDueDay !== '' && Number.isInteger(Number(paymentDueDay)) && Number(paymentDueDay) >= 1 && Number(paymentDueDay) <= 31 ? `Hạn thanh toán của hóa đơn sẽ là ngày ${String(paymentDueDay).padStart(2, '0')} hàng tháng.` : 'Có thể để trống nếu chưa thỏa thuận ngày thanh toán.'} Nếu tháng không có ngày đã chọn, dùng ngày cuối cùng của tháng.</p><p className="mt-2 text-sm text-slate-500">Gợi ý từ cấu hình nhà trọ khi tạo mới; có thể thay đổi riêng cho hợp đồng này.</p></div></div>}
    {step === 3 && <section className="space-y-3 rounded-xl bg-white p-4 shadow-sm sm:p-6"><h3 className="font-semibold">Kiểm tra trước khi lưu</h3><dl className="grid gap-3 text-sm sm:grid-cols-2"><div><dt>Mã hợp đồng</dt><dd className="break-words font-medium">{core.contract_code}</dd></div><div><dt>Người đứng tên</dt><dd>{context.tenants.find(person => Number(person.id) === Number(core.tenant_id))?.full_name}</dd></div><div><dt>Giá thuê thỏa thuận</dt><dd>{core.monthly_rent == null ? 'Chưa thiết lập' : formatCurrency(core.monthly_rent)}</dd></div><div><dt>Tiền cọc thỏa thuận (không phải đã thu)</dt><dd>{formatCurrency(core.deposit_amount)}</dd></div><div><dt>Người ở đăng ký</dt><dd>{1 + people.filter(id => Number(id) !== Number(core.tenant_id)).length}</dd></div><div><dt>Dịch vụ đã chọn</dt><dd>{services.length}</dd></div></dl><p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Sau kích hoạt, các điều khoản cốt lõi, thông tin hai bên, dịch vụ và chỉ số đầu được chốt. Muốn thay đổi quan trọng cần hợp đồng mới. Chưa tạo hóa đơn ban đầu hay ghi nhận đã thu cọc.</p><div className="flex flex-wrap gap-3"><button type="button" disabled={saving} onClick={() => save('DRAFT')} className="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Lưu nháp</button><button type="button" disabled={saving} onClick={() => save('ACTIVE')} className={buttonClass}>{saving ? 'Đang lưu...' : 'Kích hoạt hợp đồng'}</button></div></section>}
    {error && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700"><p>{error}</p>{Object.entries(fieldErrors).map(([key, value]) => <p key={key}>{value}</p>)}<p className="mt-2">Có thể quay lại các bước để chỉnh dữ liệu.</p></div>}
    <div className="flex flex-wrap gap-3">{step > 0 && <button type="button" disabled={saving} onClick={() => setStep(step - 1)} className="min-h-11 rounded-lg border px-4 py-2 text-sm">← Quay lại</button>}{step > 0 && step < 3 && <button type="button" onClick={() => setStep(step + 1)} className={buttonClass}>Tiếp tục</button>}</div>
  </div>
}
