import { useState } from 'react'
import { Link } from 'react-router-dom'
import { correctBillingPeriodBoundaries } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { display, formatTime, readingError, usageStatus } from './utilityLedgerDisplay.mjs'

const localInput = value => value.replace(' ', 'T')
const apiInput = value => value.replace('T', ' ') + (value.length === 16 ? ':00' : '')

function Correction({ row, onClose, onSaved }) {
  const [start, setStart] = useState(row.start.reading_value)
  const [end, setEnd] = useState(row.end.reading_value)
  const [from, setFrom] = useState(localInput(row.start.observed_at))
  const [to, setTo] = useState(localInput(row.end.observed_at))
  const [reason, setReason] = useState('')
  const [requestKey, setRequestKey] = useState(() => crypto.randomUUID())
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const validation = readingError(end, start)
  const change = setter => event => {
    setter(event.target.value)
    setRequestKey(crypto.randomUUID())
    setError('')
  }
  async function save(event) {
    event.preventDefault()
    if (busy || validation || start === '' || end === '') return
    setBusy(true)
    try {
      await correctBillingPeriodBoundaries(row.utility_meter_id, {
        start_checkpoint_id: row.start_checkpoint_id, end_checkpoint_id: row.end_checkpoint_id,
        start_reading_value: start, end_reading_value: end,
        start_observed_at: apiInput(from), end_observed_at: apiInput(to),
        reason, request_key: requestKey,
      })
      await onSaved()
      onClose()
    } catch (error) {
      setError(getApiErrorMessage(error))
    } finally {
      setBusy(false)
    }
  }
  return <div role="dialog" aria-modal="true" aria-labelledby="correction-title" className="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 p-3">
    <form onSubmit={save} className="max-h-[92dvh] w-full max-w-lg space-y-4 overflow-auto rounded-2xl bg-white p-5">
      <h2 id="correction-title" className="text-xl font-bold">Đính chính {row.meter.service?.name}</h2>
      <fieldset disabled={busy} className="grid gap-3 sm:grid-cols-2">
        <label>Chỉ số đầu kỳ<input required type="number" min="0" step="0.01" inputMode="decimal" className="block w-full rounded border p-2" value={start} onChange={change(setStart)} /></label>
        <label>Chỉ số cuối kỳ<input required type="number" min="0" step="0.01" inputMode="decimal" aria-invalid={Boolean(validation)} className="block w-full rounded border p-2" value={end} onChange={change(setEnd)} />
          {validation && <span role="alert" className="text-sm text-red-700">{validation}</span>}
        </label>
        <label>Ngày giờ đầu kỳ<input required type="datetime-local" step="1" className="block w-full rounded border p-2" value={from} onChange={change(setFrom)} /></label>
        <label>Ngày giờ cuối kỳ<input required type="datetime-local" step="1" className="block w-full rounded border p-2" value={to} onChange={change(setTo)} /></label>
        <label className="sm:col-span-2">Lý do đính chính<textarea required minLength={5} maxLength={1000} className="block w-full rounded border p-2" value={reason} onChange={change(setReason)} /></label>
      </fieldset>
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <div className="flex justify-end gap-2">
        <button type="button" disabled={busy} onClick={onClose} className="min-h-11 rounded border px-4">Đóng</button>
        <button disabled={busy || Boolean(validation)} className="min-h-11 rounded bg-teal-700 px-4 text-white">Lưu đính chính</button>
      </div>
    </form>
  </div>
}

export default function UtilityLedgerHistory({ rooms, room, selectRoom, year, data, meters, canWrite, onRecord, onSaved }) {
  const [editing, setEditing] = useState(null)
  const meterById = new Map(meters.map(meter => [Number(meter.id), meter]))
  return <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <label className="text-sm font-semibold">Chọn phòng
        <select className="mt-1 block min-h-11 min-w-48 rounded-lg border border-slate-300 px-3" value={room?.id || ''} onChange={event => selectRoom(Number(event.target.value))}>
          <option value="">Chọn phòng</option>
          {rooms.map(item => <option key={item.id} value={item.id}>{item.room_code}</option>)}
        </select>
      </label>
      {room && <><p className="text-sm text-slate-600">{room.tenant || 'Chưa có người thuê'} · Năm {year}</p>
        <button type="button" disabled={!canWrite} onClick={onRecord} className="min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white disabled:opacity-50">Ghi chỉ số</button></>}
    </div>
    {room && <div className="overflow-x-auto">
      <table aria-label="Lịch sử chỉ số 12 tháng" className="w-full min-w-[780px] text-sm tabular-nums">
        <thead className="bg-slate-50 text-left text-slate-700">
          <tr>{['Tháng', 'Điện đầu → cuối', 'kWh', 'Nước đầu → cuối', 'm³', 'Trạng thái', 'Thao tác'].map(label => <th key={label} scope="col" className="p-3">{label}</th>)}</tr>
        </thead>
        <tbody>{Array.from({ length: 12 }, (_, index) => {
          const key = `${year}-${String(index + 1).padStart(2, '0')}-01`
          const entries = data.periods.filter(row => row.billing_period === key && meterById.has(Number(row.utility_meter_id)))
            .map(row => ({ ...row, meter: meterById.get(Number(row.utility_meter_id)) }))
          const invoices = data.invoices.filter(invoice => Number(invoice.room_id) === room.id && invoice.billing_period === key)
          const pair = type => {
            const group = entries.filter(row => row.meter.service?.type === type)
            return <><td className="p-3">{group.length ? group.map(row => <div key={row.utility_meter_id}>
              {row.start && row.end ? `${display(row.start.reading_value)} → ${display(row.end.reading_value)}` : '—'}
            </div>) : '—'}</td><td className="p-3">{group.length ? group.map(row => <div key={row.utility_meter_id}>{display(row.quantity)}</div>) : '—'}</td></>
          }
          return <tr key={key} className="border-t border-slate-100 align-top">
            <th scope="row" className="whitespace-nowrap p-3 text-left">Tháng {index + 1}</th>
            {pair('ELECTRICITY')}{pair('WATER')}
            <td className="p-3">{usageStatus(entries, meters.length)}
              {entries.length > 0 && entries.every(row => row.settled && !row.needsReview) && <p className="text-xs text-slate-600">Đã quyết toán</p>}
              {invoices.map(invoice => <Link key={invoice.invoice_id} className="block text-teal-800 underline" to={`/invoices/${invoice.invoice_id}`}>Hóa đơn #{invoice.invoice_id}</Link>)}
            </td>
            <td className="p-3">{entries.length > 0 && <details>
              <summary className="cursor-pointer font-semibold text-teal-800">Xem chi tiết</summary>
              {entries.map(row => <div className="mt-2 border-t pt-2" key={row.utility_meter_id}>
                <p>{row.meter.service?.name} {row.meter.meter_code}</p>
                {row.start && row.end && <p>{formatTime(row.start.observed_at)} → {formatTime(row.end.observed_at)}</p>}
                {canWrite && row.correctable && <button type="button" className="min-h-11 text-teal-800 underline" onClick={() => setEditing(row)}>Đính chính</button>}
              </div>)}
            </details>}</td>
          </tr>
        })}</tbody>
      </table>
    </div>}
    {editing && <Correction row={editing} onClose={() => setEditing(null)} onSaved={onSaved} />}
  </section>
}
