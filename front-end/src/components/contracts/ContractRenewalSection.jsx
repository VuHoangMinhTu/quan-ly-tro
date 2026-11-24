import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { renewContract } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDate } from '../../utils/formatters'

export default function ContractRenewalSection({ contract }) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [newEndDate, setNewEndDate] = useState('')
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const allowed = contract.operational_role === 'OWNER' && !contract.is_demo &&
    ['ACTIVE', 'EXPIRED'].includes(contract.status) && Boolean(contract.end_date) && !contract.actual_ended_at
  const mutation = useMutation({
    mutationFn: data => renewContract(contract.id, data),
    onSuccess: async () => {
      setError('')
      setSuccess('Đã ghi nhận thời hạn mới. Nếu có phụ lục gia hạn đã ký, hãy lưu cùng hồ sơ hợp đồng.')
      setEditing(false)
      setNewEndDate('')
      setReason('')
      setConfirmed(false)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: contractKeys.detail(String(contract.id)) }),
        queryClient.invalidateQueries({ queryKey: ['contracts'] }),
        queryClient.invalidateQueries({ queryKey: ['billing-v2'] }),
        queryClient.invalidateQueries({ queryKey: ['invoices'] }),
      ])
    },
    onError: failure => setError(getApiErrorMessage(failure)),
  })
  const history = contract.renewal_history || []
  if (!allowed && history.length === 0) return null
  const submit = event => {
    event.preventDefault()
    setError('')
    setSuccess('')
    if (!allowed) return
    if (!newEndDate || newEndDate <= contract.end_date.slice(0, 10)) {
      setError('Ngày kết thúc mới phải sau ngày kết thúc hiện tại.')
      return
    }
    if (reason.trim().length < 10 || !confirmed) {
      setError('Cần ghi lý do (ít nhất 10 ký tự) và xác nhận đã có thỏa thuận gia hạn với người thuê.')
      return
    }
    mutation.mutate({
      expected_end_date: contract.end_date.slice(0, 10),
      new_end_date: newEndDate,
      reason: reason.trim(),
      agreement_confirmed: true,
    })
  }
  return <section id="contract-renewal" className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 className="text-lg font-semibold">Gia hạn hợp đồng</h3>
        <p className="text-sm text-slate-600">Thời hạn hiện tại: <strong>{formatDate(contract.end_date)}</strong>.</p>
        {contract.signing_snapshot && <p className="text-xs text-slate-600">Hợp đồng ký gốc không bị ghi đè. Mỗi lần gia hạn được lưu riêng trong lịch sử.</p>}
      </div>
      {allowed && !editing && <button type="button" className="min-h-11 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white" onClick={() => { setEditing(true); setError(''); setSuccess('') }}>Gia hạn hợp đồng</button>}
    </div>
    {success && <p role="status" className="rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{success}</p>}
    {editing && allowed && <form onSubmit={submit} className="space-y-3 rounded-lg border bg-slate-50 p-3">
      <label className="block text-sm font-medium">Ngày kết thúc mới
        <input required type="date" min={contract.end_date?.slice(0, 10)} value={newEndDate} onChange={event => setNewEndDate(event.target.value)} className="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3" />
      </label>
      <label className="block text-sm font-medium">Lý do / căn cứ gia hạn
        <textarea required minLength={10} maxLength={1000} rows={3} value={reason} onChange={event => setReason(event.target.value)} placeholder="Ví dụ: Hai bên thống nhất gia hạn hợp đồng thêm một tháng" className="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-3" />
      </label>
      <label className="flex items-start gap-2 text-sm"><input required type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} className="mt-1" />Tôi xác nhận đã có thỏa thuận gia hạn với người thuê. Đây là ghi nhận vận hành nội bộ, không thay thế phụ lục/văn bản ký kết.</label>
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <div className="flex flex-wrap gap-2"><button disabled={mutation.isPending || !confirmed} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">{mutation.isPending ? 'Đang lưu...' : 'Xác nhận gia hạn'}</button><button type="button" disabled={mutation.isPending} onClick={() => setEditing(false)} className="min-h-11 rounded-lg border px-4 text-sm">Hủy</button></div>
    </form>}
    {!editing && error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    <div className="space-y-2"><h4 className="font-medium">Lịch sử gia hạn</h4>
      {history.length === 0 ? <p className="text-sm text-slate-500">Chưa có lần gia hạn nào được ghi nhận.</p> :
        <ul className="space-y-2">{history.map(row => <li key={row.id} className="rounded-lg border p-3 text-sm"><p><strong>{formatDate(row.old_end_date)} → {formatDate(row.new_end_date)}</strong></p><p className="mt-1 break-words">{row.reason}</p><p className="mt-1 text-xs text-slate-500">Ghi nhận lúc {row.created_at}</p></li>)}</ul>}
    </div>
    <Link to={`/rooms/${contract.room_id}/invoices/new`} className="inline-flex min-h-11 items-center text-sm font-medium text-teal-800 underline">Quay lại tạo hóa đơn</Link>
  </section>
}
