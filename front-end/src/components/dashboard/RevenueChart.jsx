import { useId } from 'react'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatCurrency } from '../../utils/formatters'
import { ChartTooltip, moneyTick } from './chartPresentation'

export default function RevenueChart({ months = [] }) {
  const fillId = useId()
  const points = (months ?? []).map(month => ({
    ...month,
    amount: month.amount == null || month.amount === '' || !Number.isFinite(Number(month.amount))
      ? null
      : Number(month.amount),
  }))
  const reported = points.filter(point => point.amount !== null)
  if (!reported.length) return <p className="bh-premium-empty">Chưa có dữ liệu thực thu theo tháng.</p>
  if (reported.every(point => point.amount === 0)) {
    return <p className="bh-premium-empty">Chưa ghi nhận thanh toán trong khoảng thời gian này.</p>
  }

  return <figure className="bh-cash-chart" aria-label="Tiền thực thu theo tháng">
    <figcaption><span aria-hidden="true" /> Thực thu <small>Đơn vị: VND</small></figcaption>
    <div className="bh-area-frame">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={points} margin={{ top: 12, right: 12, bottom: 0, left: 0 }}>
          <defs><linearGradient id={fillId} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="var(--bh-primary)" stopOpacity={0.25} />
            <stop offset="100%" stopColor="var(--bh-primary)" stopOpacity={0.025} />
          </linearGradient></defs>
          <CartesianGrid vertical={false} stroke="var(--bh-line)" strokeDasharray="4 4" />
          <XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={18} tick={{ fontSize: 11 }} />
          <YAxis width={58} tickFormatter={moneyTick} tickLine={false} axisLine={false} tick={{ fontSize: 11 }} />
          <Tooltip content={<ChartTooltip />} />
          <Area dataKey="amount" name="Thực thu" type="linear" stroke="var(--bh-primary)" strokeWidth={2.5}
            fill={'url(#' + fillId + ')'} connectNulls={false} dot={{ r: 3, fill: 'white', strokeWidth: 2 }}
            activeDot={{ r: 5 }} isAnimationActive={false} />
        </AreaChart>
      </ResponsiveContainer>
    </div>
    <details className="bh-chart-values"><summary>Xem số liệu từng tháng</summary>
      <ul>{reported.map(point => <li key={point.month}>{point.label}: {formatCurrency(point.amount)}</li>)}</ul>
    </details>
  </figure>
}
