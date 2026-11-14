import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChartTooltip, moneyTick } from './chartPresentation'

function SeriesChart({ rows, bars, line, label, count = false }) {
  if (!rows?.length) return null
  return <figure aria-label={label}>
    <div className="bh-series-frame">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={rows} margin={{ top: 10, right: 10, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--bh-line)" strokeDasharray="4 4" />
          <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fontSize: 11 }} minTickGap={12} />
          <YAxis width={count ? 28 : 57} allowDecimals={false} tickFormatter={count ? undefined : moneyTick}
            tickLine={false} axisLine={false} tick={{ fontSize: 11 }} />
          <Tooltip content={<ChartTooltip format={count ? value => value + ' hợp đồng' : undefined} />} />
          <Legend iconSize={9} wrapperStyle={{ fontSize: 12 }} />
          {bars.map(([key, name, color]) => <Bar key={key} dataKey={key} name={name} fill={color}
            maxBarSize={26} radius={[4, 4, 0, 0]} isAnimationActive={false} />)}
          {line && <Line dataKey={line[0]} name={line[1]} stroke={line[2]} strokeWidth={2.5} isAnimationActive={false} />}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  </figure>
}

export function InvoiceHistoryChart({ rows }) {
  return <SeriesChart rows={rows} label="Hóa đơn và công nợ theo kỳ"
    bars={[[ 'billed', 'Giá trị hóa đơn', '#316bb2' ], [ 'paid', 'Đã thanh toán', '#087e70' ], [ 'due', 'Còn phải thu', '#bd701e' ]]} />
}

export function IncomeExpenseChart({ rows }) {
  return <SeriesChart rows={rows} label="Thu và chi theo kỳ"
    bars={[[ 'received', 'Thực thu', '#087e70' ]]} line={['expense', 'Tổng chi', '#bd701e']} />
}

export function ContractExpiryChart({ rows }) {
  return <SeriesChart rows={rows} label="Hợp đồng sắp hết hạn theo khoảng"
    bars={[[ 'count', 'Hợp đồng sắp hết hạn', '#316bb2' ]]} count />
}
