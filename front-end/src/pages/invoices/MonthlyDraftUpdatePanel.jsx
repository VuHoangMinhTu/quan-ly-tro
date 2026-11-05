import { useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { previewMonthlyDraft, confirmMonthlyDraft } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'

const suggestedEndForPeriod = period => {
  const [year, month] = period.slice(0, 7).split('-').map(Number)
  const end = new Date(year, month, 1)
  return `${end.getFullYear()}-${String(end.getMonth() + 1).padStart(2, '0')}-${String(end.getDate()).padStart(2, '0')}T00:00`
}

const defaultReason = 'Cập nhật hóa đơn theo số liệu quyết toán mới'

export default function MonthlyDraftUpdatePanel({ roomId, contractId, period, confirmations = [], initialUsageStartAt, initialUsageEndAt, onUpdated }) {
  const [usageWindow, setUsageWindow] = useState(() => ({ start: initialUsageStartAt?.replace(' ', 'T').slice(0, 16) || `${period.slice(0, 7)}-01T00:00`, end: initialUsageEndAt?.replace(' ', 'T').slice(0, 16) || suggestedEndForPeriod(period) }))
  const [checkedStart, setCheckedStart] = useState(Boolean(initialUsageStartAt))
  const [checkedEnd, setCheckedEnd] = useState(Boolean(initialUsageEndAt))
  const periodConfirmed = checkedStart && checkedEnd
  const [pendingPreview, setPendingPreview] = useState(null)
  const [result, setResult] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const locked = useRef(false)
  const client = useQueryClient()

  const apply = async (data) => {
    const response = await confirmMonthlyDraft(roomId, data.token, defaultReason)
    const updated = response.data.data
    await client.invalidateQueries({ queryKey: ['invoices'] })
    for (const item of updated.after?.invoices || []) await client.invalidateQueries({ queryKey: ['invoice', String(item.id)] })
    onUpdated?.(updated.after?.invoices || [])
    const total = (updated.after?.invoices || []).reduce((sum, invoice) => sum + Number(invoice.total_amount || 0), 0)
    setResult(`Đã cập nhật hóa đơn nháp. Tổng tiền mới: ${formatCurrency(total)}.`)
    setPendingPreview(null)
  }

  const run = async (mode = 'update') => {
    if (locked.current) return
    if (!periodConfirmed) { setError('Hóa đơn chưa lưu kỳ sử dụng. Vui lòng chọn lại cả ngày bắt đầu và kết thúc trước khi cập nhật.'); return }
    locked.current = true
    setBusy(true)
    setError('')
    setResult('')
    try {
      if (mode === 'apply' && pendingPreview) {
        await apply(pendingPreview)
      } else {
        const response = await previewMonthlyDraft(roomId, {
          billing_period: period,
          ...(contractId ? { contract_id: contractId } : {}),
          usage_start_at: `${usageWindow.start.replace('T', ' ')}:00`,
          usage_end_at: `${usageWindow.end.replace('T', ' ')}:00`,
          confirmations,
        })
        const data = response.data.data
        if (data.status !== 'PREVIEWED') {
          const messages = Object.values(data.errors || {}).flat().filter(Boolean)
          setError(messages.join(' ') || 'Chưa thể cập nhật. Vui lòng kiểm tra dữ liệu quyết toán điện nước.')
        } else if ((data.after?.invoices || []).length === 0) {
          setError('Không tìm thấy hóa đơn nháp cần cập nhật.')
        } else if ((data.after?.invoices || []).length === 1) {
          // One draft only: safe to apply automatically after backend preview checks.
          await apply(data)
        } else {
          // Shared meters can affect multiple draft invoices. Show scope before applying.
          setPendingPreview(data)
        }
      }
    } catch (e) {
      setError(e.billingV2Message || getApiErrorMessage(e))
    } finally {
      locked.current = false
      setBusy(false)
    }
  }

  return <section className="mb-4 rounded-xl border bg-white p-4 print-hide" aria-label="Cập nhật hóa đơn nháp">
    <h3 className="font-semibold">Cập nhật hóa đơn</h3>
    <p className="mt-1 text-sm text-slate-600">Khi có số điện, nước mới được quyết toán, bạn có thể tính lại hóa đơn nháp. Các khoản nhập thêm vẫn được giữ nguyên.</p>
    <button type="button" disabled={busy || !periodConfirmed} onClick={() => run()} className="mt-3 min-h-11 rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Đang cập nhật...' : 'Cập nhật hóa đơn'}</button>
    {!periodConfirmed && <p role="alert" className="mt-2 text-sm text-amber-800">Chưa có kỳ sử dụng đã lưu. Hãy mở phần điều chỉnh và chọn lại ngày bắt đầu, kết thúc trước khi cập nhật.</p>}
    <details className="mt-3 text-sm text-slate-600">
      <summary className="cursor-pointer">Điều chỉnh kỳ điện nước</summary>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <label className="block">Bắt đầu sử dụng · giờ Việt Nam<input type="datetime-local" required value={usageWindow.start} disabled={busy} onChange={event => { setPendingPreview(null); setCheckedStart(Boolean(event.target.value)); setUsageWindow(previous => ({ ...previous, start: event.target.value })) }} className="mt-1 block min-h-11 w-full rounded border p-2" /></label>
        <label className="block">Kết thúc sử dụng · giờ Việt Nam<input type="datetime-local" required value={usageWindow.end} disabled={busy} onChange={event => { setPendingPreview(null); setCheckedEnd(Boolean(event.target.value)); setUsageWindow(previous => ({ ...previous, end: event.target.value })) }} className="mt-1 block min-h-11 w-full rounded border p-2" /></label>
      </div>
    </details>
    {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
    {result && <p role="status" className="mt-3 text-sm text-emerald-800">{result}</p>}
    {pendingPreview && <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
      <p className="font-medium">Kỳ công tơ này liên quan đến {pendingPreview.after.invoices.length} hóa đơn.</p>
      <p className="mt-1">Để tránh thay đổi nhầm tiền của người thuê khác, vui lòng xem tổng tiền mới trước khi áp dụng:</p>
      <ul className="mt-2 space-y-1">{pendingPreview.after.invoices.map(item => <li key={item.id}>{item.invoice_code}: {formatCurrency(item.total_amount)}</li>)}</ul>
      <button type="button" disabled={busy} onClick={() => run('apply')} className="mt-3 min-h-11 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Áp dụng cho {pendingPreview.after.invoices.length} hóa đơn nháp</button>
    </div>}
  </section>
}
