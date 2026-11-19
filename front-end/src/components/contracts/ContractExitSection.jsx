import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Droplets, X, Zap } from 'lucide-react'
import { confirmBillingCheckout, confirmBillingSettlement, getBillingV2, getBillingV2Status, recordBillingDeposit } from '../../api/billingV2Api'
import { getInvoicesByRoom } from '../../api/invoiceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency, formatUtilityValue } from '../../utils/formatters'
import { formatDateForApi, isValidDisplayDate } from '../../utils/date'
import DateInput from '../ui/DateInput'
import { checkoutMeasurement, contractDebt, formatExitTime } from './contractExitDisplay.mjs'

const localNow = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' }).replace(' ', 'T').slice(0, 16)
const apiTime = value => value.replace('T', ' ') + (value.length === 16 ? ':00' : '')
const field = 'mt-1 block min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 tabular-nums'
const primary = 'min-h-11 rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white disabled:opacity-50'
const secondary = 'min-h-11 rounded-lg border border-slate-300 px-4 text-sm disabled:opacity-50'
const eventLabel = { RECEIVED: 'Nhận cọc', REFUNDED: 'Hoàn cọc', FORFEITED: 'Mất cọc theo chính sách' }
const reasons = { MUTUAL_EARLY_END: 'Kết thúc theo thỏa thuận', NORMAL_EXPIRY: 'Hết hạn bình thường', TENANT_ABANDONMENT: 'Người thuê bỏ ngang' }
const sameId = (a, b) => String(a) === String(b)
const money = value => value != null && Number.isFinite(Number(value)) ? formatCurrency(value) : 'Chưa có dữ liệu'

function ExitDialog({ title, subtitle, close, busy, children }) {
  const panel = useRef(null)
  useEffect(() => {
    const previous = document.activeElement
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panel.current.focus()
    return () => { document.body.style.overflow = overflow; previous?.focus() }
  }, [])
  return <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-3" onKeyDown={event => {
    if (event.key === 'Escape' && !busy) { event.stopPropagation(); close() }
    if (event.key === 'Tab') {
      const controls = [...panel.current.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href]')].filter(node => node.getClientRects().length)
      const first = controls[0], last = controls.at(-1)
      if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) { event.preventDefault(); last?.focus() }
      else if (!event.shiftKey && (document.activeElement === last || document.activeElement === panel.current)) { event.preventDefault(); first?.focus() }
    }
  }}>
    <section ref={panel} tabIndex={-1} role="dialog" aria-modal="true" aria-label={title} className="max-h-[calc(100dvh-24px)] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-xl bg-white p-4 outline-none sm:p-6">
      <header className="mb-5 flex items-start justify-between gap-3"><div><p className="text-xs font-semibold text-teal-800">{subtitle}</p><h3 className="mt-1 text-xl font-semibold text-slate-900">{title}</h3></div>
        <button type="button" aria-label="Đóng cửa sổ" disabled={busy} onClick={close} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100 disabled:opacity-50"><X size={20} /></button>
      </header>{children}
    </section>
  </div>
}

function TransactionTime({ label, value, onChange }) {
  const [date, setDate] = useState(() => formatExitTime(value.slice(0, 10)))
  return <fieldset className="grid min-w-0 grid-cols-[minmax(0,1fr)_100px] gap-3"><legend className="mb-1 text-sm font-medium">{label}</legend>
    <div><label htmlFor="exit-date" className="text-xs text-slate-500">Ngày (DD/MM/YYYY)</label><DateInput id="exit-date" value={date} invalid={!isValidDisplayDate(date)} onChange={next => {
      setDate(next); onChange((isValidDisplayDate(next) ? formatDateForApi(next) : '') + value.slice(value.indexOf('T')))
    }} />{!isValidDisplayDate(date) && <p role="alert" className="mt-1 text-xs text-red-700">Ngày không hợp lệ.</p>}</div>
    <label className="text-xs text-slate-500">Giờ (24 giờ)<input aria-label={`${label} — giờ`} type="text" inputMode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:mm" required className={field} value={value.slice(value.indexOf('T') + 1)} onChange={event => onChange(value.split('T')[0] + 'T' + event.target.value)} /></label>
  </fieldset>
}

