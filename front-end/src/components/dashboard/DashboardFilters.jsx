import { CalendarDays, RotateCcw, SlidersHorizontal } from 'lucide-react'
import { dashboardPresets, filterLabel } from './dashboardFilters'

export default function DashboardFilters({ filter, houses, onChange, onReset }) {
  const change = values => onChange({ ...filter, ...values })
  return <section className="bh-dashboard-filters" aria-label="Bộ lọc thống kê">
    <div className="bh-filter-title"><span className="bh-filter-icon"><SlidersHorizontal size={18} aria-hidden="true" /></span><div><h3>Bộ lọc thống kê</h3><p>Múi giờ Việt Nam</p></div></div>
    <p className="bh-chart-empty"><strong>Chưa hỗ trợ lọc theo thời gian hoặc so sánh kỳ.</strong> Kỳ xem chỉ lưu lựa chọn. Cơ sở chỉ lọc số liệu phòng và danh sách cơ sở. Tài chính tổng hợp, hợp đồng và người thuê vẫn bao gồm tất cả cơ sở được cấp quyền.</p>
    <div className="bh-filter-controls">
      <label><span>Kỳ xem</span><select value={filter.preset} onChange={event => change({ preset: event.target.value })}>{dashboardPresets.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
      <label><span>Cơ sở</span><select value={filter.houseId} onChange={event => change({ houseId: event.target.value })}><option value="">Tất cả cơ sở</option>{houses.map(house => <option key={house.id} value={house.id}>{house.name}</option>)}</select></label>
      {(filter.preset === 'day' || filter.preset === 'custom') && <label><span>{filter.preset === 'day' ? 'Ngày' : 'Từ ngày'}</span><input type="date" value={filter.start} onChange={event => change(filter.preset === 'day' ? { start: event.target.value, end: event.target.value } : { start: event.target.value, end: event.target.value > filter.end ? event.target.value : filter.end })} /></label>}
      {filter.preset === 'custom' && <label><span>Đến ngày</span><input type="date" min={filter.start} value={filter.end} onChange={event => change({ end: event.target.value })} /></label>}
    </div>
    <div className="bh-filter-actions"><label className="bh-compare"><input type="checkbox" disabled checked={false} /> <span>So sánh kỳ trước (chưa hỗ trợ)</span></label><button type="button" onClick={onReset}><RotateCcw size={16} aria-hidden="true" /> Đặt lại</button><span className="bh-filter-applied"><CalendarDays size={16} aria-hidden="true" /> Kỳ đã lưu · {filterLabel(filter)}</span></div>
  </section>
}
