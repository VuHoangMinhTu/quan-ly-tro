import { useState } from 'react'
import { X, Zap, Droplet } from 'lucide-react'
import { confirmBillingPeriodBoundaries, correctBillingPeriodBoundaries } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { display, readingError, readingWindowError } from './utilityLedgerDisplay.mjs'
import LedgerDateTime from './LedgerDateTime'

const toLocal = value => String(value || '').replace(' ', 'T').slice(0, 19)
const toApi = value => String(value || '').replace('T', ' ') + (String(value || '').length === 16 ? ':00' : '')
const kind = meter => meter.service?.type === 'ELECTRICITY' ? 'electric' : meter.service?.type === 'WATER' ? 'water' : 'other'
const numeric = value => value !== '' && value != null && /^\d+(?:\.\d{1,2})?$/.test(String(value))
const sameNumber = (a, b) => numeric(a) && numeric(b) && Number(a) === Number(b)

function initialFor(meter, entry) {
  return {
    start: String(entry?.start?.reading_value ?? meter.previous ?? ''),
    end: String(entry?.end?.reading_value ?? ''),
    from: toLocal(entry?.start?.observed_at ?? meter.previousAt ?? meter.cycleStart),
    to: toLocal(entry?.end?.observed_at ?? meter.cycleEnd),
  }
}

