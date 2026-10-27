import watermarkLogo from '../../assets/bill-watermark.svg'
import { BedDouble, CirclePlus, Droplet, Handshake, Settings, Zap } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { formatBillingPeriodForDisplay, formatDateForDisplay } from '../../utils/date'
import { formatCurrency, formatUtilityValue } from '../../utils/formatters'
import PrivatePaymentQr from '../payments/PrivatePaymentQr'
import { invoicePayosQr } from '../../utils/invoicePayment'

const utilityUnits = { ELECTRICITY: 'kWh', WATER: 'm³' }
const itemIcons = { RENT: BedDouble, ELECTRICITY: Zap, WATER: Droplet, OTHER: CirclePlus }

function displayDate(value) {
  return formatDateForDisplay(value) || '—'
}

function itemQuantity(item) {
  const value = formatUtilityValue(item.quantity)
  const unit = item.unit || utilityUnits[item.type] || (item.type === 'RENT' ? 'tháng' : '')
  return unit ? `${value} ${unit}` : value
}

function unitPrice(item) {
  if (utilityUnits[item.type] && Number(item.unit_price) === 0 && Number(item.amount) > 0) return 'Theo bậc'
  return formatCurrency(item.unit_price)
}

function SectionHeading({ children }) {
  return <h2 className="invoice-section-heading">{children}</h2>
}

function InvoicePrintHeader({ invoice }) {
  const house = invoice.room?.boarding_house
  return <>
    <header className="invoice-document-header">
      <div className="invoice-brand">
        <img className="invoice-brand-mark" src="/images/bill-landlord-logo.svg" alt="Logo Nhà trọ 115M" />
        <div><p className="invoice-brand-name">NHÀ TRỌ 115M</p><p className="invoice-brand-tagline">Quản lý và vận hành nhà trọ</p></div>
      </div>
      <dl className="invoice-document-meta">
        <div><dt>Mã hóa đơn</dt><dd>{invoice.invoice_code}</dd></div>
        <div><dt>Kỳ thanh toán</dt><dd>{formatBillingPeriodForDisplay(invoice.billing_period) || '—'}</dd></div>
        <div className="invoice-meta-due"><dt>Hạn thanh toán</dt><dd>{displayDate(invoice.due_date)}</dd></div>
      </dl>
    </header>
    <div className="invoice-title-band">
      <h1>HÓA ĐƠN TIỀN PHÒNG</h1>
      <p>Ngày phát hành: <span>{displayDate(invoice.issued_at)}</span></p>
      {house && <p className="invoice-house-address">{[house.name, house.address].filter(Boolean).join(' · ')}</p>}
    </div>
  </>
}

function InvoicePrintSummary({ invoice }) {
  const room = invoice.room
  const roomName = room?.room_name ? `${room.room_code} — ${room.room_name}` : room?.room_code
  return <section className="invoice-block invoice-keep-together">
    <SectionHeading>Thông tin thuê</SectionHeading>
    <dl className="invoice-rental-strip">
      <div><dt>Phòng:</dt><dd>{roomName || '—'}</dd></div>
      <div><dt>Khách thuê:</dt><dd>{invoice.contract?.tenant?.full_name || '—'}</dd></div>
      <div><dt>Hợp đồng:</dt><dd>{invoice.contract?.contract_code || 'Không liên kết'}</dd></div>
    </dl>
  </section>
}

function InvoicePrintItemsTable({ items }) {
  return <section className="invoice-block invoice-charges">
    <SectionHeading>Chi tiết các khoản thu</SectionHeading>
    <div className="invoice-items-scroll" role="region" aria-label="Chi tiết khoản thu" tabIndex={0}>
      <table className="invoice-items-table">
        <thead><tr><th>Khoản thu</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
        <tbody>
          {items.map(item => {
            const Icon = itemIcons[item.type] || Settings
            return <tr key={item.id}>
              <td><div className="invoice-item-name"><Icon size={18} aria-hidden="true" /><span>{item.description}</span></div></td>
              <td>{itemQuantity(item)}</td><td>{unitPrice(item)}</td><td>{formatCurrency(item.amount)}</td>
            </tr>
          })}
          {items.length === 0 && <tr><td colSpan="4" className="invoice-empty-row">Chưa có khoản thu trong hóa đơn.</td></tr>}
        </tbody>
      </table>
    </div>
    <p className="invoice-fine-print">Số lượng, đơn giá và thành tiền được lấy theo các khoản thu đã ghi nhận trên hóa đơn.</p>
    {items.some(item => item.type === 'SERVICE_TAX') && <p className="invoice-fine-print">Khoản VAT đã ghi nhận được giữ theo hóa đơn gốc, không tự điều chỉnh dữ liệu lịch sử.</p>}
  </section>
}

