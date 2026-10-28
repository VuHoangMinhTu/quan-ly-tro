import TenantPicker from '../tenants/TenantPicker'
import { handleFormSubmit } from '../../notifications/formFeedback'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link } from 'react-router-dom'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDateForDisplay, isValidDisplayDate } from '../../utils/date'
import { genderLabels } from '../../utils/formatters'
import { residenceFormValues, residencePayload } from '../../utils/residence'

const schema = z.object({
  declarant_tenant_id: z.string(), contract_id: z.string(), authority_name: z.string().max(255, 'Tên cơ quan không quá 255 ký tự.'),
  household_head_mode: z.enum(['', 'DECLARANT', 'OTHER_PERSON']),
  household_head_name: z.string().max(255, 'Họ tên chủ hộ không quá 255 ký tự.'),
  household_head_identity_number: z.string().max(30, 'Số định danh chủ hộ không quá 30 ký tự.'),
  relationship_to_household_head: z.string().max(255, 'Quan hệ không quá 255 ký tự.'), request_content: z.string().max(5000, 'Nội dung không quá 5.000 ký tự.'),
  members: z.array(z.object({ tenant_id: z.number().int(), relationship_to_household_head: z.string().max(255, 'Quan hệ không quá 255 ký tự.') })),
  stay_start_date: z.string().refine(value => !value || isValidDisplayDate(value), 'Ngày bắt đầu ở nhờ phải đúng dd/mm/yyyy.'),
  stay_end_date: z.string().refine(value => !value || isValidDisplayDate(value), 'Ngày kết thúc ở nhờ phải đúng dd/mm/yyyy.'),
  household_head_opinion: z.string().max(2000, 'Ý kiến chủ hộ không quá 2.000 ký tự.'),
  legal_owner_opinion: z.string().max(2000, 'Ý kiến chủ sở hữu không quá 2.000 ký tự.'),
  guardian_opinion: z.string().max(2000, 'Ý kiến người giám hộ không quá 2.000 ký tự.'),
  household_head_confirmation_method: z.string(), legal_owner_confirmation_method: z.string(), guardian_confirmation_method: z.string(),
})
const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 p-2'
const sectionClass = 'space-y-3 rounded-xl border border-slate-200 p-4'

function Datum({ label, value }) {
  return <div className="min-w-0"><dt className="text-slate-500">{label}</dt><dd className={`break-words ${value ? 'font-medium' : 'text-amber-700'}`}>{value || 'Chưa cập nhật'}</dd></div>
}

export function TenantAutofill({ tenant, role }) {
  if (!tenant) return <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Chọn người kê khai để tự điền thông tin từ hồ sơ người thuê.</p>
  const values = [
    ['1. Họ, chữ đệm và tên khai sinh', tenant.full_name], ['2. Ngày, tháng, năm sinh', formatDateForDisplay(tenant.date_of_birth)],
    ['3. Giới tính', genderLabels[tenant.gender]], ['4. Số định danh cá nhân', tenant.identity_number],
    ['5. Số điện thoại liên hệ', tenant.phone], ['6. Email', tenant.email],
  ]
  return <div className="space-y-3 rounded-lg bg-slate-50 p-3"><dl className="grid gap-3 text-sm sm:grid-cols-2">{values.map(([label, value]) => <Datum key={label} label={label} value={value} />)}</dl>
    {role === 'OWNER' ? <Link to={`/tenants/${tenant.id ?? tenant.tenant_id}/edit`} className="inline-flex min-h-11 items-center text-sm underline">Bổ sung thông tin trong hồ sơ người thuê</Link> : <p className="text-xs text-slate-500">Nếu thiếu dữ liệu, vui lòng yêu cầu chủ workspace cập nhật hồ sơ người thuê.</p>}
  </div>
}

