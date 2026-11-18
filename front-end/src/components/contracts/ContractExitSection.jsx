import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { confirmBillingCheckout, confirmBillingSettlement, getBillingV2, getBillingV2Status, recordBillingDeposit } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'

const localNow = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' }).replace(' ', 'T').slice(0, 16)
const apiTime = value => value.replace('T', ' ') + (value.length === 16 ? ':00' : '')
const field = 'mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3'
const primary = 'min-h-11 rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white disabled:opacity-50'
const secondary = 'min-h-11 rounded-lg border border-slate-300 px-4 text-sm disabled:opacity-50'
const eventLabel = { RECEIVED: 'Nhận cọc', REFUNDED: 'Hoàn cọc', FORFEITED: 'Mất cọc theo chính sách' }
const reasons = {
  MUTUAL_EARLY_END: 'Kết thúc theo thỏa thuận',
  NORMAL_EXPIRY: 'Hết hạn bình thường',
  TENANT_ABANDONMENT: 'Người thuê bỏ ngang',
}
const sameId = (a, b) => String(a) === String(b)

function Checkout({ contract, meters, held, refresh, close }) {
  const [step, setStep] = useState(0)
  const [endedAt, setEndedAt] = useState(localNow)
  const [reason, setReason] = useState('MUTUAL_EARLY_END')
  const [readings, setReadings] = useState({})
  const [confirmNoHeld, setConfirmNoHeld] = useState(false)
  const mutation = useMutation({
    mutationFn: () => confirmBillingCheckout(contract.id, {
      actual_ended_at: apiTime(endedAt), reason,
      confirm_no_held_deposit: reason === 'TENANT_ABANDONMENT' && held === 0 && confirmNoHeld,
      readings: meters.map(meter => ({ utility_meter_id: meter.id, reading_value: readings[meter.id] })),
    }),
    onSuccess: async () => { await refresh(); close() },
  })
  const submit = event => {
    event.preventDefault()
    if (mutation.isPending) return
    if (step < 2) setStep(step + 1)
    else mutation.mutate()
  }
  return <div role="dialog" aria-modal="true" aria-labelledby="checkout-title" className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-3">
    <form onSubmit={submit} className="max-h-[90dvh] w-full max-w-xl space-y-4 overflow-auto rounded-xl bg-white p-5">
      <header><p className="text-xs font-semibold text-teal-800">Bước {step + 1}/3</p>
        <h3 id="checkout-title" className="text-xl font-semibold">{['Bàn giao phòng', 'Chốt công tơ', 'Đối soát và xác nhận'][step]}</h3>
        <p className="text-sm text-slate-600">{contract.contract_code}</p>
      </header>
      {step === 0 && <div className="grid gap-3 sm:grid-cols-2">
        <label>Thời điểm trả phòng<input type="datetime-local" className={field} required value={endedAt} onChange={event => setEndedAt(event.target.value)} /></label>
        <label>Lý do kết thúc<select className={field} value={reason} onChange={event => { setReason(event.target.value); setConfirmNoHeld(false) }}>
          {Object.entries(reasons).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select></label>
      </div>}
      {step === 1 && <div className="grid gap-3 sm:grid-cols-2">
        {meters.length === 0 && <p className="text-sm text-slate-600">Phòng chưa có công tơ đang hoạt động.</p>}
        {meters.map(meter => <label key={meter.id}>{meter.service?.name || 'Công tơ'} · {meter.meter_code || `#${meter.id}`}
          <input type="number" inputMode="decimal" min="0" step="0.01" required className={field} value={readings[meter.id] ?? ''}
            onChange={event => setReadings({ ...readings, [meter.id]: event.target.value })} />
        </label>)}
      </div>}
      {step === 2 && <div className="space-y-3 text-sm">
        <p><strong>{reasons[reason]}</strong> · {endedAt.replace('T', ' ')}</p>
        <ul>{meters.map(meter => <li key={meter.id}>{meter.service?.name || meter.meter_code}: {readings[meter.id]} {meter.service?.unit}</li>)}</ul>
        <p>Cọc thực tế còn giữ: <strong>{formatCurrency(held)}</strong></p>
        <p>Xác nhận sẽ chốt bàn giao và kết thúc hợp đồng. Khoản thu cuối kỳ được đối soát theo Billing V2.</p>
        {reason === 'TENANT_ABANDONMENT' && (held > 0 ?
          <p className="rounded-lg bg-amber-50 p-3 text-amber-900">Billing V2 sẽ ghi mất số cọc thực tế còn giữ theo chính sách bỏ ngang.</p> :
          <label className="flex gap-2"><input type="checkbox" checked={confirmNoHeld} onChange={event => setConfirmNoHeld(event.target.checked)} />
            Tôi đã đối soát và xác nhận không có tiền cọc thực tế còn giữ.
          </label>)}
      </div>}
      {mutation.isError && <p role="alert" className="text-sm text-red-700">{mutation.error.billingV2Message || getApiErrorMessage(mutation.error)}</p>}
      <footer className="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-3">
        <button type="button" className={secondary} disabled={mutation.isPending} onClick={step ? () => setStep(step - 1) : close}>{step ? 'Quay lại' : 'Đóng'}</button>
        <button className={primary} disabled={mutation.isPending}>{step < 2 ? 'Tiếp tục' : 'Xác nhận trả phòng'}</button>
      </footer>
    </form>
  </div>
}

function DepositLedger({ contract, rows, held, refresh }) {
  const [draft, setDraft] = useState(null)
  const mutation = useMutation({
    mutationFn: values => recordBillingDeposit(contract.id, {
      event: values.event, amount: values.amount, occurred_at: apiTime(values.occurredAt), request_key: values.key,
    }),
    onSuccess: async () => { await refresh(); setDraft(null) },
  })
  const open = event => {
    mutation.reset()
    setDraft({ event, amount: '', occurredAt: localNow(), key: crypto.randomUUID() })
  }
  return <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div><h3 className="text-lg font-semibold">Sổ cọc</h3><p className="text-sm text-slate-600">Thực tế còn giữ <strong className="tabular-nums text-slate-900">{formatCurrency(held)}</strong></p></div>
      {!draft && <div className="flex gap-2">
        {contract.status === 'ACTIVE' && <button type="button" className={primary} onClick={() => open('RECEIVED')}>Ghi nhận cọc</button>}
        <button type="button" className={secondary} disabled={held <= 0} onClick={() => open('REFUNDED')}>Ghi hoàn cọc</button>
      </div>}
    </div>
    {draft && <form className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3" onSubmit={event => { event.preventDefault(); if (!mutation.isPending) mutation.mutate(draft) }}>
      <h4 className="font-semibold">{eventLabel[draft.event]}</h4>
      <fieldset disabled={mutation.isPending} className="grid gap-3 sm:grid-cols-2">
        <label>Số tiền (đ)<input aria-label="Số tiền (đ)" type="number" inputMode="numeric" min="1" step="1" max={draft.event === 'REFUNDED' ? held : undefined} required className={field}
          value={draft.amount} aria-invalid={draft.event === 'REFUNDED' && Number(draft.amount) > held}
          onChange={event => setDraft({ ...draft, amount: event.target.value })} />
          {draft.event === 'REFUNDED' && Number(draft.amount) > held && <p role="alert" className="mt-1 text-sm text-red-700">Số tiền hoàn vượt số dư cọc còn giữ.</p>}
        </label>
        <label>Thời điểm giao dịch<input type="datetime-local" required className={field} value={draft.occurredAt}
          onChange={event => setDraft({ ...draft, occurredAt: event.target.value })} /></label>
      </fieldset>
      {mutation.isError && <p role="alert" className="text-sm text-red-700">{mutation.error.billingV2Message || getApiErrorMessage(mutation.error)}</p>}
      <div className="flex gap-2"><button className={primary} disabled={mutation.isPending}>Lưu giao dịch</button>
        <button type="button" className={secondary} disabled={mutation.isPending} onClick={() => setDraft(null)}>Đóng</button></div>
    </form>}
    {rows.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm">
      <thead><tr><th scope="col">Thời điểm</th><th scope="col">Giao dịch</th><th scope="col" className="text-right">Số tiền</th></tr></thead>
      <tbody>{rows.map(row => <tr className="border-t border-slate-200" key={row.id}>
        <td className="whitespace-nowrap">{row.occurred_at}</td><td>{eventLabel[row.event]}</td><td className="text-right tabular-nums">{formatCurrency(row.amount)}</td>
      </tr>)}</tbody>
    </table></div> : <p className="text-sm text-slate-600">Chưa có giao dịch cọc thực tế.</p>}
  </section>
}

export default function ContractExitSection({ contract }) {
  const [checkout, setCheckout] = useState(false)
  const [period] = useState(() => localNow().slice(0, 7) + '-01')
  const client = useQueryClient()
  const status = useQuery({ queryKey: ['billing-v2-status'], queryFn: getBillingV2Status })
  const enabled = status.data?.data?.data?.enabled === true
  const room = useQuery({
    queryKey: ['billing-v2', String(contract.room_id), period],
    queryFn: () => getBillingV2(contract.room_id, period),
    enabled,
  })
  const refresh = () => client.invalidateQueries({ queryKey: ['billing-v2', String(contract.room_id)] })
  const refreshCheckout = async () => {
    await refresh()
    window.location.reload()
  }
  const confirm = useMutation({ mutationFn: () => confirmBillingSettlement(contract.id), onSuccess: refresh })
  if (status.isError || room.isError) return <p role="alert" className="text-red-700">{getApiErrorMessage(status.error || room.error)}</p>
  if (status.isPending || (enabled && room.isPending)) return <p role="status">Đang tải thông tin trả phòng và sổ cọc…</p>
  if (!enabled) return null
  const data = room.data.data.data
  const rows = data.deposits.filter(row => sameId(row.contract_id, contract.id))
  const held = rows.reduce((sum, row) => sum + Number(row.amount) * (row.event === 'RECEIVED' ? 1 : -1), 0)
  const settlements = data.settlements.filter(row => sameId(row.contract_id, contract.id))
  return <div id="contract-exit" className="space-y-4">
    <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-semibold">Trả phòng</h3>
        {contract.status === 'ACTIVE' && data.room.room_type === 'PRIVATE_ROOM' && <button type="button" className={primary} onClick={() => setCheckout(true)}>Bắt đầu trả phòng</button>}
      </div>
      {data.room.room_type !== 'PRIVATE_ROOM' && <p className="text-sm text-slate-600">Xác nhận trả phòng Billing V2 hiện áp dụng cho phòng riêng.</p>}
      {settlements.map(row => <p key={row.id} className="text-sm text-slate-700">{row.billing_period?.slice(0, 7)} · {row.status === 'FINAL_RECONCILED' ? 'Đã đối soát cuối kỳ' : 'Chờ quyết toán cuối kỳ'}</p>)}
      {settlements.some(row => row.confirmation_state === 'NEEDS_OWNER_CONFIRMATION') && <button type="button" className={secondary} disabled={confirm.isPending}
        onClick={() => confirm.mutate()}>Xác nhận đã đối soát và không còn giữ cọc</button>}
      {confirm.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(confirm.error)}</p>}
    </section>
    <DepositLedger contract={contract} rows={rows} held={held} refresh={refresh} />
    {checkout && <Checkout contract={contract} meters={data.meters} held={held} refresh={refreshCheckout} close={() => setCheckout(false)} />}
  </div>
}