function MeterSummary({ meter, measurement, reading }) {
  return <div className="grid grid-cols-3 gap-2 text-sm">
    <div><p className="text-xs text-slate-500">Chỉ số trước</p><p className="mt-1 font-semibold tabular-nums">{measurement.previous ? formatUtilityValue(measurement.previous.reading_value) : 'Chưa có mốc'}</p></div>
    <div><p className="text-xs text-slate-500">Chỉ số cuối</p><p className="mt-1 font-semibold tabular-nums">{formatUtilityValue(reading)} {meter.service?.unit}</p></div>
    <div><p className="text-xs text-slate-500">Tiêu thụ</p><p className="mt-1 font-semibold tabular-nums">{measurement.usage == null ? 'Chưa xác định' : `${formatUtilityValue(measurement.usage)} ${meter.service?.unit || ''}`}</p></div>
  </div>
}

async function roomInvoices(roomId) {
  const items = []
  for (let page = 1, last = 1; page <= last; page++) {
    const response = await getInvoicesByRoom(roomId, { page }, { toast: false })
    const data = response.data.data
    if (!Array.isArray(data?.items) || !Number.isInteger(data.pagination?.last_page)) throw new Error('Chưa có dữ liệu công nợ đầy đủ.')
    items.push(...data.items); last = data.pagination.last_page
  }
  return items
}

