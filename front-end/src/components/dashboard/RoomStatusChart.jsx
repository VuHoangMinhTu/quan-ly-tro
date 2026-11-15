import { Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ChartTooltip } from './chartPresentation'

export default function RoomStatusChart({ occupied = 0, total = 0 }) {
  if (!total) return <p className="bh-premium-empty">Chưa có phòng ở cơ sở đã chọn.</p>
  const segments = [
    { name: 'Đang thuê', value: occupied, fill: '#087e70' },
    { name: 'Chưa có người', value: total - occupied, fill: '#a8bfd8' },
  ]
  return <figure className="bh-room-status" aria-label="Tình trạng phòng">
    <div className="bh-chart-ring">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={segments} dataKey="value" nameKey="name" innerRadius="67%" outerRadius="95%"
            stroke="white" strokeWidth={2} startAngle={90} endAngle={-270} isAnimationActive={false} />
          <Tooltip content={<ChartTooltip format={value => value + ' phòng'} />} />
        </PieChart>
      </ResponsiveContainer>
      <div className="bh-ring-center" aria-hidden="true"><strong>{total}</strong><span>phòng</span></div>
    </div>
    <figcaption><ul>{segments.map(item => <li key={item.name}>
      <span style={{ background: item.fill }} aria-hidden="true" />
      <span>{item.name}</span><strong>{item.value} · {Math.round(item.value / total * 100)}%</strong>
    </li>)}</ul></figcaption>
  </figure>
}
