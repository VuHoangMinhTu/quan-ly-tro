import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { display, usageStatus } from './utilityLedgerDisplay.mjs'
import UtilityLedgerMonthDialog from './UtilityLedgerMonthDialog'

const monthKey = (year, month) => `${year}-${String(month).padStart(2, '0')}-01`
const byMeter = (rows, meter) => rows.find(row => Number(row.utility_meter_id) === Number(meter.id))
const formatPair = (item, unit) => item?.start && item?.end
  ? <><strong>{display(item.start.reading_value)} → {display(item.end.reading_value)}</strong><span className="block text-xs text-slate-500">{display(item.quantity)} {unit}</span></>
  : <span className="text-slate-400">—</span>

export default function UtilityLedgerHistory({ rooms, room, selectRoom, year, data, meters, canWrite, onSaved, recordMonth, onMonthChange, actionRequest, clearActionRequest }) {
  const [editingMonth, setEditingMonth] = useState('')
  const selectedRow = useRef(null)
  const selectedPeriod = recordMonth ? `${recordMonth}-01` : ''

  useEffect(() => {
    if (!room?.id || !selectedRow.current) return
    selectedRow.current.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [room?.id, selectedPeriod])

  useEffect(() => {
    if (!actionRequest || !room?.id || String(actionRequest.roomId) !== String(room.id)) return
    if (actionRequest.key) setEditingMonth(actionRequest.key)
    clearActionRequest?.()
  }, [actionRequest, room?.id, clearActionRequest])

  const open = (key) => {
    const month = key.slice(0, 7)
    if (month !== recordMonth) onMonthChange?.(month)
    setEditingMonth(key)
  }
  const entriesFor = key => data.periods.filter(row => row.billing_period === key && meters.some(meter => Number(meter.id) === Number(row.utility_meter_id)))
  const activeMonthRows = editingMonth ? entriesFor(editingMonth) : []
  const chosenMonthReady = !!editingMonth && editingMonth.slice(0, 7) === recordMonth

  return <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-3">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <label className="text-sm font-semibold">Chọn phòng
        <select className="mt-1 block min-h-11 min-w-48 rounded-lg border border-slate-300 px-3" value={room?.id || ''} onChange={event => { setEditingMonth(''); selectRoom(Number(event.target.value)) }}>
          <option value="">Chọn phòng</option>
          {rooms.map(item => <option key={item.id} value={item.id}>{item.room_code}</option>)}
        </select>
      </label>
      {room && <p className="text-sm text-slate-600">{room.tenant || 'Chưa có người thuê'} · Năm {year}</p>}
    </div>
    {room && <div className="overflow-x-auto">
      <table aria-label="Lịch sử chỉ số điện nước 12 tháng" className="w-full min-w-[690px] text-sm tabular-nums">
        <thead className="bg-slate-50 text-left text-slate-700"><tr>
          {['Tháng', 'Điện', 'Nước', 'Trạng thái', 'Thao tác'].map(x => <th key={x} scope="col" className="p-3">{x}</th>)}
        </tr></thead>
        <tbody>{Array.from({ length: 12 }, (_, i) => {
          const key = monthKey(year, i + 1)
          const entries = entriesFor(key)
          const electricMeters = meters.filter(m => m.service?.type === 'ELECTRICITY')
          const waterMeters = meters.filter(m => m.service?.type === 'WATER')
          const enough = meters.length > 0 && entries.length >= meters.length
          const status = usageStatus(entries, meters.length)
          const label = entries.length === 0 ? 'Ghi chỉ số' : enough ? 'Đính chính' : 'Bổ sung / đính chính'
          const invoices = data.invoices.filter(x => Number(x.room_id) === Number(room.id) && x.billing_period === key)
          const col = items => <td className="p-3">{items.length ? items.map(m => <div key={m.id} className="mb-1">{formatPair(byMeter(entries, m), m.service?.unit || '')}</div>) : '—'}</td>
          return <tr ref={key === selectedPeriod ? selectedRow : undefined} key={key} className={`border-t border-slate-100 ${key === selectedPeriod ? 'bg-teal-50/50' : ''}`}>
            <th scope="row" className="whitespace-nowrap p-3 text-left font-semibold">T{i + 1}</th>
            {col(electricMeters)}{col(waterMeters)}
            <td className="p-3"><span className="ledger-badge" data-status={status}>{entries.length && !enough ? 'Thiếu một phần' : status}</span>
              {invoices.map(invoice => <Link key={invoice.invoice_id} className="mt-1 block text-xs text-teal-800 underline" to={`/invoices/${invoice.invoice_id}`}>Hóa đơn #{invoice.invoice_id}</Link>)}
            </td>
            <td className="p-3"><button type="button" disabled={!canWrite || !meters.length} onClick={() => open(key)}
              className="min-h-10 whitespace-nowrap rounded-lg bg-teal-50 px-3 font-semibold text-teal-800 hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-40">{label}</button></td>
          </tr>
        })}</tbody>
      </table>
    </div>}
    {room && chosenMonthReady && <UtilityLedgerMonthDialog key={`${room.id}:${editingMonth}`} room={room} periodKey={editingMonth}
      meters={meters.filter(m => m.service?.type === 'ELECTRICITY' || m.service?.type === 'WATER')}
      entries={activeMonthRows} canWrite={canWrite} onSaved={onSaved} onClose={() => setEditingMonth('')} />}
  </section>
}
