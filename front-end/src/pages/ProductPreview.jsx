import { Building2, CalendarDays, DoorOpen, FileText, LayoutDashboard, Receipt, Users, Wallet } from 'lucide-react'
import logo from '../assets/logo_bee_mau.png'
import './product-preview.css'

const navigation = [
  [LayoutDashboard, 'Tổng quan'], [Building2, 'Nhà trọ'], [DoorOpen, 'Phòng'],
  [Users, 'Người thuê'], [FileText, 'Hợp đồng'], [Receipt, 'Hóa đơn'],
]
const metrics = [
  [DoorOpen, 'Phòng đang có người', '20 / 24', '4 phòng chưa có người'],
  [Wallet, 'Đã thu của kỳ', '28.400.000 ₫', '18 hóa đơn đã thanh toán'],
  [Receipt, 'Còn phải thu', '6.200.000 ₫', '4 hóa đơn còn nợ'],
]
const receipts = [18, 21, 19, 24, 23, 28.4]
const invoices = [
  ['HD-1024', 'Phòng 204', '2.800.000 ₫', 'Đã thanh toán', 'paid'],
  ['HD-1025', 'Phòng 305', '2.100.000 ₫', 'Thanh toán một phần', 'partial'],
  ['HD-1026', 'Phòng 107', '1.650.000 ₫', 'Quá hạn', 'overdue'],
]

export default function ProductPreview() {
  return <figure className="product-preview">
    <figcaption className="product-preview-bar">
      <span className="product-preview-dots" aria-hidden="true"><i /><i /><i /></span>
      <span>BeeHouse / Tổng quan</span>
      <span className="product-preview-demo">Dữ liệu minh họa</span>
    </figcaption>
    <div className="product-preview-body">
      <div className="product-preview-sidebar" aria-hidden="true">
        <div className="product-preview-brand"><img src={logo} width="32" height="32" alt="" />BeeHouse</div>
        {navigation.map(([Icon, label], index) => <div key={label} className={index === 0 ? 'preview-current' : ''}>
          <Icon size={17} />{label}
        </div>)}
      </div>
      <div className="product-preview-workspace">
        <div className="product-preview-topbar"><span>Không gian quản lý</span><span className="preview-avatar" aria-hidden="true">BH</span></div>
        <div className="product-preview-content">
          <div className="preview-title-row">
            <h2>Tổng quan vận hành</h2>
            <span><CalendarDays size={16} aria-hidden="true" /> Kỳ 06/2026</span>
          </div>
          <dl className="preview-metrics">
            {metrics.map(([Icon, label, value, note]) => <div key={label}>
              <dt><Icon size={17} aria-hidden="true" />{label}</dt>
              <dd>{value}<small>{note}</small></dd>
            </div>)}
          </dl>
          <div className="preview-insights">
            <section className="preview-surface">
              <h3>Tiền thực thu theo tháng</h3>
              <p>6 tháng gần nhất · triệu đồng</p>
              <ol className="preview-receipts">
                {receipts.map((value, index) => <li key={index}>
                  <span>{value.toLocaleString('vi-VN')}</span>
                  <i aria-hidden="true" style={{ height: `${value / 30 * 78}px` }} />
                  <small>T{index + 1}</small>
                </li>)}
              </ol>
            </section>
            <section className="preview-surface preview-occupancy">
              <h3>Tỷ lệ lấp đầy</h3>
              <strong>83%</strong>
              <div aria-hidden="true"><i /></div>
              <p>20 trên 24 phòng đang có người ở</p>
            </section>
          </div>
          <section className="preview-surface preview-invoices">
            <h3>Hóa đơn gần đây</h3>
            <table aria-label="Hóa đơn minh họa gần đây">
              <thead><tr><th scope="col">Hóa đơn</th><th scope="col">Tổng tiền</th><th scope="col">Trạng thái</th></tr></thead>
              <tbody>{invoices.map(([code, room, amount, status, tone]) => <tr key={code}>
                <th scope="row">{code}<small>{room}</small></th>
                <td>{amount}</td>
                <td><span className={`preview-badge preview-badge-${tone}`}>{status}</span></td>
              </tr>)}</tbody>
            </table>
          </section>
        </div>
      </div>
    </div>
  </figure>
}