export default function ResidenceDeclarationForm({ context, initial, initialPeople = [], onSubmit }) {
  const form = useForm({ resolver: zodResolver(schema), defaultValues: residenceFormValues(initial) })
  const declarantId = useWatch({ control: form.control, name: 'declarant_tenant_id' })
  const contractId = useWatch({ control: form.control, name: 'contract_id' })
  const householdHeadMode = useWatch({ control: form.control, name: 'household_head_mode' })
  const legalOwnerConfirmationMethod = useWatch({ control: form.control, name: 'legal_owner_confirmation_method' })
  const members = useWatch({ control: form.control, name: 'members' }) ?? []
  const tenants = context.occupants.map(occupant => occupant.tenant)
  const declarant = tenants.find(tenant => String(tenant.id) === declarantId)
  const selectedContract = context.contracts.find(contract => String(contract.id) === contractId)
  const profile = context.room.boarding_house?.residence_profile
  const [error, setError] = useState('')
  const { errors, isSubmitting } = form.formState
  const submit = async (values, event) => {
    setError('')
    const intent = event?.nativeEvent?.submitter?.value ?? 'draft'
    if (intent === 'finish' && !window.confirm('Hoàn tất sẽ chốt dữ liệu CT01 và văn bản ở nhờ theo thông tin hiện tại. Hãy kiểm tra thông tin trước khi tiếp tục.')) return
    try { await onSubmit(residencePayload(context.room.id, values), intent) } catch (requestError) {
      applyApiFieldErrors(requestError, form.setError)
      setError(getApiErrorMessage(requestError))
    }
  }
  const selectContract = form.register('contract_id')
  const chooseContract = event => {
    const previous = context.contracts.find(contract => String(contract.id) === form.getValues('contract_id'))
    const next = context.contracts.find(contract => String(contract.id) === event.target.value)
    for (const [field, source] of [['stay_start_date', 'start_date'], ['stay_end_date', 'end_date']]) {
      const current = form.getValues(field)
      if (!current || (previous && current === formatDateForDisplay(previous[source]))) {
        form.setValue(field, formatDateForDisplay(next?.[source]), { shouldDirty: true })
      }
    }
    selectContract.onChange(event)
  }
  return <form noValidate onSubmit={handleFormSubmit(form, submit)} className="min-w-0 space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <p className="text-sm text-slate-500">Có thể lưu nháp khi thiếu thông tin. Chọn rõ chủ hộ của hồ sơ này; khi nộp, BeeHouse chốt một bản snapshot.</p>
    {!profile && <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Nhà trọ chưa có cấu hình cư trú. Vui lòng bổ sung cơ quan tiếp nhận và thông tin chủ sở hữu đã được xác nhận; BeeHouse không tự lấy thông tin OWNER/MANAGER làm chủ hộ hoặc chủ sở hữu. <Link to={`/boarding-houses/${context.room.boarding_house_id}`} className="underline">Cập nhật cấu hình nhà trọ</Link>.</p>}
    <section className={sectionClass}><h3 className="font-semibold">1. Cơ quan tiếp nhận</h3>
      <label htmlFor="ct01-authority" className="block text-sm">Kính gửi / cơ quan tiếp nhận<input id="ct01-authority" className={inputClass} {...form.register('authority_name')} /></label><p className="text-xs text-slate-500">Đã lấy từ cấu hình của nhà trọ. Có thể thay đổi cho hồ sơ này.</p>{errors.authority_name && <p className="text-sm text-red-700">{errors.authority_name.message}</p>}
      <label htmlFor="ct01-contract" className="block text-sm">Hợp đồng liên quan<select id="ct01-contract" className={inputClass} {...selectContract} onChange={chooseContract}><option value="">Không liên kết hợp đồng</option>{context.contracts.map(contract => <option key={contract.id} value={String(contract.id)}>{contract.contract_code}</option>)}</select></label>{errors.contract_id && <p className="text-sm text-red-700">{errors.contract_id.message}</p>}
    </section>
    <section className={sectionClass}><h3 className="font-semibold">2. Người kê khai</h3>
      <TenantPicker label="Chọn người kê khai" value={declarantId} initialTenants={tenants} contextRoomId={context.room.id} scope="ACTIVE_ROOM" allowClear invalid={Boolean(errors.declarant_tenant_id)} onChange={id => { form.setValue('declarant_tenant_id', id ? String(id) : '', { shouldDirty: true }); form.setValue('members', members.filter(member => member.tenant_id !== Number(id)), { shouldDirty: true }) }} />{errors.declarant_tenant_id && <p className="text-sm text-red-700">{errors.declarant_tenant_id.message}</p>}
      <TenantAutofill tenant={declarant} role={context.operational_role} />
    </section>
    <section className={sectionClass}><h3 className="font-semibold">3. Thông tin chủ hộ</h3><p className="text-sm">Ai là chủ hộ của hồ sơ tạm trú này?</p>
      <div className="flex flex-wrap gap-4 text-sm"><label className="flex items-center gap-2"><input type="radio" value="DECLARANT" {...form.register('household_head_mode')} />Người kê khai</label><label className="flex items-center gap-2"><input type="radio" value="OTHER_PERSON" {...form.register('household_head_mode')} />Một người khác</label></div>
      {householdHeadMode === 'DECLARANT' && <dl className="grid gap-2 text-sm sm:grid-cols-2"><Datum label="7. Họ tên chủ hộ" value={declarant?.full_name} /><Datum label="8. Mối quan hệ với chủ hộ" value="Chủ hộ" /><Datum label="9. Số định danh chủ hộ" value={declarant?.identity_number} /></dl>}
      {householdHeadMode === 'OTHER_PERSON' && <div className="space-y-3"><label className="block text-sm">7. Họ tên chủ hộ<input className={inputClass} {...form.register('household_head_name')} />{errors.household_head_name && <span className="text-red-700">{errors.household_head_name.message}</span>}</label><label className="block text-sm">8. Mối quan hệ với chủ hộ<input list="ct01-relationships" className={inputClass} {...form.register('relationship_to_household_head')} />{errors.relationship_to_household_head && <span className="text-red-700">{errors.relationship_to_household_head.message}</span>}</label><label className="block text-sm">9. Số định danh cá nhân chủ hộ<input className={inputClass} {...form.register('household_head_identity_number')} />{errors.household_head_identity_number && <span className="text-red-700">{errors.household_head_identity_number.message}</span>}</label></div>}
      <datalist id="ct01-relationships">{['Ở thuê', 'Ở nhờ', 'Cùng ở thuê', 'Cùng ở nhờ', 'Vợ', 'Chồng', 'Con', 'Cha', 'Mẹ', 'Khác'].map(value => <option key={value} value={value} />)}</datalist>
      <p className="text-xs text-slate-500">Chủ hộ của hồ sơ cư trú không mặc định là chủ sở hữu chỗ ở hợp pháp.</p>
    </section>
    <section className={sectionClass}><h3 className="font-semibold">4. Nội dung đề nghị</h3><label htmlFor="ct01-request" className="block text-sm">10. Nội dung đề nghị<textarea id="ct01-request" rows={3} className={inputClass} {...form.register('request_content')} /></label>{errors.request_content && <p className="text-sm text-red-700">{errors.request_content.message}</p>}</section>
    <fieldset className={sectionClass}><legend className="px-1 font-semibold">5. Thành viên cùng thay đổi</legend><p className="text-sm text-slate-500">11. Chỉ chọn những người đang ở cùng phòng; người đã chuyển đi không xuất hiện.</p>
      <TenantPicker label="Thành viên cùng thay đổi" multiple allowClear value={members.map(member => member.tenant_id)} initialTenants={tenants} contextRoomId={context.room.id} scope="ACTIVE_ROOM" excludeIds={declarantId ? [Number(declarantId)] : []} onChange={ids => form.setValue('members', ids.map(id => members.find(member => member.tenant_id === id) ?? { tenant_id: id, relationship_to_household_head: '' }), { shouldDirty: true })} />
      {members.length > 0 && <div className="min-w-0 overflow-x-auto"><table className="w-full min-w-[760px] border-collapse text-left text-sm"><thead><tr>{['STT', 'Họ, chữ đệm và tên', 'Ngày sinh', 'Giới tính', 'Số định danh cá nhân', 'Mối quan hệ với chủ hộ', ''].map(label => <th key={label} className="border-b p-2">{label}</th>)}</tr></thead><tbody>{members.map((member, index) => {
        const tenant = tenants.find(person => Number(person.id) === member.tenant_id) || initialPeople.find(person => Number(person.tenant_id) === member.tenant_id)
        return <tr key={member.tenant_id}><td className="border-b p-2">{index + 1}</td><td className="border-b p-2">{tenant?.full_name || 'Chưa cập nhật'}</td><td className="border-b p-2">{formatDateForDisplay(tenant?.date_of_birth) || 'Chưa cập nhật'}</td><td className="border-b p-2">{genderLabels[tenant?.gender] || 'Chưa cập nhật'}</td><td className="border-b p-2">{tenant?.identity_number || 'Chưa cập nhật'}</td><td className="border-b p-2"><input aria-label={`Quan hệ với chủ hộ của ${tenant?.full_name || 'thành viên'}`} list="ct01-relationships" className={inputClass} {...form.register(`members.${index}.relationship_to_household_head`)} />{errors.members?.[index] && <p className="text-red-700">{errors.members[index].relationship_to_household_head?.message || errors.members[index].tenant_id?.message}</p>}</td><td className="border-b p-2"><button type="button" className="min-h-11 text-red-700" onClick={() => form.setValue('members', members.filter((_, current) => current !== index), { shouldDirty: true })}>Bỏ</button></td></tr>
      })}</tbody></table></div>}
    </fieldset>
    <section className={sectionClass}><h3 className="font-semibold">6. Xác nhận</h3><p className="text-sm text-slate-500">Chọn phương thức dự kiến; lựa chọn này không thay thế chữ ký hoặc xác nhận thực tế trên CT01.</p>
      <div className="space-y-2 text-sm"><strong>Chủ hộ</strong>{householdHeadMode === 'DECLARANT' ? <p>Người kê khai đồng thời là chủ hộ. Không cần xác nhận riêng.</p> : householdHeadMode === 'OTHER_PERSON' ? <label className="block">Phương thức xác nhận của chủ hộ<select className={inputClass} {...form.register('household_head_confirmation_method')}><option value="">Chưa chọn</option><option value="DIRECT">Ký trực tiếp trên CT01</option><option value="ELECTRONIC">Xác nhận điện tử / VNeID / dịch vụ công</option><option value="SEPARATE_DOCUMENT">Có văn bản đồng ý riêng</option></select></label> : <p>Chưa xác định chủ hộ.</p>}</div>
      <div className="space-y-2 text-sm"><strong>Chủ sở hữu chỗ ở hợp pháp</strong><p>{profile?.legal_owner_name || 'Chưa cập nhật trong cấu hình nhà trọ'}</p><label className="block">Phương thức xác nhận<select className={inputClass} {...form.register('legal_owner_confirmation_method')}><option value="">Chưa chọn</option><option value="DIRECT">Ký trực tiếp trên CT01</option><option value="ELECTRONIC">Xác nhận điện tử / VNeID / dịch vụ công</option><option value="SEPARATE_DOCUMENT">Có văn bản đồng ý riêng</option></select></label>{legalOwnerConfirmationMethod === 'SEPARATE_DOCUMENT' && <button type="submit" name="intent" value="preview-stay-consent" className="min-h-11 text-sm underline">Lưu và xem văn bản đồng ý cho ở nhờ nhà</button>}</div>
      <label className="block text-sm">Cha/mẹ/người giám hộ<select className={inputClass} {...form.register('guardian_confirmation_method')}><option value="NOT_APPLICABLE">Không áp dụng</option><option value="DIRECT">Ký trực tiếp trên CT01</option><option value="ELECTRONIC">Xác nhận điện tử / VNeID / dịch vụ công</option><option value="SEPARATE_DOCUMENT">Có văn bản đồng ý riêng</option></select></label>
      <p className="text-sm">Người kê khai: <strong>{declarant?.full_name || 'Chưa cập nhật'}</strong></p>
    </section>
    <section className={sectionClass}><h3 className="font-semibold">7. Thời hạn văn bản ở nhờ</h3><p className="text-sm text-slate-500">{selectedContract ? `Đã lấy từ hợp đồng: ${formatDateForDisplay(selectedContract.start_date) || 'Chưa cập nhật'} – ${formatDateForDisplay(selectedContract.end_date) || 'Chưa cập nhật'}. Có thể thay đổi riêng cho văn bản ở nhờ.` : 'Nếu không có hợp đồng, nhập thời hạn ở nhờ để có thể in văn bản.'}</p><div className="grid gap-3 sm:grid-cols-2"><label className="block text-sm">Từ ngày<input type="text" inputMode="numeric" placeholder="dd/mm/yyyy" className={inputClass} {...form.register('stay_start_date')} />{errors.stay_start_date && <span className="text-red-700">{errors.stay_start_date.message}</span>}</label><label className="block text-sm">Đến ngày<input type="text" inputMode="numeric" placeholder="dd/mm/yyyy" className={inputClass} {...form.register('stay_end_date')} />{errors.stay_end_date && <span className="text-red-700">{errors.stay_end_date.message}</span>}</label></div></section>
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    <div className="flex flex-wrap gap-2"><button disabled={isSubmitting} type="submit" name="intent" value="draft" className="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Lưu bản nháp</button><button disabled={isSubmitting} type="submit" name="intent" value="preview-ct01" className="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Xem trước CT01</button><button disabled={isSubmitting} type="submit" name="intent" value="preview-stay-consent" className="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Xem trước văn bản ở nhờ</button><button disabled={isSubmitting} type="submit" name="intent" value="finish" className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{isSubmitting ? 'Đang lưu...' : 'Hoàn tất'}</button></div>
  </form>
}