export default function UtilityLedgerMonthDialog({ room, periodKey, meters, entries, onClose, onSaved, canWrite }) {
  const [values, setValues] = useState(() => Object.fromEntries(meters.map(meter => {
    const entry = entries.find(row => Number(row.utility_meter_id) === Number(meter.id))
    return [meter.id, initialFor(meter, entry)]
  })))
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState({})
  // A lost response may mean the transaction committed. Retain the exact original request.
  const [retry, setRetry] = useState({})
  const hasRetry = Object.keys(retry).length > 0
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const monthLabel = `${Number(periodKey.slice(5, 7))}/${periodKey.slice(0, 4)}`
  const existingCount = meters.filter(m => entries.some(p => Number(p.utility_meter_id) === Number(m.id))).length
  const status = existingCount === 0 ? 'Ghi chỉ số' : existingCount === meters.length ? 'Đính chính' : 'Bổ sung / đính chính'
  const update = (id, field, value) => {
    setValues(current => ({ ...current, [id]: { ...current[id], [field]: value } }))
    setErrors(current => ({ ...current, [id]: '' }))
    setMessage('')
  }
  const info = meters.map(meter => {
    const entry = entries.find(row => Number(row.utility_meter_id) === Number(meter.id))
    const value = values[meter.id] || initialFor(meter, entry)
    const changed = !entry || !sameNumber(value.start, entry.start?.reading_value) ||
      !sameNumber(value.end, entry.end?.reading_value) ||
      toApi(value.from) !== String(entry.start?.observed_at || '') ||
      toApi(value.to) !== String(entry.end?.observed_at || '')
    const editable = !entry || Boolean(entry.correctable && entry.start && entry.end && entry.start_checkpoint_id && entry.end_checkpoint_id && (!entry.start.kind || entry.start.kind === 'PERIOD_BOUNDARY') && (!entry.end.kind || entry.end.kind === 'PERIOD_BOUNDARY'))
    return { meter, entry, value, changed, editable }
  })
  const changedCorrections = info.some(row => !saved[row.meter.id] && (retry[row.meter.id]?.correction || (row.entry && row.editable && row.changed)))
  async function closeDialog() {
    if (hasRetry) {
      if (!window.confirm('Có thao tác chưa xác định kết quả. Đóng cửa sổ sẽ mất mã thử lại. Cần tải lại sổ và đối soát trước khi ghi tiếp. Bạn muốn đóng?')) return
      try { await onSaved() } catch { /* User must refresh/reconcile before another write. */ }
    }
    onClose()
  }

  async function save(event) {
    event.preventDefault()
    if (busy || !canWrite) return
    setMessage('')
    const pending = info.filter(row => !saved[row.meter.id] && (retry[row.meter.id] || (row.changed && row.editable)))
    if (!pending.length) { setMessage('Không có thay đổi cần lưu.'); return }
    const formErrors = {}
    for (const { meter, entry, value } of pending) {
      if (retry[meter.id]) continue // Retry the exact previously validated request.
      if (!numeric(value.start) || !numeric(value.end)) formErrors[meter.id] = 'Nhập đủ chỉ số đầu và cuối (tối đa 2 số thập phân).'
      else if (readingError(value.end, value.start)) formErrors[meter.id] = readingError(value.end, value.start)
      else if (readingWindowError(value.from, value.to)) formErrors[meter.id] = readingWindowError(value.from, value.to)
      else if (!entry && (!meter.previousAt || meter.needsOpening || meter.needsContract)) formErrors[meter.id] = 'Thiếu mốc đầu kỳ đã xác nhận theo hợp đồng. Không được lấy chỉ số 0 hoặc ngày 01 làm mặc định.'
      else if (!entry && !sameNumber(value.start, meter.previous)) formErrors[meter.id] = 'Chỉ số đầu kỳ phải khớp mốc đã xác nhận. Muốn sửa mốc cũ, dùng chức năng đính chính.'
      else if (entry && (!entry.start_checkpoint_id || !entry.end_checkpoint_id)) formErrors[meter.id] = 'Kỳ đã lưu thiếu liên kết hai mốc; cần đối soát trước khi đính chính.'
    }
    if (pending.some(row => row.entry && !retry[row.meter.id]) && reason.trim().length < 5) {
      setMessage('Đính chính phải có lý do ít nhất 5 ký tự.')
      return
    }
    if (Object.keys(formErrors).length) { setErrors(current => ({ ...current, ...formErrors })); return }
    setBusy(true)
    const succeeded = []
    const failed = {}
    // Existing V2 API commits each meter separately. Remember successful items so a retry
    // never sends a second correction for a meter that was already saved in this popup.
    for (const { meter, entry, value } of pending) {
      const request = retry[meter.id] || (entry ? { correction: true, payload: {
        start_checkpoint_id: entry.start_checkpoint_id,
        end_checkpoint_id: entry.end_checkpoint_id,
        start_observed_at: toApi(value.from), end_observed_at: toApi(value.to),
        start_reading_value: String(value.start), end_reading_value: String(value.end),
        reason: reason.trim(), request_key: crypto.randomUUID(),
      }} : { correction: false, payload: {
        billing_period: periodKey,
        start_observed_at: toApi(value.from), end_observed_at: toApi(value.to),
        start_reading_value: String(value.start), end_reading_value: String(value.end),
      }})
      try {
        if (request.correction) await correctBillingPeriodBoundaries(meter.id, request.payload)
        else await confirmBillingPeriodBoundaries(meter.id, request.payload)
        succeeded.push(meter.id)
        setSaved(current => ({ ...current, [meter.id]: true }))
        setRetry(current => { const next = { ...current }; delete next[meter.id]; return next })
        setErrors(current => ({ ...current, [meter.id]: '' }))
      } catch (error) {
        if (error.billingV2Message) setRetry(current => ({ ...current, [meter.id]: request }))
        failed[meter.id] = error.billingV2Message || getApiErrorMessage(error) || error.message || 'Không lưu được công tơ này.'
        setErrors(current => ({ ...current, [meter.id]: failed[meter.id] }))
      }
    }
    if (succeeded.length) {
      try { await onSaved() } catch { /* A successful DB save is not rolled back by a refresh failure. */ }
    }
    setBusy(false)
    if (!Object.keys(failed).length) {
      onClose()
    } else {
      setMessage(`Đã lưu ${succeeded.length} công tơ; còn ${Object.keys(failed).length} công tơ lỗi. Chỉ thử lại mục lỗi, không cần ghi lại mục đã lưu.`)
    }
  }

  return <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-2 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="utility-month-dialog-title">
    <form onSubmit={save} className="max-h-[96dvh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-4 shadow-xl sm:p-6">
      <div className="flex items-start justify-between gap-3">
        <div><p className="text-sm font-medium text-teal-700">Phòng {room.room_code} · Kỳ {monthLabel}</p>
          <h2 id="utility-month-dialog-title" className="mt-1 text-xl font-semibold">{status} điện nước</h2>
          <p className="mt-1 text-sm text-slate-600">Điện và nước cùng một cửa sổ, giữ riêng mốc và lịch sử từng đồng hồ.</p>
        </div>
        <button type="button" disabled={busy} aria-label="Đóng" onClick={closeDialog} className="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-slate-100"><X size={20}/></button>
      </div>
      <div className="mt-5 grid gap-3 sm:grid-cols-2">
        {info.map(({ meter, entry, value, editable }) => {
          const water = kind(meter) === 'water'
          return <fieldset key={meter.id} disabled={busy || !canWrite || saved[meter.id] || !editable || Boolean(retry[meter.id])} className="min-w-0 space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className={`flex items-center gap-1 font-semibold ${water ? 'text-sky-800' : 'text-amber-800'}`}>{water ? <Droplet size={17}/> : <Zap size={17}/>} {meter.service?.name || (water ? 'Nước' : 'Điện')}</h3>
              <span className="text-xs text-slate-600">{saved[meter.id] ? 'Đã lưu' : entry ? 'Đã ghi' : 'Chưa ghi'}</span>
            </div>
            <p className="text-xs text-slate-600">Đồng hồ #{meter.id} · {meter.service?.unit || ''}</p>
            <label className="block text-sm font-medium">Chỉ số đầu kỳ
              <input type="number" inputMode="decimal" min="0" step="0.01" required readOnly={!entry}
                className={`mt-1 w-full rounded-lg border p-2.5 ${entry ? 'bg-white' : 'bg-slate-100 text-slate-700'}`}
                value={value.start} onChange={e => update(meter.id, 'start', e.target.value)}/>
            </label>
            <label className="block text-sm font-medium">Chỉ số cuối kỳ
              <input type="number" inputMode="decimal" min="0" step="0.01" required
                className="mt-1 w-full rounded-lg border bg-white p-2.5" value={value.end} onChange={e => update(meter.id, 'end', e.target.value)}/>
            </label>
            <p className="text-sm font-medium">Tiêu thụ: {numeric(value.start) && numeric(value.end) && Number(value.end) >= Number(value.start) ? `${display(Number(value.end) - Number(value.start))} ${meter.service?.unit || ''}` : '—'}</p>
            <LedgerDateTime label="Ngày đầu kỳ" required value={value.from} onValueChange={v => update(meter.id, 'from', v)}/>
            <LedgerDateTime label="Ngày cuối kỳ" required value={value.to} onValueChange={v => update(meter.id, 'to', v)}/>
            {!entry && !meter.previousAt && <p role="alert" className="text-xs text-amber-800">Chưa có mốc đầu kỳ đã xác nhận. Cần xác nhận bàn giao hoặc mốc kỳ trước.</p>}
            {!editable && !retry[meter.id] && <p role="alert" className="text-xs text-amber-800">Mốc không đủ điều kiện đính chính. Cần đối soát lịch sử công tơ.</p>}
            {retry[meter.id] && <p role="alert" className="text-xs text-amber-800">Chưa xác định kết quả. Lưu lại để gửi đúng yêu cầu cũ; không sửa nội dung khi đang chờ đối soát.</p>}
            {errors[meter.id] && <p role="alert" className="rounded-lg bg-red-50 p-2 text-sm text-red-800">{errors[meter.id]}</p>}
          </fieldset>
        })}
      </div>
      {changedCorrections && <label className="mt-4 block text-sm font-medium">Lý do đính chính (áp dụng cho các đồng hồ được sửa)
        <textarea disabled={busy || hasRetry} required minLength={5} maxLength={1000} rows={2} className="mt-1 block w-full rounded-lg border p-3" value={reason} onChange={e => setReason(e.target.value)} placeholder="Ví dụ: Đối chiếu biên bản bàn giao, cập nhật ngày đầu kỳ đúng hợp đồng"/>
      </label>}
      {message && <p role="status" className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{message}</p>}
      <p className="mt-4 text-xs text-slate-500">Bấm Lưu một lần để gửi tất cả mục thay đổi. Backend xác nhận từng công tơ riêng; nếu một mục lỗi, popup giữ kết quả để thử lại mà không ghi trùng mục đã thành công. Không tự sửa dữ liệu hóa đơn.</p>
      <div className="sticky bottom-0 mt-4 flex justify-end gap-2 border-t bg-white pt-3">
        <button type="button" disabled={busy} onClick={closeDialog} className="min-h-11 rounded-lg border px-4">Đóng</button>
        <button type="submit" disabled={busy || !canWrite} className="min-h-11 rounded-lg bg-teal-700 px-5 font-semibold text-white disabled:opacity-50">{busy ? 'Đang lưu…' : 'Lưu điện và nước'}</button>
      </div>
    </form>
  </div>
}
