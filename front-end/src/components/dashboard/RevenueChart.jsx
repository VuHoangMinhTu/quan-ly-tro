import { formatCurrency } from '../../utils/formatters'

export default function RevenueChart({ months }) {
  const maximum = Math.max(0, ...months.map(month => Number(month.amount)))
  return <figure className="min-w-0 space-y-4" aria-label="Tiền thực thu theo tháng"><figcaption className="text-sm text-slate-500">Tổng Payment theo ngày thanh toán, gồm tiền mặt và payOS. Không phải lợi nhuận.</figcaption><ol className="space-y-4">{months.map(month => <li key={month.month} className="min-w-0"><div className="mb-1 flex flex-wrap justify-between gap-x-3 gap-y-1 text-sm"><span className="font-medium">{month.label}</span><span className="break-words font-semibold tabular-nums">{formatCurrency(month.amount)}</span></div><div aria-hidden="true" className="h-3 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-slate-800" style={{ width: maximum > 0 ? `${Math.min(100, Number(month.amount) / maximum * 100)}%` : '0%' }} /></div></li>)}</ol>{maximum === 0 && <p className="text-sm text-slate-500">Chưa ghi nhận thanh toán trong khoảng thời gian này.</p>}</figure>
}
