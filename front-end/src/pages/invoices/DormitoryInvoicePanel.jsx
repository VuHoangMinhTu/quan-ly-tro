import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { generateDormitoryBedInvoice, getMonthlyBillingOptions } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'
import { dormitoryBedCycle } from '../../utils/dormitoryBedCycle.mjs'
import MonthlyDraftUpdatePanel from './MonthlyDraftUpdatePanel'

const messageFor = row => Object.values(row?.errors || {}).flat().join(' · ') || 'Chưa đủ điều kiện tính tiền. Vui lòng kiểm tra hợp đồng.'
const optInAllowed = term => term.billing_method !== 'TIERED'
  && !(term.billing_method === 'PER_UNIT' && ['ELECTRICITY', 'WATER'].includes(term.service_type))

export default function DormitoryInvoicePanel({ houseId, roomId }) {
  const [month, setMonth] = useState(() => new Date().toLocaleDateString('sv-SE').slice(0, 7))
  const [inputs, setInputs] = useState({})
  const [results, setResults] = useState({})
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const client = useQueryClient()
  const period = `${month}-01`
  const options = useQuery({
    queryKey: ['monthly-billing-options', houseId, period],
    queryFn: () => getMonthlyBillingOptions(houseId, period),
    enabled: Boolean(houseId && month),
  })
  const contracts = (options.data?.data?.data || [])
    .filter(c => Number(c.room_id) === Number(roomId) && c.contract_type === 'DORM_BED')
    .slice().sort((a, b) => String(a.bed?.bed_code || '').localeCompare(String(b.bed?.bed_code || ''))
      || String(a.start_date || '').localeCompare(String(b.start_date || '')))
  const setField = (id, field, value) => setInputs(previous => ({
    ...previous, [id]: { ...previous[id], [field]: value },
  }))

  const createOne = async contract => {
    const values = inputs[contract.id] || {}
    const selected = (contract.agreed_services || [])
      .filter(term => values[`extra_${term.id}`])
      .map(term => ({ term_id: term.id, quantity: values[`quantity_${term.id}`] || '', amount: values[`amount_${term.id}`] || '' }))
    const hasRent = values.rent !== undefined && values.rent !== ''
    const confirmation = {
      ...(hasRent ? { rent_amount: values.rent } : {}),
      ...(selected.length ? { extra_service_confirmed: Boolean(values.extraConfirmed), services: selected } : {}),
      ...((hasRent || selected.length) ? { reason: values.reason || '' } : {}),
    }
    const response = await generateDormitoryBedInvoice(contract.id, { billing_period: period,
      ...(Object.keys(confirmation).length ? { confirmation } : {}),
    })
    const result = response.data.data
    setResults(previous => ({ ...previous, [contract.id]: result }))
    setErrors(previous => ({ ...previous, [contract.id]: result.status === 'BLOCKED' ? messageFor(result) : '' }))
    if (result.status === 'CREATED' || result.status === 'EXISTING') {
      await client.invalidateQueries({ queryKey: ['invoices'] })
    }
    return result
  }

  const submit = async contractList => {
    if (busy) return
    setBusy(true)
    try {
      // Deliberately isolate requests. One bed failing does not roll back another bed.
      for (const contract of contractList) {
        try {
          await createOne(contract)
        } catch (error) {
          setErrors(previous => ({ ...previous, [contract.id]: error.billingV2Message || getApiErrorMessage(error) }))
        }
      }
    } finally { setBusy(false) }
  }

  return <section className="mt-5 space-y-5 rounded-xl border bg-white p-4" aria-label="Hóa đơn tiền giường KTX">
    <h3 className="font-semibold">Tạo hóa đơn KTX theo từng người thuê giường</h3>
    <p className="text-sm text-slate-600">Mỗi hợp đồng có một kỳ thuê và hóa đơn riêng. Ngày trên từng thẻ được lấy từ ngày bắt đầu hợp đồng. Điện, nước, mạng và dịch vụ đã bao gồm trong giá giường, mặc định không thu thêm.</p>
    <label className="block text-sm">Tháng quản lý hóa đơn
      <input type="month" value={month} disabled={busy} className="mt-1 block min-h-11 w-full max-w-xs rounded border p-2"
        onChange={e => { setMonth(e.target.value); setResults({}); setErrors({}) }} />
    </label>
    {options.isPending && <p>Đang tải các hợp đồng thuê giường...</p>}
    {options.isError && <p role="alert" className="text-red-700">{getApiErrorMessage(options.error)}</p>}
    {!options.isPending && !options.isError && contracts.length === 0 && <p className="rounded bg-slate-50 p-3 text-sm">Không có hợp đồng thuê giường hợp lệ trong tháng này.</p>}
    {contracts.map(contract => {
      const cycle = dormitoryBedCycle(contract.start_date, month)
      const values = inputs[contract.id] || {}
      const result = results[contract.id]
      const allowed = (contract.agreed_services || []).filter(term => term.is_active && optInAllowed(term))
      return <article key={contract.id} className="space-y-3 rounded-lg border border-slate-300 p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="font-semibold">Giường {contract.bed?.bed_code || `#${contract.bed_id}`} · {contract.tenant?.full_name || contract.contract_code || `Hợp đồng #${contract.id}`}</p>
            <p className="text-sm text-slate-600">Hợp đồng {contract.contract_code || `#${contract.id}`} · Giá giường {formatCurrency(contract.monthly_rent)}</p>
          </div>
          <span className="rounded bg-slate-100 px-2 py-1 text-xs">{contract.status}</span>
        </div>
        {cycle ? <p className="rounded bg-blue-50 p-3 text-sm text-blue-900"><strong>Kỳ thuê riêng:</strong> {cycle.start.split('-').reverse().join('/')} → {cycle.end.split('-').reverse().join('/')}. Không dùng kỳ của người thuê giường khác.</p>
          : <p className="text-sm text-amber-800">Hợp đồng chưa bắt đầu trong tháng này; không tạo hóa đơn.</p>}
        <details className="rounded border bg-slate-50 p-3 text-sm">
          <summary className="cursor-pointer font-medium">Trả sớm hoặc có thỏa thuận thu riêng? (tùy chọn)</summary>
          <p className="mt-2 text-slate-600">Chỉ nhập tiền giường xác nhận khi hợp đồng bị kết thúc giữa kỳ. Không tự chia tiền theo ngày.</p>
          <label className="mt-2 block">Tiền giường chủ trọ xác nhận cho kỳ lẻ (đ)
            <input className="mt-1 block min-h-11 w-full rounded border p-2" type="number" min="0" step="0.01" value={values.rent || ''} disabled={busy}
              onChange={e => setField(contract.id, 'rent', e.target.value)} placeholder="Để trống nếu thuê đủ tháng" />
          </label>
          {allowed.length > 0 && <div className="mt-3 space-y-3">
            <p className="font-medium">Dịch vụ có thỏa thuận thu thêm (mặc định không chọn)</p>
            {allowed.map(term => <div key={term.id} className="rounded border bg-white p-2">
              <label className="flex items-center gap-2"><input type="checkbox" checked={Boolean(values[`extra_${term.id}`])} disabled={busy}
                onChange={e => setField(contract.id, `extra_${term.id}`, e.target.checked)} />{term.service_name} · {term.billing_method}</label>
              {values[`extra_${term.id}`] && <div className="mt-2 grid gap-2 sm:grid-cols-2">
                <label>Số lượng<input type="number" min="0.01" step="0.01" className="mt-1 block w-full rounded border p-2" value={values[`quantity_${term.id}`] || ''} disabled={busy}
                  onChange={e => setField(contract.id, `quantity_${term.id}`, e.target.value)} /></label>
                <label>Số tiền (đ)<input type="number" min="0.01" step="0.01" className="mt-1 block w-full rounded border p-2" value={values[`amount_${term.id}`] || ''} disabled={busy}
                  onChange={e => setField(contract.id, `amount_${term.id}`, e.target.value)} /></label>
              </div>}
            </div>)}
            {allowed.some(term => values[`extra_${term.id}`]) && <label className="flex items-start gap-2"><input type="checkbox" checked={Boolean(values.extraConfirmed)} disabled={busy}
              onChange={e => setField(contract.id, 'extraConfirmed', e.target.checked)} />Tôi xác nhận có thỏa thuận thu riêng dịch vụ này.</label>}
          </div>}
          <label className="mt-3 block">Lý do / thỏa thuận khi điều chỉnh
            <textarea className="mt-1 block min-h-20 w-full rounded border p-2" maxLength={1000} value={values.reason || ''} disabled={busy}
              onChange={e => setField(contract.id, 'reason', e.target.value)} placeholder="Ví dụ: Chủ trọ xác nhận số tiền thuê do trả giường giữa kỳ" />
          </label>
        </details>
        <button type="button" disabled={busy || !cycle} onClick={() => submit([contract])}
          className="min-h-11 rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">{busy ? 'Đang xử lý...' : 'Tạo / xem hóa đơn giường này'}</button>
        {errors[contract.id] && <p role="alert" className="rounded bg-amber-50 p-3 text-sm text-amber-900">{errors[contract.id]}</p>}
        {result?.status === 'CREATED' && <p className="text-sm text-green-800">Đã tạo hóa đơn nháp cho hợp đồng này.</p>}
        {result?.status === 'EXISTING' && <p className="text-sm text-slate-700">Hóa đơn hợp đồng này đã tồn tại, không tạo trùng.</p>}
        {(result?.invoices || []).map(invoice => <div key={invoice.id} className="space-y-2">
          <Link to={`/invoices/${invoice.id}`} className="block text-sm underline">
            {invoice.invoice_code} · {formatCurrency(invoice.total_amount)} · {invoice.status === 'DRAFT' ? 'Nháp' : invoice.status}
          </Link>
          {invoice.status === 'DRAFT' && <details className="rounded border p-3 text-sm">
            <summary className="cursor-pointer font-medium">Cập nhật bản nháp của giường này</summary>
            <p className="mt-2 text-slate-600">Chỉ áp dụng thay đổi cho hợp đồng này; không cập nhật hóa đơn giường khác. Nếu thay đổi tiền thuê hoặc thu thêm dịch vụ, hãy nhập xác nhận ở phần phía trên trước.</p>
            <MonthlyDraftUpdatePanel roomId={roomId} contractId={contract.id} period={period}
              initialUsageStartAt={`${cycle.start}T00:00`} initialUsageEndAt={`${cycle.end}T00:00`}
              confirmations={(() => {
                const selected = (contract.agreed_services || []).filter(term => values[`extra_${term.id}`])
                  .map(term => ({ term_id: term.id, quantity: values[`quantity_${term.id}`] || '', amount: values[`amount_${term.id}`] || '' }))
                const input = { contract_id: contract.id }
                if (values.rent !== undefined && values.rent !== '') input.rent_amount = values.rent
                if (selected.length) { input.services = selected; input.extra_service_confirmed = Boolean(values.extraConfirmed) }
                if (input.rent_amount !== undefined || selected.length) input.reason = values.reason || ''
                return Object.keys(input).length > 1 ? [input] : []
              })()}
              onUpdated={invoices => setResults(previous => ({ ...previous,
                [contract.id]: { ...previous[contract.id], invoices } }))} />
          </details>}
        </div>)}
      </article>
    })}
    {contracts.length > 1 && <button type="button" disabled={busy} onClick={() => submit(contracts.filter(c => dormitoryBedCycle(c.start_date, month)))}
      className="min-h-11 rounded border border-slate-700 px-4 py-2 text-sm disabled:opacity-50">Tạo / kiểm tra lần lượt tất cả hợp đồng trong tháng</button>}
    <p className="text-xs text-slate-600">Chỉ tạo bản nháp hoặc hiển thị hóa đơn đã có. Không tự phát hành, không ghi nhận thanh toán. Nếu hợp đồng trả sớm hoặc bỏ ngang, hệ thống có thể yêu cầu đối soát riêng.</p>
  </section>
}
