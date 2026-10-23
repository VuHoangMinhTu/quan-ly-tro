import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { disconnectPayos, getPaymentProfile, getPayosConfig, savePaymentProfile, savePayosConfig } from '../../api/paymentSettingsApi'

const fieldClass = 'mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2'

export default function PaymentSettingsPage() {
  const cache = useQueryClient()
  const profileQuery = useQuery({ queryKey: ['landlord', 'payment-profile'], queryFn: getPaymentProfile })
  const payosQuery = useQuery({ queryKey: ['landlord', 'payos-config'], queryFn: getPayosConfig })
  const [profile, setProfile] = useState(null)
  const shownProfile = profile ?? profileQuery.data?.data?.data ?? { bank_name: '', bank_account_name: '', bank_account_number: '' }
  const [credentials, setCredentials] = useState({ client_id: '', api_key: '', checksum_key: '' })
  const [notice, setNotice] = useState('')
  const profileMutation = useMutation({ mutationFn: savePaymentProfile, onSuccess: () => { cache.invalidateQueries({ queryKey: ['landlord', 'payment-profile'] }); setProfile(null); setNotice('Đã lưu tài khoản nhận tiền.') } })
  const payosMutation = useMutation({ mutationFn: savePayosConfig, onSuccess: () => { cache.invalidateQueries({ queryKey: ['landlord', 'payos-config'] }); setCredentials({ client_id: '', api_key: '', checksum_key: '' }); setNotice('Đã lưu PayOS. Quản trị viên cần xác nhận webhook trước khi nhận thanh toán.') } })
  const disconnectMutation = useMutation({ mutationFn: disconnectPayos, onSuccess: () => { cache.invalidateQueries({ queryKey: ['landlord', 'payos-config'] }); setNotice('Đã ngắt kết nối PayOS.') } })
  const payos = payosQuery.data?.data?.data
  const error = profileQuery.error || payosQuery.error || profileMutation.error || payosMutation.error || disconnectMutation.error

  return <section className="mx-auto max-w-3xl space-y-5">
    <h2 className="text-2xl font-bold">Cài đặt thanh toán</h2>
    {notice && <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-800">{notice}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{getApiErrorMessage(error)}</p>}
    <form onSubmit={(event) => { event.preventDefault(); profileMutation.mutate(shownProfile) }} className="space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
      <div><h3 className="text-lg font-semibold">Tài khoản nhận tiền</h3><p className="text-sm text-slate-600">Hiển thị trong hợp đồng và dùng cho chuyển khoản thủ công; không phải credentials PayOS.</p></div>
      {[["bank_name", "Ngân hàng"], ["bank_account_name", "Tên chủ tài khoản"], ["bank_account_number", "Số tài khoản"]].map(([key, label]) => <label key={key} className="block text-sm font-medium">{label}<input className={fieldClass} value={shownProfile[key] ?? ''} onChange={(event) => setProfile({ ...shownProfile, [key]: event.target.value })} autoComplete="off" /></label>)}
      <button disabled={profileMutation.isPending} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white disabled:opacity-50">Lưu thông tin</button>
    </form>
    <form onSubmit={(event) => { event.preventDefault(); payosMutation.mutate({ ...Object.fromEntries(Object.entries(credentials).filter(([, value]) => value.trim() !== '')), is_active: true }) }} className="space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
      <div><h3 className="text-lg font-semibold">PayOS</h3><p className="text-sm text-slate-600">{payos?.is_active ? 'Đã cấu hình' : 'Chưa cấu hình hoặc đã ngắt kết nối'}</p></div>
      {payos?.webhook_url && <p className="break-all rounded-lg bg-slate-50 p-3 text-xs">Webhook URL: {payos.webhook_url}</p>}
      <p className="text-xs text-slate-500">API Key và Checksum Key đã lưu không được hiển thị lại. Để trống khi cập nhật nếu muốn giữ giá trị cũ.</p>
      {[["client_id", "Client ID"], ["api_key", "API Key"], ["checksum_key", "Checksum Key"]].map(([key, label]) => <label key={key} className="block text-sm font-medium">{label}{key === 'client_id' && payos?.client_id_masked ? ` · hiện tại ${payos.client_id_masked}` : key !== 'client_id' && payos?.configured ? ' · ••••••••' : ''}<input className={fieldClass} type={key === 'client_id' ? 'text' : 'password'} value={credentials[key]} onChange={(event) => setCredentials({ ...credentials, [key]: event.target.value })} autoComplete="new-password" required={!payos?.configured} /></label>)}
      <div className="flex flex-wrap gap-3"><button disabled={payosMutation.isPending} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white disabled:opacity-50">{payos?.configured && !payos.is_active ? 'Kết nối lại PayOS' : 'Lưu cấu hình PayOS'}</button>{payos?.is_active && <button type="button" disabled={disconnectMutation.isPending} onClick={() => window.confirm('Ngắt kết nối PayOS?') && disconnectMutation.mutate()} className="min-h-11 rounded-lg border border-red-300 px-4 text-red-700">Ngắt kết nối</button>}</div>
    </form>
  </section>
}
