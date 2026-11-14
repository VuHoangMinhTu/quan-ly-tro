import { Bar, BarChart, ResponsiveContainer, XAxis } from 'recharts'

export default function OccupancyByHouse({ houses }) {
  if (!houses.length) return <p className="bh-premium-empty">Không có cơ sở trong phạm vi đã chọn.</p>
  return <div className="bh-occupancy-list">{houses.map(house => {
    const rate = Math.max(0, Math.min(100, Number(house.occupancy_rate) || 0))
    return <article key={house.id}>
      <div><strong>{house.name}</strong><span>{house.occupied_rooms}/{house.total_rooms} phòng · {rate.toLocaleString('vi-VN', { maximumFractionDigits: 2 })}%</span></div>
      <div aria-hidden="true" className="bh-occupancy-chart">
        <ResponsiveContainer width="100%" height={12}>
          <BarChart layout="vertical" data={[{ rate }]} margin={{ top: 0, right: 0, bottom: 0, left: 0 }}>
            <XAxis type="number" domain={[0, 100]} hide />
            <Bar dataKey="rate" fill="#316bb2" background={{ fill: '#eaf0f7', radius: 6 }}
              radius={6} barSize={12} isAnimationActive={false} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </article>
  })}</div>
}
