import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { generateRoomMonthlyInvoice, getMonthlyBillingOptions } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'
import { summarizeMonthlyResults } from '../../utils/monthlyBillingResults'
import MonthlyDraftUpdatePanel from './MonthlyDraftUpdatePanel'

const labels = { CREATED: 'Đã tạo hóa đơn nháp', EXISTING: 'Hóa đơn đã tồn tại — giữ nguyên', BLOCKED: 'Cần xem xét', PENDING_FINAL_SETTLEMENT: 'Chờ dữ liệu quyết toán', NO_ELIGIBLE_CONTRACT: 'Không có hợp đồng trong kỳ', NO_NEW_RECEIVABLES: 'Không phát sinh khoản phải thu mới' }
const suggestedUsageWindow = month => {
  const [year, monthNumber] = month.split('-').map(Number)
  const end = new Date(year, monthNumber, 1)
  const localDateTime = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T00:00`
  return { start: `${month}-01T00:00`, end: localDateTime(end) }
}

const roomUsageWindow = (month, contractStart) => {
  if (!contractStart) return suggestedUsageWindow(month)
  const [year, monthNumber] = month.split('-').map(Number)
  const day = Number(contractStart.slice(8, 10))
  const make = (y, m) => {
    const date = new Date(y, m, 0)
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(Math.min(day, date.getDate())).padStart(2, '0')}T00:00`
  }
  return { start: make(year, monthNumber), end: make(year, monthNumber + 1) }
}

// KTX service charges are opt-in. Returning no confirmation means bed rent only.
const serializeConfirmations = (contracts, approvals) => contracts.flatMap(contract => {
  const input = approvals[contract.id] || {}
  const dorm = contract.room?.room_type === 'DORMITORY'
  const services = (contract.agreed_services || [])
    .filter(term => dorm ? Boolean(input[`extra-${term.id}`]) : input[`amount-${term.id}`] !== undefined && input[`amount-${term.id}`] !== '')
    .map(term => ({ term_id: term.id, quantity: input[`quantity-${term.id}`] ?? '', amount: input[`amount-${term.id}`] ?? '' }))
  if (!input.reason && input.rent === undefined && services.length === 0) return []
  return [{ contract_id: contract.id, reason: input.reason || '',
    ...(input.rent !== undefined && input.rent !== '' ? { rent_amount: input.rent } : {}),
    ...(dorm ? { extra_service_confirmed: Boolean(input.extraConfirmed) } : {}), services }]
})

const isSharedMeterTerm = term => ['ELECTRICITY', 'WATER'].includes(term.service_type) && ['PER_UNIT', 'TIERED'].includes(term.billing_method)

