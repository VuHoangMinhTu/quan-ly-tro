import { Building2, DoorOpen, Users, FileText, Receipt, WalletCards, CircleDollarSign, ChartNoAxesCombined, AlertCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { formatCurrency, formatDate } from '../../utils/formatters'
import InvoiceStatusBadge from '../invoices/InvoiceStatusBadge'
import RevenueChart from './RevenueChart'
import RoomStatusChart from './RoomStatusChart'
import OccupancyByHouse from './OccupancyByHouse'

function Kpi({ label, value, description, icon: Icon, tone = 'default' }) { return <article className="bh-dashboard-kpi" data-tone={tone}><div><h3>{label}</h3><span className="bh-dashboard-kpi-icon"><Icon size={19} aria-hidden="true" /></span></div><p>{value}</p><small>{description}</small></article> }
function Panel({ title, children, subtitle }) { return <section className="bh-dashboard-panel"><header><div><h3>{title}</h3>{subtitle && <p>{subtitle}</p>}</div></header>{children}</section> }
function Unavailable({ children }) { return <div className="bh-unavailable"><AlertCircle size={19} aria-hidden="true" /><p>{children}</p></div> }

export default function DashboardContent({ data, filter, controls, headerLabel, invoiceChart, expenseChart, contractChart, contractPeriodChart, demoCash = false }) {
  const { viewer, summary, invoice_summary: invoices, boarding_houses: allHouses, revenue_chart: months, recent_unpaid_invoices: recent, expiring_contracts: contracts, period } = data
  const analytics = data.analytics
  const cashSupported = demoCash || analytics?.availability?.cash === 'supported'
  const received = demoCash
    ? months?.find(month => month.month === period.month || month.label === period.label)?.amount
    : analytics?.totals.cash_received
  const houses = filter?.houseId ? allHouses.filter(house => String(house.id) === String(filter.houseId)) : allHouses
  const firstOwnedHouse = allHouses.find(house => house.operational_role === 'OWNER')
  const scopedOccupied = houses.reduce((sum, house) => sum + Number(house.occupied_rooms), 0)
  const scopedTotal = houses.reduce((sum, house) => sum + Number(house.total_rooms), 0)
  if (summary.total_boarding_houses === 0) return <section className="bh-empty-state"><h2>Bạn chưa có nhà trọ.</h2><p>Tạo nhà trọ đầu tiên để bắt đầu quản lý phòng, hợp đồng và hóa đơn. Nếu bạn là quản lý, hãy nhờ chủ workspace phân công nhà trọ.</p>{viewer.can_create_boarding_house && <Link to="/boarding-houses/new" className="bh-primary-button">Tạo nhà trọ đầu tiên</Link>}</section>
  return <div className="bh-dashboard">
    <header className="bh-premium-header">
      <div>{headerLabel}<h2>Tổng quan</h2><p>Theo dõi phòng thuê, dòng tiền và các việc cần xử lý.</p></div>
      {controls}
    </header>
    {summary.total_rooms === 0 && <div className="bh-empty-state"><h3>Nhà trọ chưa có phòng.</h3><p>{firstOwnedHouse ? 'Thêm phòng để bắt đầu theo dõi tình hình vận hành.' : 'Vui lòng liên hệ chủ workspace để bổ sung phòng.'}</p>{firstOwnedHouse && <Link to={`/boarding-houses/${firstOwnedHouse.id}/rooms/new`} className="bh-primary-button">Thêm phòng</Link>}</div>}
    <section aria-label="Chỉ số chính"><div className="bh-premium-section"><h3>Chỉ số chính</h3><span>Hiện trạng phòng · Hóa đơn tháng {period.label}</span></div><div className="bh-kpi-grid">
      <Kpi icon={DoorOpen} label="Tổng số phòng" value={scopedTotal} description={`${scopedOccupied} có người · ${scopedTotal - scopedOccupied} chưa có người`} />
      <Kpi icon={Building2} label="Đang thuê" value={scopedOccupied} description="Có người đang cư trú" />
      <Kpi icon={DoorOpen} label="Phòng chưa có người" value={scopedTotal - scopedOccupied} description="Có thể đang đặt hoặc bảo trì" />
      <Kpi icon={ChartNoAxesCombined} label="Tỷ lệ lấp đầy" value={`${Number((scopedTotal ? scopedOccupied / scopedTotal * 100 : 0).toFixed(2)).toLocaleString('vi-VN')}%`} description={`${scopedOccupied}/${scopedTotal} phòng đang có người`} />
      {cashSupported && <Kpi icon={WalletCards} label={analytics ? 'Thực thu trong kỳ' : 'Thực thu tháng'} value={received == null ? '—' : formatCurrency(received)} description={`Thanh toán ghi nhận trong ${period.label}`} tone="positive" />}
      <Kpi icon={CircleDollarSign} label="Còn phải thu" value={formatCurrency(invoices.outstanding_amount)} description={`Công nợ hóa đơn ${period.label}`} tone={Number(invoices.outstanding_amount) ? 'warning' : 'default'} />
      <Kpi icon={Receipt} label="Hóa đơn chưa thanh toán" value={invoices.unpaid_invoice_count} description={`${invoices.overdue_invoice_count} quá hạn · mọi cơ sở, mọi kỳ`} tone={invoices.overdue_invoice_count ? 'danger' : 'default'} />
      <Kpi icon={FileText} label="Hợp đồng sắp hết hạn" value={summary.expiring_soon_contracts} description={`Trong ${summary.expiring_window_days} ngày tới`} tone={summary.expiring_soon_contracts ? 'warning' : 'default'} />
    </div></section>
    <div className="bh-dashboard-split bh-premium-rooms"><Panel title="Tình trạng phòng" subtitle="Tình trạng hiện tại trong phạm vi được phép xem."><RoomStatusChart occupied={scopedOccupied} total={scopedTotal} /></Panel><Panel title="Tỷ lệ lấp đầy theo cơ sở" subtitle="Số phòng có người ở trên tổng số phòng."><OccupancyByHouse houses={houses} /></Panel></div>
    <Panel title="Thực thu theo thời gian" subtitle={cashSupported && analytics ? 'Theo ngày thanh toán · ' + period.label : undefined}>
      {cashSupported ? <RevenueChart months={months} timeLabel={analytics ? 'khoảng' : 'tháng'} /> : <p className="bh-premium-empty">Thống kê thực thu theo thời gian đang được hoàn thiện để đảm bảo độ chính xác của dữ liệu.</p>}
    </Panel>
    <Panel title="Hóa đơn và công nợ theo kỳ" subtitle={analytics ? 'Theo ngày ghi trên kỳ hóa đơn · ' + period.label : 'Giá trị hóa đơn, đã thanh toán và còn phải thu'}>
      {invoiceChart ?? <p className="bh-premium-empty">Chưa có lịch sử hóa đơn theo kỳ. Xem tổng hợp hóa đơn bên dưới.</p>}
    </Panel>
    {contractPeriodChart && <Panel title="Bắt đầu và kết thúc hợp đồng" subtitle={'Theo ngày ghi trong hợp đồng · ' + period.label}>{contractPeriodChart}</Panel>}
    {analytics?.previous && <Panel title="Kỳ trước" subtitle={formatDate(analytics.previous.from) + ' đến trước ' + formatDate(analytics.previous.to_exclusive)}>
      <dl className="bh-invoice-metrics">{cashSupported && <div><dt>Thực thu</dt><dd>{formatCurrency(analytics.previous.totals.cash_received)}</dd></div>}<div><dt>Giá trị hóa đơn</dt><dd>{formatCurrency(analytics.previous.totals.invoice_value)}</dd></div><div><dt>Đã thanh toán cho hóa đơn</dt><dd>{formatCurrency(analytics.previous.totals.invoice_paid_current)}</dd></div><div><dt>Công nợ hiện tại của kỳ trước</dt><dd>{formatCurrency(analytics.previous.totals.invoice_due_current)}</dd></div></dl>
    </Panel>}
    <div className="bh-dashboard-split bh-premium-finance">
      <Panel title={cashSupported ? 'Thu — chi' : 'Tổng hợp hóa đơn'} subtitle={analytics ? period.label : `Tất cả cơ sở · Tháng ${period.label}`}>
        <dl className="bh-invoice-metrics">
          {cashSupported && <div><dt>{analytics ? 'Thực thu trong kỳ' : 'Thực thu tháng'}</dt><dd>{received == null ? '—' : formatCurrency(received)}</dd></div>}
          <div><dt>Giá trị hóa đơn</dt><dd>{formatCurrency(invoices.invoice_total)}</dd></div>
          <div><dt>Đã thanh toán cho hóa đơn kỳ này</dt><dd>{formatCurrency(invoices.paid_amount)}</dd></div>
          <div><dt>Còn phải thu của kỳ</dt><dd>{formatCurrency(invoices.outstanding_amount)}</dd></div>
        </dl>
        {cashSupported && (expenseChart ?? <Unavailable>Chưa có dữ liệu chi phí. Biểu đồ thu — chi chưa khả dụng.</Unavailable>)}
      </Panel>
      <Panel title="Hợp đồng sắp hết hạn" subtitle={`Hợp đồng hiệu lực, kết thúc trong ${summary.expiring_window_days} ngày tới; danh sách bên dưới gồm tối đa 5 mục từ mọi cơ sở được cấp quyền.`}>{contractChart ?? <p className="bh-chart-empty">Chưa có thống kê hết hạn theo khoảng.</p>}{contracts.length ? <ul className="bh-action-list">{contracts.map(contract => <li key={contract.id}><Link to={`/contracts/${contract.id}`} state={{ returnTo: '/dashboard', returnLabel: 'Dashboard' }}><strong>Phòng {contract.room_code} · {contract.contract_code}</strong><p>{contract.tenant_name || 'Người thuê không còn trong danh mục'}</p><div><span>Hết hạn: {formatDate(contract.end_date)}</span><span>{contract.days_remaining === 0 ? 'Hết hạn hôm nay' : `Còn ${contract.days_remaining} ngày`}</span></div></Link></li>)}</ul> : <p className="bh-chart-empty">Không có hợp đồng sắp hết hạn.</p>}</Panel>
    </div>
    <div className="bh-premium-bottom">
      <Panel title="Tình hình cơ sở" subtitle="Danh sách theo bộ lọc cơ sở."><div className="bh-house-grid">{houses.map(house => <article key={house.id}><div><h4>{house.name}</h4><p>{house.operational_role === 'OWNER' ? 'Nhà trọ của bạn' : 'Được phân công quản lý'}</p></div><dl><div><dt>Đang thuê</dt><dd>{house.occupied_rooms}/{house.total_rooms}</dd></div><div><dt>Lấp đầy</dt><dd>{Number(house.occupancy_rate).toLocaleString('vi-VN')}%</dd></div>{house.outstanding_amount != null && <div><dt>Còn phải thu*</dt><dd>{formatCurrency(house.outstanding_amount)}</dd></div>}</dl>{house.operational_role === 'OWNER' && <Link to={`/boarding-houses/${house.id}`}>Xem nhà trọ</Link>}</article>)}</div>{!analytics && <p className="bh-footnote">* Còn phải thu tính cho kỳ hóa đơn API trả về ({period.label}).</p>}</Panel>
      <Panel title="Người thuê"><div className="bh-tenant-summary"><Users size={22} aria-hidden="true" /><div><strong>{summary.active_tenants}</strong><span>người đang ở (không đếm trùng)</span></div></div><Link className="bh-premium-link" to="/tenants">Xem người thuê</Link></Panel>
      <Panel title="Hóa đơn cần xử lý" subtitle={`${invoices.unpaid_invoice_count} hóa đơn còn nợ trên mọi cơ sở và mọi kỳ · hiển thị tối đa 8 hóa đơn.`}>{recent.length ? <ul className="bh-action-list">{recent.map(invoice => <li key={invoice.id}><Link to={`/invoices/${invoice.id}`} state={{ returnTo: '/dashboard', returnLabel: 'Dashboard' }}><div><strong>{invoice.invoice_code}</strong><InvoiceStatusBadge status={invoice.status} /></div><p>{invoice.boarding_house_name} · Phòng {invoice.room_code}</p><div><strong>{formatCurrency(invoice.remaining_amount)}</strong><span className={invoice.is_overdue ? 'bh-danger-text' : ''}>{invoice.due_date ? `Hạn: ${formatDate(invoice.due_date)}` : 'Chưa có hạn thanh toán'}{invoice.is_overdue ? ' · Quá hạn' : ''}</span></div></Link></li>)}</ul> : <p className="bh-chart-empty">Không có hóa đơn cần xử lý.</p>}</Panel>
    </div>
  </div>
}
