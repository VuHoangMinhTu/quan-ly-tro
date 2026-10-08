import { Building2, CreditCard, Home, UserRound } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { formatBillingPeriodForDisplay, formatDateForDisplay } from '../../utils/date'
import { formatCurrency, formatUtilityValue } from '../../utils/formatters'
import { invoiceStatusLabels } from '../../utils/invoiceStatus'

const utilityUnits = { ELECTRICITY: 'kWh', WATER: 'm³' }

function displayDate(value) {
  return formatDateForDisplay(value) || '—'
}

function itemQuantity(item) {
  const value = formatUtilityValue(item.quantity)
  return utilityUnits[item.type] ? `${value} ${utilityUnits[item.type]}` : value
}

function unitPrice(item) {
  if (utilityUnits[item.type] && Number(item.unit_price) === 0 && Number(item.amount) > 0) return 'Theo bậc'
  return formatCurrency(item.unit_price)
}

function InfoCard({ icon: Icon, label, primary, secondary }) {
  return <div className="invoice-info-card">
    <span className="invoice-info-icon"><Icon size={17} /></span>
    <div><p className="invoice-eyebrow">{label}</p><p className="invoice-info-primary">{primary || '—'}</p>{secondary && <p className="invoice-info-secondary">{secondary}</p>}</div>
  </div>
}

function SectionHeading({ children }) {
  return <h2 className="invoice-section-heading">{children}</h2>
}

function InvoicePrintHeader({ invoice }) {
  const boardingHouse = invoice.room?.boarding_house
  return <>
    <header className="invoice-document-header">
      <div className="invoice-brand">
        <span className="invoice-brand-mark"><Building2 size={25} /></span>
        <div><p className="invoice-brand-name">{boardingHouse?.name || 'NHÀ TRỌ'}</p><p className="invoice-brand-address">{boardingHouse?.address || 'Hệ thống quản lý nhà trọ'}</p></div>
      </div>
      <div className="invoice-document-meta">
        <p><span>Mã hóa đơn</span><strong>{invoice.invoice_code}</strong></p>
        <p><span>Ngày phát hành</span><strong>{displayDate(invoice.issued_at)}</strong></p>
        <p><span>Hạn thanh toán</span><strong>{displayDate(invoice.due_date)}</strong></p>
      </div>
    </header>
    <div className="invoice-title-band">
      <p>PHIẾU THU TIỀN NHÀ</p>
      <h1>HÓA ĐƠN TIỀN PHÒNG</h1>
      <span>Kỳ {formatBillingPeriodForDisplay(invoice.billing_period) || '—'}</span>
    </div>
  </>
}

function PaymentQrCard({ invoice, paymentRequest, remaining }) {
  const hasQr = Boolean(paymentRequest?.qr_code)

  return <aside className={`invoice-top-payment-card ${hasQr ? 'has-qr' : 'without-qr'}`}>
    <p className="invoice-eyebrow">Cần thanh toán</p>
    <strong className="invoice-top-payment-amount">{formatCurrency(remaining)}</strong>
    <span className="invoice-top-payment-status">{invoiceStatusLabels[invoice.status] || invoice.status}</span>
    {hasQr ? <>
      <div className="invoice-top-qr"><QRCodeSVG value={paymentRequest.qr_code} size={118} level="M" /></div>
      <span className="invoice-top-qr-label">Quét mã payOS để thanh toán</span>
    </> : <div className="invoice-top-payment-fallback"><CreditCard size={25} /><span>Thanh toán tiền mặt hoặc chuyển khoản theo hướng dẫn của chủ trọ.</span></div>}
  </aside>
}

function InvoicePrintSummary({ invoice, paymentRequest, remaining }) {
  const room = invoice.room
  const tenant = invoice.contract?.tenant
  const roomName = room?.room_name ? `${room.room_code} — ${room.room_name}` : room?.room_code
  return <section className="invoice-block invoice-summary-layout invoice-keep-together">
    <div className="invoice-summary-details">
      <SectionHeading>Thông tin hóa đơn</SectionHeading>
      <div className="invoice-info-grid">
        <InfoCard icon={Home} label="Phòng" primary={roomName} secondary={room?.boarding_house?.name} />
        <InfoCard icon={UserRound} label="Người thuê" primary={tenant?.full_name} secondary={tenant?.phone} />
        <InfoCard icon={CreditCard} label="Hợp đồng" primary={invoice.contract?.contract_code || 'Không liên kết hợp đồng'} />
      </div>
    </div>
    <PaymentQrCard invoice={invoice} paymentRequest={paymentRequest} remaining={remaining} />
  </section>
}

