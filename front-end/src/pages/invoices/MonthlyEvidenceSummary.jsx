const dateText = (value) => {
  if (!value) return 'chưa rõ ngày'
  const text = String(value).replace('T', ' ')
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?/)
  if (!match) return text
  const [, year, month, day, hour, minute] = match
  return `${day}/${month}/${year}${hour ? ` lúc ${hour}:${minute}` : ''}`
}

const methodText = (method) => ({ PER_UNIT: 'Theo số sử dụng', FIXED: 'Phí cố định', PER_PERSON: 'Theo số người', TIERED: 'Theo bậc sử dụng' }[method] || 'Theo thỏa thuận')

export default function MonthlyEvidenceSummary({ invoice }) {
  const evidence = invoice.monthly_evidence
  const terms = evidence?.snapshot?.sources?.terms || []
  const periods = evidence?.snapshot?.usage_periods || []
  const updates = invoice.monthly_recalculations || []
  if (!evidence) return null

  return <section className="mb-4 rounded-xl border bg-white p-4 print-hide">
    <h3 className="font-semibold">Chi tiết tính tiền</h3>
    <p className="mt-2 text-sm text-slate-700">Hóa đơn tháng {invoice.billing_period?.slice(5, 7)}/{invoice.billing_period?.slice(0, 4)} được tính theo hợp đồng và các khoản dịch vụ đã đăng ký.</p>
    {terms.length > 0 && <div className="mt-3">
      <p className="text-sm font-medium">Dịch vụ của phòng</p>
      <ul className="mt-1 space-y-1 text-sm text-slate-700">{terms.map((term, index) => <li key={term.id || index}>{term.service_name}: {methodText(term.billing_method)}</li>)}</ul>
    </div>}
    {periods.length > 0 && <div className="mt-3">
      <p className="text-sm font-medium">Kỳ ghi điện, nước</p>
      <ul className="mt-1 space-y-1 text-sm text-slate-700">{periods.map((period, index) => <li key={`${period.utility_meter_id}-${index}`}>Đồng hồ {period.utility_meter_id}: {dateText(period.usage_start_at)} – {dateText(period.usage_end_at)}. Đã ghi nhận chỉ số đầu và cuối kỳ.</li>)}</ul>
    </div>}
    {updates.length > 0 && <p className="mt-3 text-sm text-emerald-800">Đã cập nhật lại tiền điện, nước vào hóa đơn lúc {dateText(updates[updates.length - 1].approved_at)}. Hóa đơn vẫn là bản nháp nếu chưa phát hành.</p>}
    <details className="mt-3 border-t pt-3 text-sm">
      <summary className="cursor-pointer font-medium text-slate-700">Lịch sử và thông tin đối soát</summary>
      <div className="mt-2 space-y-2 text-slate-600">
        <p>Hợp đồng #{invoice.contract_id}. Hóa đơn tạo lúc {dateText(evidence.created_at)}.</p>
        {evidence.confirmation?.reason && <p>Ghi chú khi lập hóa đơn: {evidence.confirmation.reason}</p>}
        {periods.map((period, index) => <p key={`${period.utility_meter_id}-${index}`}>Đồng hồ #{period.utility_meter_id}: mốc đầu #{period.start_checkpoint_id}, mốc cuối #{period.end_checkpoint_id}. Người ghi nhận: #{period.start_confirmed_by} và #{period.end_confirmed_by}. Tính tiền lúc {dateText(period.calculated_at)}.</p>)}
        {updates.map(entry => <p key={entry.id}>Cập nhật lần {entry.id} lúc {dateText(entry.approved_at)}{entry.reason ? ` · Ghi chú: ${entry.reason}` : ''}</p>)}
      </div>
    </details>
  </section>
}