function Totals({ invoice, remaining }) {
  const discount = Number(invoice.discount_amount || 0)
  return <section className="invoice-cost-summary invoice-keep-together">
    <SectionHeading>Tổng hợp chi phí</SectionHeading>
    <div className="invoice-totals">
      <dl>
        <div><dt>Tạm tính</dt><dd>{formatCurrency(invoice.subtotal)}</dd></div>
        <div><dt>Giảm giá</dt><dd>{discount > 0 ? `− ${formatCurrency(discount)}` : formatCurrency(0)}</dd></div>
        <div><dt>Tổng cộng</dt><dd>{formatCurrency(invoice.total_amount)}</dd></div>
        <div><dt>Đã thanh toán</dt><dd>{formatCurrency(invoice.paid_amount)}</dd></div>
      </dl>
      <div className="invoice-remaining-row"><span>Còn phải thanh toán</span><strong>{formatCurrency(remaining)}</strong></div>
    </div>
  </section>
}

function PaymentInfo({ invoice }) {
  const bank = invoice.payment_profile
  const configured = Boolean(bank?.bank_name || bank?.bank_account_name || bank?.bank_account_number || bank?.payment_qr_url)
  const missingSnapshot = bank?.source === 'missing_snapshot'
  return <section className="invoice-transfer-section invoice-keep-together">
    <SectionHeading>Thông tin chuyển khoản</SectionHeading>
    <div className={`invoice-payment-details ${bank?.payment_qr_url ? 'has-bank-qr' : ''}`}>
      <div className="invoice-bank-content">
        {bank?.payment_qr_url && <div className="invoice-bank-qr"><PrivatePaymentQr profile={bank} cropToQr /></div>}
        <dl className="invoice-payment-rows">
          {bank?.bank_name && <div><dt>Ngân hàng</dt><dd>{bank.bank_name}</dd></div>}
          {bank?.bank_account_number && <div><dt>Số tài khoản</dt><dd>{bank.bank_account_number}</dd></div>}
          {bank?.bank_account_name && <div><dt>Chủ tài khoản</dt><dd>{bank.bank_account_name}</dd></div>}
        </dl>
      </div>
      {bank?.payment_qr_url && <p className="invoice-qr-caption">Quét mã QR để chuyển khoản thanh toán.</p>}
      {bank?.payment_qr_missing && <p className="invoice-payment-fallback">Ảnh QR đã lưu không còn khả dụng. Vui lòng liên hệ chủ trọ để đối chiếu thông tin thanh toán.</p>}
      {!configured && <div className="invoice-payment-fallback">
        <p>{missingSnapshot ? 'Hóa đơn này chưa lưu thông tin nhận thanh toán tại thời điểm phát hành.' : 'Chưa thiết lập thông tin nhận thanh toán.'}</p>
        {missingSnapshot && bank.current_profile_configured && <p>Chủ trọ đã có cấu hình thanh toán hiện tại; cấu hình này chưa được ghi nhận trên hóa đơn này.</p>}
        <p>Vui lòng liên hệ chủ trọ để {missingSnapshot ? 'đối chiếu và xác nhận' : 'biết'} thông tin thanh toán.</p>
      </div>}
    </div>
  </section>
}

function ExistingPayosInfo({ paymentRequest }) {
  if (!paymentRequest) return null
  return <section className="invoice-existing-payos invoice-keep-together">
    <div className="invoice-top-qr"><QRCodeSVG value={paymentRequest.qr_code} size={100} level="M" marginSize={4} /></div>
    <div><strong>Thanh toán qua payOS</strong><p>Quét QR payOS để thanh toán · Mã: {paymentRequest.order_code}</p>{paymentRequest.description && <p>Nội dung: {paymentRequest.description}</p>}</div>
  </section>
}

export default function InvoicePrintLayout({ invoice }) {
  const paymentRequest = invoicePayosQr(invoice)
  const remaining = Math.max(0, Number(invoice.total_amount || 0) - Number(invoice.paid_amount || 0))

  return <article className="invoice-sheet">
    <div className="invoice-watermarks" aria-hidden="true">
      <img className="invoice-watermark invoice-watermark-center" src={watermarkLogo} alt="" />
      <img className="invoice-watermark invoice-watermark-top" src={watermarkLogo} alt="" />
      <img className="invoice-watermark invoice-watermark-bottom" src={watermarkLogo} alt="" />
    </div>
    <InvoicePrintHeader invoice={invoice} />
    <InvoicePrintSummary invoice={invoice} />
    <InvoicePrintItemsTable items={invoice.items || []} />
    <div className="invoice-financial-row">
      <PaymentInfo invoice={invoice} />
      <Totals invoice={invoice} remaining={remaining} />
    </div>
    <ExistingPayosInfo paymentRequest={paymentRequest} />
    {invoice.note?.trim() && <section className="invoice-note-box invoice-block"><SectionHeading>Ghi chú</SectionHeading><p>{invoice.note}</p></section>}
    <footer className="invoice-footer">
      <Handshake size={29} aria-hidden="true" />
      <div><strong>Cảm ơn quý khách!</strong><p>Vui lòng thanh toán đúng hạn và giữ hóa đơn này để đối chiếu khi cần.</p></div>
      <img className="invoice-footer-houses" src="/images/bill-footer-neighborhood-transparent.png" alt="" aria-hidden="true" />
    </footer>
  </article>
}