function InvoicePrintItemsTable({ items }) {
  return <section className="invoice-block invoice-keep-together">
    <SectionHeading>Chi tiết các khoản thu</SectionHeading>
    <table className="invoice-items-table">
      <thead><tr><th>STT</th><th>Diễn giải</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
      <tbody>
        {items.map((item, index) => <tr key={item.id}>
          <td>{index + 1}</td>
          <td><strong>{item.description}</strong><span>{item.source === 'AUTO' ? 'Tự động' : 'Thủ công'}</span></td>
          <td>{itemQuantity(item)}</td><td>{unitPrice(item)}</td><td>{formatCurrency(item.amount)}</td>
        </tr>)}
        {items.length === 0 && <tr><td colSpan="5" className="invoice-empty-row">Chưa có khoản thu trong hóa đơn.</td></tr>}
      </tbody>
    </table>
  </section>
}

function UtilitySummary({ items }) {
  const utilityItems = items.filter((item) => utilityUnits[item.type])
  if (utilityItems.length === 0) return null

  return <section className="invoice-block invoice-keep-together">
    <SectionHeading>Chi tiết điện nước</SectionHeading>
    <div className="invoice-utility-grid">
      {utilityItems.map((item) => <div key={item.id} className="invoice-utility-card">
        <p className="invoice-eyebrow">{item.type === 'ELECTRICITY' ? 'Điện' : 'Nước'}</p>
        <strong>{item.description}</strong>
        <dl><div><dt>Mức tiêu thụ</dt><dd>{itemQuantity(item)}</dd></div><div><dt>Đơn giá</dt><dd>{unitPrice(item)}</dd></div><div><dt>Thành tiền</dt><dd>{formatCurrency(item.amount)}</dd></div></dl>
      </div>)}
    </div>
    <p className="invoice-fine-print">Mức tiêu thụ và thành tiền được lấy từ khoản thu đã chốt trên hóa đơn.</p>
  </section>
}

function Totals({ invoice, remaining }) {
  const discount = Number(invoice.discount_amount || 0)
  return <aside className="invoice-totals invoice-keep-together">
    <div><span>Tạm tính</span><strong>{formatCurrency(invoice.subtotal)}</strong></div>
    <div><span>Giảm giá</span><strong>{discount > 0 ? `− ${formatCurrency(discount)}` : formatCurrency(0)}</strong></div>
    <div className="invoice-total-row"><span>Tổng cộng</span><strong>{formatCurrency(invoice.total_amount)}</strong></div>
    <div><span>Đã thanh toán</span><strong>{formatCurrency(invoice.paid_amount)}</strong></div>
    <div className="invoice-remaining-row"><span>Còn lại</span><strong>{formatCurrency(remaining)}</strong></div>
  </aside>
}

function PaymentInfo({ invoice, paymentRequest, remaining }) {
  const hasQr = Boolean(paymentRequest?.qr_code)
  return <section className="invoice-payment-details invoice-keep-together">
    <SectionHeading>Thông tin thanh toán</SectionHeading>
    <div className="invoice-payment-rows">
      <p><span>Hạn thanh toán</span><strong>{displayDate(invoice.due_date)}</strong></p>
      <p><span>Số tiền cần thanh toán</span><strong>{formatCurrency(remaining)}</strong></p>
      <p><span>Phương thức</span><strong>{hasQr ? 'Chuyển khoản qua payOS' : 'Tiền mặt / Chuyển khoản'}</strong></p>
      {hasQr && <p><span>Mã thanh toán</span><strong>{paymentRequest.order_code}</strong></p>}
    </div>
  </section>
}

export default function InvoicePrintLayout({ invoice }) {
  const items = invoice.items || []
  const paymentRequest = invoice.payos_payment_request
  const remaining = Math.max(0, Number(invoice.total_amount || 0) - Number(invoice.paid_amount || 0))

  return <article className="invoice-sheet">
    <InvoicePrintHeader invoice={invoice} />
    <InvoicePrintSummary invoice={invoice} paymentRequest={paymentRequest} remaining={remaining} />
    <InvoicePrintItemsTable items={items} />
    <UtilitySummary items={items} />
    <div className="invoice-financial-row">
      <PaymentInfo invoice={invoice} paymentRequest={paymentRequest} remaining={remaining} />
      <Totals invoice={invoice} remaining={remaining} />
    </div>
    <section className="invoice-note-box invoice-block invoice-keep-together"><SectionHeading>Ghi chú</SectionHeading><p>{invoice.note || 'Không có ghi chú.'}</p></section>
    <footer className="invoice-footer"><strong>Cảm ơn quý khách!</strong><p>Vui lòng thanh toán đúng hạn và giữ hóa đơn này để đối chiếu khi cần.</p></footer>
  </article>
}
