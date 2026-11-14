import { formatCurrency } from '../../utils/formatters'

const compactMoney = new Intl.NumberFormat('vi-VN', { notation: 'compact', maximumFractionDigits: 1 })
export const moneyTick = value => compactMoney.format(value)

export function ChartTooltip({ active, payload, label, format = formatCurrency }) {
  if (!active || !payload?.length) return null
  return <div className="bh-chart-tooltip" role="status">
    {label != null && <strong>{label}</strong>}
    <dl>{payload.map(item => <div key={item.dataKey ?? item.name}>
      <dt>{item.name}</dt><dd>{format(item.value)}</dd>
    </div>)}</dl>
  </div>
}
