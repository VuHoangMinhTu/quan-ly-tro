import { formatCurrency } from '../../utils/formatters'

const axisFormat = new Intl.NumberFormat('vi-VN', { notation: 'compact', maximumFractionDigits: 1 })

export default function RevenueChart({ months = [] }) {
  const points = months.filter(month => month.amount != null && month.amount !== '' && Number.isFinite(Number(month.amount)))
  if (!points.length) return <p className="bh-premium-empty">Chưa có dữ liệu thực thu theo tháng.</p>
  const maximum = Math.max(0, ...points.map(point => Number(point.amount)))
  if (!maximum) return <p className="bh-premium-empty">Chưa ghi nhận thanh toán trong khoảng thời gian này.</p>

  const magnitude = 10 ** Math.floor(Math.log10(maximum / 4))
  const step = Math.ceil(maximum / (4 * magnitude)) * magnitude
  const ceiling = step * 4

  return <figure className="bh-cash-chart" aria-label="Tiền thực thu theo tháng">
    <figcaption><span aria-hidden="true" /> Thực thu <small>Đơn vị: VND</small></figcaption>
    <div className="bh-cash-plot">
      <div className="bh-cash-axis" aria-hidden="true">
        {[4, 3, 2, 1, 0].map(i => <span key={i}>{axisFormat.format(i * step)}</span>)}
      </div>
      <ol className="bh-cash-series" style={{ '--months': points.length }}>
        {points.map(point => {
          const amount = Number(point.amount)
          const label = point.label
          return <li key={point.month}>
            <button type="button" className="bh-cash-point" aria-label={label + ': ' + formatCurrency(amount)} onClick={event => event.currentTarget.focus()}>
              <span className="bh-cash-bar" style={{ height: Math.max(0, amount / ceiling * 100) + '%' }} />
              <span className="bh-cash-tooltip" aria-hidden="true"><span>{label}</span><strong>{formatCurrency(amount)}</strong></span>
            </button>
            <span className="bh-cash-month" aria-hidden="true">{label}</span>
          </li>
        })}
      </ol>
    </div>
  </figure>
}
