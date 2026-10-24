import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { disconnectPayos, getPaymentProfile, getPayosConfig, savePaymentProfile, savePayosConfig } from '../../api/paymentSettingsApi'
import PrivatePaymentQr, { LocalQrImage } from '../../components/payments/PrivatePaymentQr'

const fieldClass = 'mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2'

export default function PaymentSettingsPage() {
  const cache = useQueryClient()
  const ownerId = String(cache.getQueryData(['me'])?.data?.data?.landlord?.id ?? '')
  const profileKey = ['landlord', ownerId, 'payment-profile']
  const payosKey = ['landlord', ownerId, 'payos-config']
  const profileQuery = useQuery({ queryKey: profileKey, queryFn: getPaymentProfile })
  const payosQuery = useQuery({ queryKey: payosKey, queryFn: getPayosConfig })
  const [profile, setProfile] = useState(null)
  const [qrFile, setQrFile] = useState(null)
  const [removeQr, setRemoveQr] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const shownProfile = profile ?? profileQuery.data?.data?.data ?? { bank_name: '', bank_account_name: '', bank_account_number: '' }
  const [credentials, setCredentials] = useState({ client_id: '', api_key: '', checksum_key: '' })
  const [notice, setNotice] = useState('')
  const profileMutation = useMutation({ mutationFn: savePaymentProfile, onSuccess: response => {
    cache.setQueryData(profileKey, response)
    cache.invalidateQueries({ queryKey: ['invoice'] })
    setProfile(null); setQrFile(null); setRemoveQr(false); setUploadError(''); setNotice('Đã lưu thông tin nhận thanh toán.')
  } })
  const payosMutation = useMutation({ mutationFn: savePayosConfig, onSuccess: () => { cache.invalidateQueries({ queryKey: payosKey }); setCredentials({ client_id: '', api_key: '', checksum_key: '' }); setNotice('Đã lưu PayOS. Quản trị viên cần xác nhận webhook trước khi nhận thanh toán.') } })
  const disconnectMutation = useMutation({ mutationFn: disconnectPayos, onSuccess: () => { cache.invalidateQueries({ queryKey: payosKey }); setNotice('Đã ngắt kết nối PayOS.') } })
  const payos = payosQuery.data?.data?.data
  const error = profileQuery.error || payosQuery.error || profileMutation.error || payosMutation.error || disconnectMutation.error
  const submitProfile = event => {
    event.preventDefault()
    if (profileQuery.isPending || profileQuery.isError || uploadError) return
    const data = new FormData()
    for (const field of ['bank_name', 'bank_account_name', 'bank_account_number']) data.set(field, shownProfile[field] ?? '')
    if (qrFile) data.set('payment_qr', qrFile)
    if (removeQr) data.set('remove_payment_qr', '1')
    setNotice('')
    profileMutation.mutate(data)
  }

  return <section className="mx-auto max-w-3xl space-y-5">
    <h2 className="text-2xl font-bold">Cài đặt thanh toán</h2>
    {notice && <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-800">{notice}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{getApiErrorMessage(error)}</p>}
    <form onSubmit={submitProfile} className="space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6">
      <div><h3 className="text-lg font-semibold">Thông tin nhận thanh toán</h3><p className="text-sm text-slate-600">Tài khoản ngân hàng và QR chuyển khoản thủ công của bạn.</p></div>
      {!profileQuery.isPending && !shownProfile.bank_name && !shownProfile.bank_account_name && !shownProfile.bank_account_number && !shownProfile.has_payment_qr && <p className="text-sm text-slate-500">Chưa thiết lập thông tin nhận thanh toán.</p>}
      {[["bank_name", "Ngân hàng"], ["bank_account_name", "Tên chủ tài khoản"], ["bank_account_number", "Số tài khoản"]].map(([key, label]) => <label key={key} className="block text-sm font-medium">{label}<input disabled={profileQuery.isPending || profileMutation.isPending} className={fieldClass} value={shownProfile[key] ?? ''} onChange={(event) => setProfile({ ...shownProfile, [key]: event.target.value })} autoComplete="off" /></label>)}
      <div className="space-y-3"><h4 className="text-sm font-medium">QR thanh toán</h4>
        {qrFile ? <LocalQrImage key={`${qrFile.name}-${qrFile.lastModified}`} blob={qrFile} alt="QR mới đã chọn" className="h-48 w-48 max-w-full rounded-lg border bg-white p-2 object-contain" /> : !removeQr && shownProfile.has_payment_qr && <PrivatePaymentQr profile={shownProfile} className="h-48 w-48 max-w-full rounded-lg border bg-white p-2 object-contain" />}
        <div className="flex flex-wrap items-center gap-3"><label className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border px-4 text-sm">{qrFile || shownProfile.has_payment_qr ? 'Thay ảnh QR' : 'Upload ảnh QR'}<input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" disabled={profileQuery.isPending || profileMutation.isPending} onChange={event => {
          const file = event.target.files?.[0]
          event.target.value = ''
          if (!file) return
          if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { setUploadError('Vui lòng chọn ảnh PNG, JPG hoặc WebP, tối đa 5 MB.'); return }
          setUploadError(''); setQrFile(file); setRemoveQr(false)
        }} /></label>{(qrFile || (shownProfile.has_payment_qr && !removeQr)) && <button type="button" disabled={profileMutation.isPending} onClick={() => { setQrFile(null); setRemoveQr(true); setUploadError('') }} className="min-h-11 rounded-lg border border-red-200 px-4 text-sm text-red-700">Xóa QR</button>}</div>
        <p className="text-xs text-slate-500">PNG, JPG hoặc WebP, tối đa 5 MB. Thay đổi có hiệu lực sau khi lưu.</p>
        {removeQr && <p className="text-sm text-slate-600">QR sẽ được xóa khỏi cài đặt sau khi lưu. Hóa đơn đã phát hành vẫn giữ QR đã chốt.</p>}
        {uploadError && <p role="alert" className="text-sm text-red-600">{uploadError} <button type="button" onClick={() => setUploadError('')} className="min-h-11 underline">Bỏ qua ảnh này</button></p>}
      </div>
      <button disabled={profileQuery.isPending || profileQuery.isError || profileMutation.isPending || Boolean(uploadError)} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white disabled:opacity-50">{profileMutation.isPending ? 'Đang lưu...' : 'Lưu thông tin'}</button>
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