function Checkout({ contract, meters, checkpoints, held, refresh, close }) {
  const [step, setStep] = useState(0)
  const [endedAt, setEndedAt] = useState(localNow)
  const [reason, setReason] = useState('MUTUAL_EARLY_END')
  const [readings, setReadings] = useState({})
  const [confirmNoHeld, setConfirmNoHeld] = useState(false)
  const invoices = useQuery({ queryKey: ['checkout-invoices', String(contract.room_id)], queryFn: () => roomInvoices(contract.room_id), enabled: step === 2, staleTime: 0 })
  const debt = invoices.isSuccess ? contractDebt(invoices.data, contract.id) : null
  const measurement = meter => checkoutMeasurement(checkpoints, meter.id, contract.id, endedAt, readings[meter.id])
  const mutation = useMutation({
    mutationFn: () => confirmBillingCheckout(contract.id, {
      actual_ended_at: apiTime(endedAt), reason,
      confirm_no_held_deposit: reason === 'TENANT_ABANDONMENT' && held === 0 && confirmNoHeld,
      readings: meters.map(meter => ({ utility_meter_id: meter.id, reading_value: readings[meter.id] })),
    }, { quiet: true }),
    onSuccess: async () => { await refresh(); close() },
  })
  const uncertain = mutation.isError && !!mutation.error.billingV2Message
  const validTime = /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/.test(endedAt)
  const submit = event => {
    event.preventDefault()
    if (mutation.isPending || !validTime) return
    if (step < 2) setStep(step + 1)
    else mutation.mutate()
  }
  return <ExitDialog title={['Bàn giao phòng', 'Chốt công tơ', 'Đối soát và xác nhận'][step]} subtitle={`Bước ${step + 1}/3 · ${contract.contract_code}`} close={close} busy={mutation.isPending || uncertain}>
    <form onSubmit={submit} className="space-y-4">
      {step === 0 && <div className="space-y-4">
        <TransactionTime label="Thời điểm trả phòng" value={endedAt} onChange={setEndedAt} />
        <label className="block text-sm font-medium">Lý do kết thúc<select className={field} value={reason} onChange={event => { setReason(event.target.value); setConfirmNoHeld(false) }}>{Object.entries(reasons).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
      </div>}
      {step === 1 && <div className="space-y-3">
        {meters.length === 0 && <p className="text-sm text-slate-600">Phòng chưa có công tơ đang hoạt động.</p>}
        {meters.map(meter => {
          const info = measurement(meter), water = meter.service?.type === 'WATER', Icon = water ? Droplets : Zap
          const invalid = readings[meter.id] !== '' && readings[meter.id] != null && info.previous && Number(readings[meter.id]) < Number(info.previous.reading_value)
          return <section key={meter.id} className="space-y-3 rounded-lg border border-slate-200 p-3">
            <h4 className={`flex items-center gap-2 font-semibold ${water ? 'text-sky-700' : 'text-amber-700'}`}><Icon size={18} />{meter.service?.name || 'Công tơ'} <span className="text-xs font-normal text-slate-500">{meter.meter_code}</span></h4>
            <div className="grid gap-3 sm:grid-cols-2"><div className="text-sm"><p className="text-slate-500">Chỉ số trước đã xác nhận</p><p className="mt-1 text-lg font-semibold tabular-nums">{info.previous ? `${formatUtilityValue(info.previous.reading_value)} ${meter.service?.unit || ''}` : 'Chưa có checkpoint hợp lệ'}</p><p className="mt-1 text-xs text-slate-500">{info.previous ? formatExitTime(info.previous.observed_at) : 'Chưa xác định được lượng tiêu thụ.'}</p></div>
              <label className="text-sm font-medium">Chỉ số cuối — {meter.service?.name || meter.meter_code}<input type="number" inputMode="decimal" min={info.previous ? info.previous.reading_value : 0} step="0.01" required aria-invalid={!!invalid} className={`${field} text-lg`} value={readings[meter.id] ?? ''} onChange={event => setReadings({ ...readings, [meter.id]: event.target.value })} />{invalid && <p role="alert" className="mt-1 text-xs text-red-700">Chỉ số cuối không được nhỏ hơn chỉ số trước.</p>}</label>
            </div>
            <div className="flex items-center justify-between border-t border-slate-100 pt-2 text-sm"><span>Lượng tiêu thụ từ mốc trên</span><strong className={`tabular-nums ${water ? 'text-sky-700' : 'text-amber-700'}`}>{info.usage == null ? 'Chưa xác định' : `${formatUtilityValue(info.usage)} ${meter.service?.unit || ''}`}</strong></div>
          </section>
        })}
      </div>}
      {step === 2 && <div className="space-y-4 text-sm">
        <div className="rounded-lg bg-slate-50 p-3"><p className="font-semibold">{contract.contract_code} · {contract.room?.room_code}</p><p className="mt-1">Ngày trả phòng: {formatExitTime(endedAt)}</p><p className="text-slate-600">{reasons[reason]}</p></div>
        <section className="space-y-3"><h4 className="font-semibold">Chốt công tơ</h4>{meters.map(meter => <div key={meter.id} className="space-y-2 rounded-lg border border-slate-200 p-3"><p className="font-medium">{meter.service?.name || meter.meter_code}</p><MeterSummary meter={meter} reading={readings[meter.id]} measurement={measurement(meter)} /></div>)}</section>
        <section className="space-y-2"><h4 className="font-semibold">Hóa đơn và công nợ</h4>
          {invoices.isPending && <p role="status">Đang tải công nợ…</p>}
          {invoices.isError && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-amber-900">Chưa có dữ liệu công nợ chi tiết. Cần kiểm tra hóa đơn riêng trước khi đối soát tài chính.</p>}
          {debt && <>{debt.rows.map(row => <div key={row.id} className="rounded-lg border border-slate-200 p-3"><Link to={`/invoices/${row.id}`} target="_blank" rel="noreferrer" className="font-medium text-teal-800 underline">{row.invoice_code || `Hóa đơn #${row.id}`}</Link><span className="ml-2 text-xs text-slate-500">{row.status === 'PARTIALLY_PAID' ? 'Thanh toán một phần' : 'Chưa thanh toán'}</span><dl className="mt-2 grid grid-cols-3 gap-2 text-xs"><div><dt>Tổng hóa đơn</dt><dd className="mt-1 tabular-nums">{money(row.total_amount)}</dd></div><div><dt>Đã thanh toán</dt><dd className="mt-1 tabular-nums">{money(row.paid_amount)}</dd></div><div><dt>Còn nợ</dt><dd className="mt-1 font-semibold tabular-nums">{row.total_amount != null && row.paid_amount != null && Number(row.total_amount) >= Number(row.paid_amount) ? formatCurrency(Number(row.total_amount) - Number(row.paid_amount)) : 'Cần đối soát'}</dd></div></dl></div>)}
            <p className="flex justify-between gap-3 rounded-lg bg-slate-50 p-3"><span>Còn nợ trên hóa đơn đã phát hành</span><strong className="shrink-0 tabular-nums">{debt.total == null ? 'Cần đối soát' : formatCurrency(debt.total)}</strong></p>
            {debt.drafts > 0 && <p className="text-slate-600">Có {debt.drafts} hóa đơn nháp, chưa tính vào công nợ đã phát hành.</p>}
            {debt.unassigned && <p className="text-amber-800">Có hóa đơn của phòng chưa gắn hợp đồng. Chưa cộng vào công nợ hợp đồng này; cần đối soát riêng.</p>}
          </>}
        </section>
        <p className="flex justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 p-3"><span>Cọc thực tế còn giữ</span><strong className="tabular-nums">{formatCurrency(held)}</strong></p>
        <div className="rounded-lg bg-amber-50 p-3 text-amber-900"><p className="font-semibold">Chưa quyết toán cuối kỳ</p><p className="mt-1">Tiền điện/nước cuối kỳ chưa được xác định tại bước này. Xác nhận trả phòng không tự tạo hóa đơn, ghi thanh toán hoặc khấu trừ cọc vào công nợ.</p></div>
        {reason === 'TENANT_ABANDONMENT' && (held > 0 ? <p className="rounded-lg bg-amber-50 p-3 text-amber-900">Theo chính sách bỏ ngang hiện có, Billing V2 sẽ ghi mất số cọc thực tế còn giữ.</p> : <label className="flex gap-2"><input type="checkbox" disabled={mutation.isPending || uncertain} checked={confirmNoHeld} onChange={event => setConfirmNoHeld(event.target.checked)} />Tôi đã đối soát và xác nhận không có tiền cọc thực tế còn giữ.</label>)}
      </div>}
      {mutation.isError && <p role="alert" className={`rounded-lg p-3 text-sm ${uncertain ? 'bg-amber-50 text-amber-900' : 'bg-red-50 text-red-700'}`}>{mutation.error.billingV2Message || getApiErrorMessage(mutation.error)}</p>}
      <footer className="flex justify-end gap-2 border-t border-slate-200 bg-white py-3">
        <button type="button" className={secondary} disabled={mutation.isPending || uncertain} onClick={step ? () => setStep(step - 1) : close}>{step ? 'Quay lại' : 'Đóng'}</button>
        <button className={primary} disabled={mutation.isPending || !validTime}>{step < 2 ? 'Tiếp tục' : uncertain ? 'Thử lại xác nhận' : 'Xác nhận trả phòng'}</button>
      </footer>
    </form>
  </ExitDialog>
}

function DepositLedger({ contract, rows, held, refresh }) {
  const [draft, setDraft] = useState(null)
  const [success, setSuccess] = useState('')
  const mutation = useMutation({
    mutationFn: values => recordBillingDeposit(contract.id, { event: values.event, amount: values.amount, occurred_at: apiTime(values.occurredAt), request_key: values.key }, { quiet: true }),
    onSuccess: async () => { setSuccess('Đã xác nhận giao dịch cọc thành công.'); setDraft(null); await refresh() },
  })
  const uncertain = mutation.isError && !!mutation.error.billingV2Message
  const open = event => { mutation.reset(); setSuccess(''); setDraft({ event, amount: '', occurredAt: localNow(), key: crypto.randomUUID() }) }
  return <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
    <header className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="text-lg font-semibold">Sổ cọc</h3><p className="text-sm text-slate-500">Tiền thực tế còn giữ</p><p className="mt-1 text-2xl font-semibold tabular-nums text-teal-800">{formatCurrency(held)}</p></div>
      <div className="flex flex-wrap gap-2">{contract.status === 'ACTIVE' && <button type="button" className={primary} onClick={() => open('RECEIVED')}>Ghi nhận cọc</button>}<button type="button" className={secondary} disabled={held <= 0} onClick={() => open('REFUNDED')}>Ghi hoàn cọc</button></div>
    </header>
    {success && <p role="status" className="rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{success}</p>}
    {draft && <ExitDialog title={eventLabel[draft.event]} subtitle={contract.contract_code} close={() => setDraft(null)} busy={mutation.isPending || uncertain}>
      <form className="space-y-4" onSubmit={event => { event.preventDefault(); if (!mutation.isPending && /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/.test(draft.occurredAt)) mutation.mutate(draft) }}>
        <p className="text-sm text-slate-600">Cọc thực tế đang giữ: <strong>{formatCurrency(held)}</strong></p>
        <fieldset disabled={mutation.isPending || uncertain} className="space-y-4">
          <label className="block text-sm font-medium">Số tiền (đ)<input aria-label="Số tiền (đ)" type="number" inputMode="numeric" min="1" step="1" max={draft.event === 'REFUNDED' ? held : undefined} required className={`${field} text-lg`} value={draft.amount} aria-invalid={draft.event === 'REFUNDED' && Number(draft.amount) > held} onChange={event => setDraft({ ...draft, amount: event.target.value })} />{draft.event === 'REFUNDED' && Number(draft.amount) > held && <p role="alert" className="mt-1 text-sm text-red-700">Số tiền hoàn vượt số dư cọc còn giữ.</p>}</label>
          <TransactionTime label="Thời điểm giao dịch" value={draft.occurredAt} onChange={occurredAt => setDraft({ ...draft, occurredAt })} />
        </fieldset>
        {mutation.isError && <p role="alert" className={`rounded-lg p-3 text-sm ${uncertain ? 'bg-amber-50 text-amber-900' : 'bg-red-50 text-red-700'}`}>{mutation.error.billingV2Message || getApiErrorMessage(mutation.error)}</p>}
        <footer className="flex justify-end gap-2 border-t border-slate-200 pt-3"><button type="button" className={secondary} disabled={mutation.isPending || uncertain} onClick={() => setDraft(null)}>Đóng</button><button className={primary} disabled={mutation.isPending}>{uncertain ? 'Thử lại giao dịch' : 'Lưu giao dịch'}</button></footer>
      </form>
    </ExitDialog>}
    {rows.length ? <table className="w-full table-fixed text-left text-xs sm:text-sm"><colgroup><col className="w-[38%]" /><col className="w-[29%]" /><col className="w-[33%]" /></colgroup>
      <thead className="bg-slate-50 text-slate-500"><tr><th scope="col" className="p-2 font-medium">Thời điểm</th><th scope="col" className="p-2 font-medium">Giao dịch</th><th scope="col" className="p-2 text-right font-medium">Số tiền</th></tr></thead>
      <tbody>{rows.map(row => <tr className="border-t border-slate-200" key={row.id}><td className="p-2 tabular-nums">{formatExitTime(row.occurred_at)}</td><td className="p-2">{eventLabel[row.event]}</td><td className="break-words p-2 text-right font-medium tabular-nums">{formatCurrency(row.amount)}</td></tr>)}</tbody>
    </table> : <p className="text-sm text-slate-600">Chưa có giao dịch cọc thực tế.</p>}
  </section>
}

export default function ContractExitSection({ contract, view = 'overview' }) {
  const [checkout, setCheckout] = useState(false)
  const [period] = useState(() => localNow().slice(0, 7) + '-01')
  const client = useQueryClient()
  const status = useQuery({ queryKey: ['billing-v2-status'], queryFn: getBillingV2Status })
  const enabled = status.data?.data?.data?.enabled === true
  const room = useQuery({ queryKey: ['billing-v2', String(contract.room_id), period], queryFn: () => getBillingV2(contract.room_id, period), enabled })
  const refresh = () => client.invalidateQueries({ queryKey: ['billing-v2', String(contract.room_id)] })
  const refreshCheckout = async () => { await refresh(); window.location.reload() }
  const confirm = useMutation({ mutationFn: () => confirmBillingSettlement(contract.id), onSuccess: refresh })
  if (status.isError || room.isError) return <p role="alert" className="text-red-700">{getApiErrorMessage(status.error || room.error)}</p>
  if (status.isPending || (enabled && room.isPending)) return <p role="status">Đang tải thông tin trả phòng và sổ cọc…</p>
  if (!enabled) return null
  const data = room.data.data.data
  const rows = data.deposits.filter(row => sameId(row.contract_id, contract.id))
  const held = rows.reduce((sum, row) => sum + Number(row.amount) * (row.event === 'RECEIVED' ? 1 : -1), 0)
  const settlements = data.settlements.filter(row => sameId(row.contract_id, contract.id))
  return <div id="contract-exit" className="space-y-4">
    {view === 'deposit' ? <DepositLedger contract={contract} rows={rows} held={held} refresh={refresh} /> : <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-semibold">Trả phòng</h3>{contract.status === 'ACTIVE' && data.room.room_type === 'PRIVATE_ROOM' && <button type="button" className={primary} onClick={() => setCheckout(true)}>Bắt đầu trả phòng</button>}</div>
      {data.room.room_type !== 'PRIVATE_ROOM' && <p className="text-sm text-slate-600">Xác nhận trả phòng Billing V2 hiện áp dụng cho phòng riêng.</p>}
      {contract.actual_ended_at && <p className="text-sm text-slate-700">Ngày trả phòng: <strong>{formatExitTime(contract.actual_ended_at)}</strong></p>}
      {settlements.map(row => <p key={row.id} className="text-sm text-slate-700">{row.billing_period ? `Kỳ quyết toán ${row.billing_period.slice(5, 7)}/${row.billing_period.slice(0, 4)} · ` : ''} {row.status === 'FINAL_RECONCILED' ? 'Đã đối soát cuối kỳ' : 'Chờ quyết toán cuối kỳ'}</p>)}
      {settlements.some(row => row.confirmation_state === 'NEEDS_OWNER_CONFIRMATION') && <button type="button" className={secondary} disabled={confirm.isPending} onClick={() => confirm.mutate()}>Xác nhận đã đối soát và không còn giữ cọc</button>}
      {confirm.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(confirm.error)}</p>}
    </section>}
    {checkout && <Checkout contract={contract} meters={data.meters} checkpoints={data.checkpoints} held={held} refresh={refreshCheckout} close={() => setCheckout(false)} />}
  </div>
}
