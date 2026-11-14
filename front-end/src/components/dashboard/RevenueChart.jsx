import { formatCurrency } from '../../utils/formatters'

export default function RevenueChart({ months }) {
  const maximum = Math.max(0, ...months.map(month => Number(month.amount)))
  return <figure className="bh-revenue-chart min-w-0" aria-label="Tiền thực thu theo tháng"><figcaption>Tổng Payment theo ngày thanh toán, gồm tiền mặt và payOS. Không phải lợi nhuận.</figcaption><div className="bh-revenue-bars" aria-label="Số tiền theo tháng">{months.map(month => <div key={month.month} className="bh-revenue-column" tabIndex={0} aria-label={`${month.label}: ${formatCurrency(month.amount)}`}><div className="bh-revenue-value">{formatCurrency(month.amount)}</div><div className="bh-revenue-column-track"><div className="bh-revenue-column-fill" hidden={Number(month.amount) === 0} style={{ height: maximum > 0 ? `${Math.min(100, Number(month.amount) / maximum * 100)}%` : '0%' }} /></div><span>{month.label}</span></div>)}</div>{maximum === 0 && <p className="bh-chart-empty">Chưa ghi nhận thanh toán trong khoảng thời gian này.</p>}</figure>
}