export default function MonthlyInvoicePanel({ houseId, roomId }) {
  const [month, setMonth] = useState(() => new Date().toLocaleDateString('sv-SE').slice(0, 7))
  const [usageWindow, setUsageWindow] = useState(() => suggestedUsageWindow(new Date().toLocaleDateString('sv-SE').slice(0, 7)))
  const [approvals, setApprovals] = useState({})
  const [results, setResults] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const client = useQueryClient()
  const period = `${month}-01`
  const usageStartAt = `${usageWindow.start.replace('T', ' ')}:00`
  const usageEndAt = `${usageWindow.end.replace('T', ' ')}:00`
  const options = useQuery({ queryKey: ['monthly-billing-options', houseId, period], queryFn: () => getMonthlyBillingOptions(houseId, period), enabled: Boolean(houseId && month) })
  const contracts = (options.data?.data?.data || []).filter(c => Number(c.room_id) === Number(roomId))
  const contractStart = contracts.find(c => c.start_date)?.start_date?.slice(0, 10)
  useEffect(() => { if (contractStart && !results) setUsageWindow(roomUsageWindow(month, contractStart)) }, [contractStart, month])
  const counts = summarizeMonthlyResults(results || [])
  const confirmedInputs = serializeConfirmations(contracts, approvals)
  const update = (id, key, value) => setApprovals(previous => ({ ...previous, [id]: { ...previous[id], [key]: value } }))
  const submit = async () => {
    if (lock.current || !houseId || !month) return
    lock.current = true
    setBusy(true); setError('')
    try {
      const confirmations = serializeConfirmations(contracts, approvals)
      const invalid = contracts.find(contract => {
        if (contract.room?.room_type !== 'DORMITORY') return false
        const input = approvals[contract.id] || {}
        const selected = (contract.agreed_services || []).filter(term => input[`extra-${term.id}`])
        return selected.length > 0 && (!input.extraConfirmed || (input.reason || '').trim().length < 5 || selected.some(term => !(Number(input[`quantity-${term.id}`]) > 0) || !(Number(input[`amount-${term.id}`]) > 0)))
      })
      if (invalid) {
        setError(`Giường ${invalid.bed?.bed_code || invalid.contract_code}: cần xác nhận thỏa thuận thu riêng, lý do, số lượng và số tiền cho từng dịch vụ.`)
        return
      }
      const response = await generateRoomMonthlyInvoice(roomId, { billing_period: period, usage_start_at: usageStartAt, usage_end_at: usageEndAt, confirmations })
      setResults(response.data.data)
      // Loading an existing DRAFT restores its saved service choices, so the
      // UI never misleadingly shows a charged service as unchecked on reload.
      setApprovals(previous => {
        const restored = { ...previous }
        for (const row of response.data.data || []) {
          for (const invoice of row.invoices || []) {
            const saved = invoice.monthly_confirmation
            if (!saved || Object.keys(restored[invoice.contract_id] || {}).length > 0) continue
            const entry = { reason: saved.reason || '', extraConfirmed: Boolean(saved.extra_service_confirmed) }
            if (saved.rent_amount !== undefined) entry.rent = String(saved.rent_amount)
            for (const service of saved.services || []) {
              entry[`extra-${service.term_id}`] = true
              entry[`quantity-${service.term_id}`] = String(service.quantity)
              entry[`amount-${service.term_id}`] = String(service.amount)
            }
            restored[invoice.contract_id] = entry
          }
        }
        return restored
      })
      await client.invalidateQueries({ queryKey: ['invoices'] })
    } catch (e) {
      setError(e.billingV2Message || getApiErrorMessage(e))
    } finally {
      lock.current = false; setBusy(false)
    }
  }
  return <section className="mt-5 space-y-4 rounded-xl border bg-white p-4" aria-label="Tạo hóa đơn tháng">
    <h3 className="font-semibold">Tạo hóa đơn tháng</h3>
    <p className="text-sm text-slate-600">Tổng hợp theo hợp đồng và dữ liệu đã xác nhận. Chỉ tạo bản nháp; chưa phát hành, thu tiền hoặc yêu cầu PayOS.</p>
    <div className="grid gap-3 sm:grid-cols-3">
      <label className="block text-sm">Tháng hóa đơn<input type="month" value={month} disabled={busy} onChange={e => { setMonth(e.target.value); setUsageWindow(suggestedUsageWindow(e.target.value)); setApprovals({}); setResults(null) }} className="mt-1 block min-h-11 w-full rounded border p-2" /></label>
      <label className="block text-sm">Bắt đầu sử dụng · giờ Việt Nam<input type="datetime-local" value={usageWindow.start} disabled={busy} onChange={e => setUsageWindow(previous => ({ ...previous, start: e.target.value }))} className="mt-1 block min-h-11 w-full rounded border p-2" /></label>
      <label className="block text-sm">Kết thúc sử dụng · giờ Việt Nam<input type="datetime-local" value={usageWindow.end} disabled={busy} onChange={e => setUsageWindow(previous => ({ ...previous, end: e.target.value }))} className="mt-1 block min-h-11 w-full rounded border p-2" /></label>
    </div>
    <p className="text-xs text-slate-500">Tháng hóa đơn tách biệt với thời gian sử dụng. Mặc định theo ngày bắt đầu thuê của hợp đồng phòng này, nếu có. Bạn có thể điều chỉnh kỳ theo thỏa thuận; không tự thay kỳ công tơ đã xác nhận.</p>
    <details><summary className="cursor-pointer py-2 text-sm">Tiền giường kỳ lẻ và dịch vụ KTX thu thêm (tùy chọn); xác nhận kỳ phòng thường</summary>
      <p className="mb-3 text-sm text-slate-600">KTX mặc định chỉ thu giá giường trong hợp đồng, đã bao gồm điện, nước, internet và dịch vụ. Chỉ tích chọn thu thêm khi có thỏa thuận riêng. Phòng thường giữ quy tắc hiện tại.</p>
      {options.isError && <p role="alert">{getApiErrorMessage(options.error)}</p>}
      {options.isPending && <p>Đang tải hợp đồng...</p>}
      {contracts.map(c => {
        const dorm = c.room?.room_type === 'DORMITORY'
        const input = approvals[c.id] || {}
        const selectedTerms = (c.agreed_services || []).filter(t => dorm && input[`extra-${t.id}`])
        return <fieldset key={c.id} disabled={busy} className="mb-4 space-y-2 rounded border p-3">
          <legend className="px-1 text-sm font-semibold">{dorm ? `KTX ${c.room?.room_code} · Giường ${c.bed?.bed_code || '?'}` : `Phòng ${c.room?.room_code}`} · {c.contract_code}</legend>
          {dorm && <p className="text-sm text-emerald-800">Giá giường theo hợp đồng: {formatCurrency(c.monthly_rent)}. Các dịch vụ đã bao gồm, mặc định không thu thêm. Không cần chỉ số điện nước khi chỉ thu tiền giường.</p>}
          <label className="block text-sm">{dorm ? 'Tiền giường xác nhận cho kỳ lẻ (đ)' : 'Tiền phòng xác nhận (đ)'}<input type="number" min="0" step="0.01" value={input.rent || ''} onChange={e => update(c.id, 'rent', e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label>
          <label className="block text-sm">Căn cứ thỏa thuận<input value={input.reason || ''} onChange={e => update(c.id, 'reason', e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label>
          {dorm ? <>
            <p className="text-sm font-medium">Dịch vụ muốn thu thêm (mặc định không chọn)</p>
            {(c.agreed_services || []).filter(t => t.is_active).map(t => <div key={t.id} className="rounded bg-slate-50 p-2">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={Boolean(input[`extra-${t.id}`])} disabled={isSharedMeterTerm(t)} onChange={e => update(c.id, `extra-${t.id}`, e.target.checked)} />{t.service_name} · {t.billing_method}</label>
              {isSharedMeterTerm(t) ? <p className="mt-1 text-xs text-amber-800">Thu riêng theo công tơ chung chưa hỗ trợ phân bổ an toàn. Không thể chọn để thu tự động.</p> : input[`extra-${t.id}`] && <div className="mt-2 grid gap-2 sm:grid-cols-2">
                <label className="text-sm">Số lượng<input type="number" min="0.01" step="0.01" value={input[`quantity-${t.id}`] || ''} onChange={e => update(c.id, `quantity-${t.id}`, e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label>
                <label className="text-sm">Thành tiền thu riêng (đ)<input type="number" min="1" step="1" value={input[`amount-${t.id}`] || ''} onChange={e => update(c.id, `amount-${t.id}`, e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label>
              </div>}
            </div>)}
            {selectedTerms.length > 0 && <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={Boolean(input.extraConfirmed)} onChange={e => update(c.id, 'extraConfirmed', e.target.checked)} />Tôi xác nhận có thỏa thuận thu riêng các khoản được chọn, không thu trùng dịch vụ đã gồm trong giá giường.</label>}
            <p className="text-xs text-slate-500">Nếu dịch vụ chưa có trong hợp đồng, cần bổ sung thỏa thuận hoặc thêm khoản MANUAL với lý do sau khi tạo nháp; không tự đặt giá.</p>
          </> : (c.agreed_services || []).filter(t => !isSharedMeterTerm(t) && t.is_active).map(t => <div key={t.id} className="rounded bg-slate-50 p-2"><p className="text-sm">{t.service_name} · {t.billing_method}</p><div className="grid gap-2 sm:grid-cols-2"><label className="text-sm">Số lượng/người<input type="number" min="0" step="0.01" value={input[`quantity-${t.id}`] || ''} onChange={e => update(c.id, `quantity-${t.id}`, e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label><label className="text-sm">Số tiền xác nhận (đ)<input type="number" min="0" step="0.01" value={input[`amount-${t.id}`] || ''} onChange={e => update(c.id, `amount-${t.id}`, e.target.value)} className="block min-h-11 w-full rounded border p-2" /></label></div></div>)}
        </fieldset>
      })}
    </details>
    <button type="button" disabled={busy || !month} onClick={submit} className="min-h-11 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{busy ? 'Đang tổng hợp...' : 'Tạo hóa đơn tháng'}</button>
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    {results && <div aria-live="polite" className="space-y-3">
      <p className="rounded bg-slate-100 p-3 text-sm" role="status">Tạo mới: {counts.CREATED} · Đã tồn tại: {counts.EXISTING} · Đang chờ: {counts.PENDING_FINAL_SETTLEMENT} · Bị chặn: {counts.BLOCKED} · Không có hợp đồng: {counts.NO_ELIGIBLE_CONTRACT} · Không thu mới: {counts.NO_NEW_RECEIVABLES}</p>
      <p className="text-sm text-slate-600">Chỉ xử lý phòng đang chọn, không tạo hóa đơn cho phòng khác.</p>
      {results.map(result => <article key={result.room_id} className="rounded border p-3"><h4 className="font-semibold">Phòng {result.room_code} — {labels[result.status] || result.status}</h4>{Object.values(result.errors || {}).flat().map((message, index) => <p key={index} className="mt-1 text-sm text-amber-800">{message}</p>)}
        {['BLOCKED', 'PENDING_FINAL_SETTLEMENT'].includes(result.status) && <div className="mt-2 flex flex-wrap gap-3 text-sm"><Link to={`/rooms/${result.room_id}/billing-v2`} className="underline">Kiểm tra công tơ, mốc chốt và quyết toán</Link><Link to={`/rooms/${result.room_id}/contracts`} className="underline">Kiểm tra thỏa thuận hợp đồng</Link></div>}
        {(result.invoices || []).map(invoice => <div key={invoice.id} className="mt-3"><Link to={`/invoices/${invoice.id}`} className="underline">{invoice.invoice_code} · {formatCurrency(invoice.total_amount)} · {invoice.status === 'DRAFT' ? 'Nháp' : invoice.status}</Link><p className="text-xs text-slate-500">Kỳ {month} · Hợp đồng #{invoice.contract_id} · Xem hóa đơn để đối chiếu nguồn chứng cứ.</p><details><summary className="cursor-pointer py-2 text-sm">Chi tiết khoản thu</summary><ul className="space-y-1 text-sm">{invoice.items?.map((item, index) => <li key={item.id || index}>{item.description}: {formatCurrency(item.amount)} ({item.source === 'MANUAL' ? 'Thủ công' : (item.description.includes('đã xác nhận') || (item.type === 'RENT' && (invoice.monthly_confirmation?.rent_amount != null || invoice.confirmation?.rent_amount != null))) ? 'AUTO theo xác nhận' : 'AUTO'})</li>)}</ul></details></div>)}
        {(result.invoices || []).length > 0 && <MonthlyDraftUpdatePanel roomId={result.room_id} period={period} initialUsageStartAt={usageWindow.start} initialUsageEndAt={usageWindow.end} confirmations={confirmedInputs.filter(input => result.invoices.some(i => Number(i.contract_id) === Number(input.contract_id)))} onUpdated={invoices => setResults(previous => previous.map(row => row.room_id === result.room_id ? { ...row, invoices } : row))} />}
      </article>)}
    </div>}
  </section>
}
