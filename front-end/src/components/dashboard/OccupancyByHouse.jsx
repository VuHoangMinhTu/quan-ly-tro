export default function OccupancyByHouse({ houses }) {
  if (!houses.length) return <p className="bh-chart-empty">Không có cơ sở trong phạm vi đã chọn.</p>
  return <div className="bh-occupancy-list">{houses.map(house => { const rate = Math.max(0, Math.min(100, Number(house.occupancy_rate) || 0)); return <article key={house.id}><div><strong>{house.name}</strong><span>{house.occupied_rooms}/{house.total_rooms} phòng · {rate.toLocaleString('vi-VN')}%</span></div><div className="bh-occupancy-track" role="progressbar" aria-label={`Tỷ lệ lấp đầy ${house.name}`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={rate}><span style={{ width: `${rate}%` }} /></div></article> })}</div>
}
