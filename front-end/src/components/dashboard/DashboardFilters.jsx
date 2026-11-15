import { CalendarDays, RotateCcw, SlidersHorizontal } from 'lucide-react'
import { dashboardPresets } from './dashboardFilters'

export default function DashboardFilters({ filter, houses, onChange, onReset }) {
  const change = values => onChange({ ...filter, ...values })
  return <section className="bh-dashboard-filters" aria-label="Bộ lọc thống kê">
    <div className="bh-filter-controls">
      <label><span>Kỳ xem</span><select value={filter.preset} onChange={event => change({ preset: event.target.value, anchor: '' })}>{dashboardPresets.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
      <label><span>Cơ sở</span><select value={filter.houseId} onChange={event => change({ houseId: event.target.value })}><option value="">Tất cả cơ sở</option>{houses.map(house => <option key={house.id} value={house.id}>{house.name}</option>)}</select></label>
    </div>
    <details className="bh-premium-advanced">
      <summary><SlidersHorizontal size={16} aria-hidden="true" /> Nâng cao</summary>
      <div className="bh-premium-options"><div className="bh-filter-controls">
      {['week', 'month', 'quarter', 'year'].includes(filter.preset) && <label><span>Ngày trong kỳ</span><input type="date" value={filter.anchor} onChange={event => change({ anchor: event.target.value })} /></label>}
      <label><span>Nhóm theo</span><select value={filter.group} onChange={event => change({ group: event.target.value })}>
        <option value="">Tự động</option><option value="day">Ngày</option><option value="week">Tuần</option><option value="month">Tháng</option><option value="quarter">Quý</option><option value="year">Năm</option>
      </select></label>
      {(filter.preset === 'day' || filter.preset === 'custom') && <label><span>{filter.preset === 'day' ? 'Ngày' : 'Từ ngày'}</span><input type="date" value={filter.start} onChange={event => change(filter.preset === 'day' ? { start: event.target.value, end: event.target.value } : { start: event.target.value, end: event.target.value > filter.end ? event.target.value : filter.end })} /></label>}
      {filter.preset === 'custom' && <label><span>Đến ngày</span><input type="date" min={filter.start} value={filter.end} onChange={event => change({ end: event.target.value })} /></label>}
    </div>
    <div className="bh-filter-actions"><label className="bh-compare"><input type="checkbox" checked={filter.compare} onChange={event => change({ compare: event.target.checked })} /> <span>So sánh kỳ trước</span></label><button type="button" onClick={onReset}><RotateCcw size={16} aria-hidden="true" /> Đặt lại</button><span className="bh-filter-applied"><CalendarDays size={16} aria-hidden="true" /> Múi giờ Việt Nam</span></div>
      </div>
    </details>
    <p className="bh-premium-filter-note">Hiện trạng phòng và hợp đồng sắp hết hạn được tính tại thời điểm xem.</p>
  </section>
}
