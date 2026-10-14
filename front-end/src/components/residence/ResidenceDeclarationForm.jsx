import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link } from 'react-router-dom'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDateForDisplay } from '../../utils/date'
import { genderLabels } from '../../utils/formatters'
import { residenceFormValues, residencePayload } from '../../utils/residence'

const schema = z.object({
  declarant_tenant_id: z.string(), contract_id: z.string(), authority_name: z.string().max(255, 'Tên cơ quan không quá 255 ký tự.'),
  relationship_to_household_head: z.string().max(255, 'Quan hệ không quá 255 ký tự.'), request_content: z.string().max(5000, 'Nội dung không quá 5.000 ký tự.'),
  members: z.array(z.object({ tenant_id: z.number().int(), relationship_to_household_head: z.string().max(255, 'Quan hệ không quá 255 ký tự.') })),
})
const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 p-2'

export function TenantAutofill({ tenant, role }) {
  if (!tenant) return <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">Chọn người kê khai để tự điền thông tin từ hồ sơ người thuê.</p>
  const values = [['Họ tên', tenant.full_name], ['Ngày sinh', formatDateForDisplay(tenant.date_of_birth)], ['Giới tính', genderLabels[tenant.gender]], ['Số định danh cá nhân', tenant.identity_number], ['Số điện thoại', tenant.phone], ['Email', tenant.email]]
  return <div className="space-y-2 rounded-lg bg-slate-50 p-3"><dl className="grid gap-3 text-sm sm:grid-cols-2">{values.map(([label, value]) => <div key={label} className="min-w-0"><dt className="text-slate-500">{label}</dt><dd className={`break-words ${value ? 'font-medium' : 'text-amber-700'}`}>{value || 'Chưa cập nhật'}</dd></div>)}</dl>
    {role === 'OWNER' ? <Link to={`/tenants/${tenant.id ?? tenant.tenant_id}/edit`} className="inline-flex min-h-11 items-center text-sm underline">Bổ sung thông tin trong hồ sơ người thuê</Link> : <p className="text-xs text-slate-500">Nếu thiếu dữ liệu, vui lòng yêu cầu chủ workspace cập nhật hồ sơ người thuê.</p>}
  </div>
}

