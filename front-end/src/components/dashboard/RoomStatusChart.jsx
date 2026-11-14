export default function RoomStatusChart({ occupied = 0, total = 0 }) {
  const vacant = Math.max(0, total - occupied); const rate = total ? Math.round(occupied / total * 100) : 0
  const segments = [{ label: 'Đang thuê', value: occupied, color: '#0f766e' }, { label: 'Chưa có người', value: vacant, color: '#cbd5e1' }]
  let cursor = 0
  return <figure className="bh-room-status" aria-label="Tình trạng phòng">
    <div className="bh-donut" role="img" aria-label={`${occupied} phòng đang thuê, ${vacant} phòng chưa có người trên ${total} phòng`}><svg viewBox="0 0 42 42" aria-hidden="true">{segments.map(item => { const dash = total ? item.value / total * 100 : 0; const node = <circle key={item.label} cx="21" cy="21" r="15.9155" fill="transparent" stroke={item.color} strokeWidth="5" strokeDasharray={`${dash} ${100 - dash}`} strokeDashoffset={-cursor} />; cursor += dash; return node })}</svg><div><strong>{total}</strong><span>phòng</span></div></div>
    <figcaption><ul>{segments.map(item => <li key={item.label}><span style={{ background: item.color }} aria-hidden="true" /> <span>{item.label}</span><strong>{item.value} · {total ? Math.round(item.value / total * 100) : 0}%</strong></li>)}</ul><p>{rate}% lấp đầy, xác định theo người ở ACTIVE.</p></figcaption>
  </figure>
}
