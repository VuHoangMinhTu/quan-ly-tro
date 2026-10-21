import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { getBoardingHouseManagers, getResidenceProfile, residenceKeys, updateBoardingHouseManagers, updateResidenceProfile } from '../../api/residenceApi'

const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 p-2'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50'

function ProfileForm({ houseId, initial }) {
  const client = useQueryClient()
  const form = useForm({ defaultValues: Object.fromEntries(['household_head_name', 'household_head_identity_number', 'residence_authority_name', 'document_place', 'legal_owner_name', 'legal_owner_birth_year', 'legal_owner_permanent_address', 'legal_owner_identity_number', 'legal_owner_phone'].map(field => [field, initial?.[field] ?? ''])) })
  const [message, setMessage] = useState('')
  const save = useMutation({ mutationFn: data => updateResidenceProfile(houseId, data), onSuccess: async () => {
    setMessage('Đã lưu thông tin chủ hộ.')
    await Promise.all([client.invalidateQueries({ queryKey: residenceKeys.profile(houseId) }), client.invalidateQueries({ queryKey: ['residence-context'] }), client.invalidateQueries({ queryKey: ['residence-declaration'] })])
  } })
  return <form onSubmit={form.handleSubmit(data => { setMessage(''); save.mutate(Object.fromEntries(Object.entries(data).map(([field, value]) => [field, field === 'legal_owner_birth_year' ? (value === '' ? null : Number(value)) : String(value).trim() || null]))) })} className="space-y-3">
    <h3 className="font-semibold">Thông tin cư trú & chủ sở hữu</h3><p className="text-sm text-slate-500">Chỉ nhập thông tin đã được xác nhận. BeeHouse không mặc định OWNER/MANAGER là chủ hộ hoặc chủ sở hữu.</p>
    <div><label htmlFor="residence-authority" className="text-sm">Cơ quan tiếp nhận CT01 mặc định</label><input id="residence-authority" maxLength={255} className={inputClass} {...form.register('residence_authority_name')} /></div>
    <div><label htmlFor="residence-document-place" className="text-sm">Địa danh lập văn bản ở nhờ</label><input id="residence-document-place" maxLength={255} placeholder="VD: Vườn Lài" className={inputClass} {...form.register('document_place')} /><p className="mt-1 text-xs text-slate-500">Địa danh ở đầu văn bản, không phải địa chỉ chỗ ở nhờ.</p></div>
    <div><label htmlFor="residence-head" className="text-sm">Họ tên chủ hộ</label><input id="residence-head" maxLength={255} className={inputClass} {...form.register('household_head_name')} /></div>
    <div><label htmlFor="residence-head-id" className="text-sm">Số định danh cá nhân của chủ hộ</label><input id="residence-head-id" maxLength={30} className={inputClass} {...form.register('household_head_identity_number')} /></div>
    <p className="pt-2 text-sm font-semibold">Bên cho ở nhờ (chủ sở hữu hợp pháp nếu đã xác nhận)</p>
    <div className="grid gap-3 sm:grid-cols-2"><div><label htmlFor="legal-owner-name" className="text-sm">Họ tên</label><input id="legal-owner-name" maxLength={255} className={inputClass} {...form.register('legal_owner_name')} /></div><div><label htmlFor="legal-owner-year" className="text-sm">Năm sinh</label><input id="legal-owner-year" type="number" min="1900" max="2100" className={inputClass} {...form.register('legal_owner_birth_year')} /></div></div>
    <div><label htmlFor="legal-owner-address" className="text-sm">Địa chỉ thường trú</label><input id="legal-owner-address" maxLength={500} className={inputClass} {...form.register('legal_owner_permanent_address')} /></div>
    <div className="grid gap-3 sm:grid-cols-2"><div><label htmlFor="legal-owner-id" className="text-sm">Số định danh cá nhân</label><input id="legal-owner-id" maxLength={30} className={inputClass} {...form.register('legal_owner_identity_number')} /></div><div><label htmlFor="legal-owner-phone" className="text-sm">Số điện thoại</label><input id="legal-owner-phone" maxLength={20} className={inputClass} {...form.register('legal_owner_phone')} /></div></div>
    {save.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(save.error)}</p>}{message && <p role="status" className="text-sm text-emerald-700">{message}</p>}
    <button disabled={save.isPending} className={buttonClass}>{save.isPending ? 'Đang lưu...' : 'Lưu thông tin chủ hộ'}</button>
  </form>
}

function ManagersForm({ houseId, initial }) {
  const client = useQueryClient()
  const [emails, setEmails] = useState(initial.map(user => user.email).join('\n'))
  const [message, setMessage] = useState('')
  const save = useMutation({ mutationFn: data => updateBoardingHouseManagers(houseId, data), onSuccess: async () => {
    setMessage('Đã cập nhật phân công quản lý.')
    await Promise.all([client.invalidateQueries({ queryKey: residenceKeys.managers(houseId) }), client.invalidateQueries({ queryKey: residenceKeys.houses })])
  } })
  return <form onSubmit={event => { event.preventDefault(); setMessage(''); save.mutate({ manager_emails: [...new Set(emails.split(/[,;\n]/).map(email => email.trim().toLowerCase()).filter(Boolean))] }) }} className="space-y-3">
    <h3 className="font-semibold">Phân công MANAGER</h3><p className="text-sm text-slate-500">Nhập email tài khoản BeeHouse đã đăng ký, mỗi email một dòng. Quản lý được thao tác hợp đồng và hồ sơ cư trú của nhà trọ được phân công. Để trống rồi lưu để thu hồi tất cả phân công.</p>
    <label htmlFor="manager-emails" className="block text-sm">Email quản lý</label><textarea id="manager-emails" rows={3} className={inputClass} value={emails} onChange={event => setEmails(event.target.value)} />
    {save.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(save.error)}</p>}{message && <p role="status" className="text-sm text-emerald-700">{message}</p>}
    <button disabled={save.isPending} className={buttonClass}>{save.isPending ? 'Đang lưu...' : 'Lưu phân công'}</button>
  </form>
}

export default function ResidenceHouseSettings({ houseId, role = 'OWNER' }) {
  const profile = useQuery({ queryKey: residenceKeys.profile(houseId), queryFn: () => getResidenceProfile(houseId) })
  const managers = useQuery({ queryKey: residenceKeys.managers(houseId), queryFn: () => getBoardingHouseManagers(houseId), enabled: role === 'OWNER' })
  return <div className="grid min-w-0 gap-5 rounded-xl bg-white p-4 shadow-sm sm:p-6 lg:grid-cols-2">
    {profile.isPending ? <p>Đang tải thông tin chủ hộ...</p> : profile.isError ? <p className="text-sm text-red-700">{getApiErrorMessage(profile.error)}</p> : <ProfileForm key={`profile-${houseId}`} houseId={houseId} initial={profile.data.data.data} />}
    {role === 'OWNER' && (managers.isPending ? <p>Đang tải phân công...</p> : managers.isError ? <p className="text-sm text-red-700">{getApiErrorMessage(managers.error)}</p> : <ManagersForm key={`managers-${houseId}`} houseId={houseId} initial={managers.data.data.data} />)}
  </div>
}