export default function ResidenceDeclarationForm({ context, initial, initialPeople = [], onSubmit }) {
  const form = useForm({ resolver: zodResolver(schema), defaultValues: residenceFormValues(initial) })
  const declarantId = useWatch({ control: form.control, name: 'declarant_tenant_id' })
  const members = useWatch({ control: form.control, name: 'members' })
  const tenants = context.occupants.map(occupant => occupant.tenant)
  const declarant = tenants.find(tenant => String(tenant.id) === declarantId)
  const [error, setError] = useState('')
  const { errors, isSubmitting } = form.formState
  const submit = async values => {
    setError('')
    try { await onSubmit(residencePayload(context.room.id, values)) } catch (requestError) {
      applyApiFieldErrors(requestError, form.setError)
      setError(getApiErrorMessage(requestError))
    }
  }
  const selectDeclarant = form.register('declarant_tenant_id')
  const toggleMember = tenant => {
    const selected = members.some(member => member.tenant_id === Number(tenant.id))
    form.setValue('members', selected ? members.filter(member => member.tenant_id !== Number(tenant.id)) : [...members, { tenant_id: Number(tenant.id), relationship_to_household_head: '' }], { shouldDirty: true })
  }
  return <form onSubmit={form.handleSubmit(submit)} className="min-w-0 space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <p className="text-sm text-slate-500">Có thể lưu nháp khi thiếu thông tin. Form này không ghi đè dữ liệu người thuê. Hãy lưu nháp trước khi sang trang chỉnh hồ sơ người thuê để không mất nội dung đang nhập.</p>
    <div><label htmlFor="ct01-declarant" className="text-sm font-medium">Người kê khai</label><select id="ct01-declarant" className={inputClass} {...selectDeclarant} onChange={event => { selectDeclarant.onChange(event); form.setValue('members', members.filter(member => String(member.tenant_id) !== event.target.value), { shouldDirty: true }) }}><option value="">Chưa chọn người kê khai</option>{tenants.map(tenant => <option key={tenant.id} value={String(tenant.id)}>{tenant.full_name}</option>)}</select>{errors.declarant_tenant_id && <p className="text-sm text-red-700">{errors.declarant_tenant_id.message}</p>}</div>
    <TenantAutofill tenant={declarant} role={context.operational_role} />
    <div><label htmlFor="ct01-contract" className="text-sm font-medium">Hợp đồng liên quan</label><select id="ct01-contract" className={inputClass} {...form.register('contract_id')}><option value="">Không liên kết hợp đồng</option>{context.contracts.map(contract => <option key={contract.id} value={String(contract.id)}>{contract.contract_code}</option>)}</select>{errors.contract_id && <p className="text-sm text-red-700">{errors.contract_id.message}</p>}</div>
    <div className="grid gap-4 sm:grid-cols-2"><div><label htmlFor="ct01-authority" className="text-sm font-medium">Cơ quan đăng ký cư trú</label><input id="ct01-authority" className={inputClass} {...form.register('authority_name')} />{errors.authority_name && <p className="text-sm text-red-700">{errors.authority_name.message}</p>}</div><div><label htmlFor="ct01-relationship" className="text-sm font-medium">Quan hệ người kê khai với chủ hộ</label><input id="ct01-relationship" list="ct01-relationships" className={inputClass} {...form.register('relationship_to_household_head')} />{errors.relationship_to_household_head && <p className="text-sm text-red-700">{errors.relationship_to_household_head.message}</p>}</div></div>
    <datalist id="ct01-relationships"><option value="Người thuê" /><option value="Ở nhờ" /><option value="Khác" /></datalist>
    <div><label htmlFor="ct01-request" className="text-sm font-medium">Nội dung đề nghị</label><textarea id="ct01-request" rows={3} className={inputClass} {...form.register('request_content')} /><p className="mt-1 text-xs text-slate-500">Kiểm tra và bổ sung địa chỉ, nội dung đề nghị phù hợp trước khi xuất.</p>{errors.request_content && <p className="text-sm text-red-700">{errors.request_content.message}</p>}</div>
    <fieldset className="min-w-0 space-y-3"><legend className="font-semibold">Người ở cùng khai báo</legend><p className="text-sm text-slate-500">Chỉ hiển thị người đang ở trong phòng; chọn những người cùng khai báo.</p><div className="grid gap-2 sm:grid-cols-2">{tenants.filter(tenant => String(tenant.id) !== declarantId).map(tenant => <label key={tenant.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"><input type="checkbox" className="size-5 shrink-0" checked={members.some(member => member.tenant_id === Number(tenant.id))} onChange={() => toggleMember(tenant)} /><span className="min-w-0 break-words">{tenant.full_name}</span></label>)}</div>
      {members.map((member, index) => {
        const tenant = tenants.find(tenant => Number(tenant.id) === member.tenant_id) || initialPeople.find(tenant => Number(tenant.tenant_id) === member.tenant_id)
        return <div key={member.tenant_id} className="space-y-3 rounded-lg border p-3"><div className="flex flex-wrap items-center justify-between gap-2"><strong className="break-words text-sm">{tenant?.full_name || `Thành viên #${member.tenant_id}`}</strong><button type="button" className="min-h-11 px-2 text-sm text-red-700" onClick={() => form.setValue('members', members.filter((_, current) => current !== index), { shouldDirty: true })}>Bỏ khỏi hồ sơ</button></div><TenantAutofill tenant={tenant} role={context.operational_role} /><label className="block text-sm" htmlFor={`member-relationship-${member.tenant_id}`}>Quan hệ với chủ hộ</label><input id={`member-relationship-${member.tenant_id}`} list="ct01-relationships" className={inputClass} {...form.register(`members.${index}.relationship_to_household_head`)} />{errors.members?.[index] && <p className="text-sm text-red-700">{errors.members[index].relationship_to_household_head?.message || errors.members[index].tenant_id?.message}</p>}</div>
      })}
    </fieldset>
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    <button disabled={isSubmitting} className="min-h-11 w-full rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50 sm:w-auto">{isSubmitting ? 'Đang lưu...' : 'Lưu hồ sơ và xem trước CT01'}</button>
  </form>
}
