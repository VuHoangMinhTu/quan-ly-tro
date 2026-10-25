import { formatDateForDisplay } from '../../utils/date'
import { wholeMonthsBetweenDates } from '../../utils/residence'
import './StayConsentDocument.css'

function Blank({ value, className = '' }) {
  return <span className={`stay-consent-value ${className}`}>{value ?? ''}</span>
}

export default function StayConsentDocument({ data }) {
  const host = data.host ?? {}
  const guest = data.guest ?? {}
  const companions = data.companions ?? []
  const [year, month, day] = String(data.captured_at ?? '').slice(0, 10).split('-')
  const stayAddress = [data.stay_address, data.room_code ? `Phòng ${data.room_code}` : ''].filter(Boolean).join(' - ')
  const monthCount = wholeMonthsBetweenDates(data.start_date, data.end_date)

  return <article className="stay-consent-document">
    <header className="stay-consent-heading"><strong>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</strong><strong className="stay-consent-motto">Độc lập - Tự do - Hạnh phúc</strong><p className="stay-consent-location"><Blank value={data.document_place} />, ngày <Blank value={day} /> tháng <Blank value={month} /> năm <Blank value={year} /></p><h1>VĂN BẢN ĐỒNG Ý CHO Ở NHỜ NHÀ</h1></header>
    <div className="stay-consent-body">
      <p className="stay-consent-indented">Hôm nay, ngày <Blank value={day} /> tháng <Blank value={month} /> năm <Blank value={year} />, tại số <Blank value={data.location} className="stay-consent-grow" /></p>
      <p className="stay-consent-writing-space">&nbsp;</p>
      <p>Chúng tôi gồm:</p>
      <p><strong>Bên cho ở nhờ:</strong> <em>(sau đây gọi tắt là Bên cho ở nhờ - Bên A):</em></p>
      <p className="stay-consent-indented">Ông/bà: <Blank value={host.full_name} className="stay-consent-grow" /> – Ngày sinh: <Blank value={formatDateForDisplay(host.birth_date) || host.birth_year} /></p>
      <p className="stay-consent-indented">Địa chỉ thường trú : <Blank value={host.permanent_address} className="stay-consent-grow" /></p>
      <p className="stay-consent-writing-space">&nbsp;</p>
      <p className="stay-consent-indented">CCCD: <Blank value={host.identity_number} className="stay-consent-grow" /> – Số ĐT: <Blank value={host.phone} className="stay-consent-grow" /></p>
      <p><strong>Bên ở nhờ</strong><em>(sau đây gọi tắt là Bên ở nhờ - Bên B):</em></p>
      <p className="stay-consent-indented">Ông/bà: <Blank value={guest.full_name} className="stay-consent-grow" /> – Năm sinh: <Blank value={guest.birth_year} /></p>
      <p className="stay-consent-indented">Địa chỉ thường trú: <Blank value={guest.permanent_address} className="stay-consent-grow" /></p>
      <p className="stay-consent-writing-space">&nbsp;</p>
      <p className="stay-consent-indented">CCCD: <Blank value={guest.identity_number} className="stay-consent-grow" /> – Số ĐT: <Blank value={guest.phone} className="stay-consent-grow" /></p>
      <p className="stay-consent-indented">Bên A đồng ý cho Bên B được phép ở nhờ tại <Blank value={stayAddress} className="stay-consent-grow" /></p>
      <p className="stay-consent-writing-space">&nbsp;</p>
      <p className="stay-consent-writing-space">&nbsp;</p>
      <p className="stay-consent-section"><strong>I. Mục đích, số người ở nhờ, thời hạn ở nhờ:</strong></p>
      <p><strong>1. Mục đích ở nhờ:</strong> <Blank value={data.purpose} /></p>
      <p className="stay-consent-indented"><strong>Số người ở nhờ kèm theo:</strong></p>
      {Array.from({ length: Math.max(5, companions.length) }, (_, index) => <p key={companions[index]?.tenant_id ?? index} className="stay-consent-companion">+ <Blank value={companions[index]?.full_name} className="stay-consent-grow" /></p>)}
      <p><strong>2. Thời hạn ở nhờ:</strong> {monthCount === null ? null : <><Blank value={String(monthCount).padStart(2, '0')} className="stay-consent-month-count" /> tháng </>}(Từ ngày <Blank value={formatDateForDisplay(data.start_date)} /> đến ngày <Blank value={formatDateForDisplay(data.end_date)} /> )</p>
      <p className="stay-consent-section"><strong>II. Quyền và nghĩa vụ của hai bên:</strong></p>
      <p className="stay-consent-indented">- Bên B có trách nhiệm thanh toán tất cả các khoảng phí phát sinh trong quá trình ở nhờ.</p>
      <p className="stay-consent-indented">- Khi cần sửa chữa phải thông báo cho bên A và được sự đồng ý của Bên A.</p>
      <p className="stay-consent-indented">- Khi không cho ở nhờ nữa, Bên A phải thông báo cho Bên B trước 1 tháng (30 ngày).</p>
      <p className="stay-consent-section"><strong>III. Cam kết thực hiện của các bên</strong></p>
      <p className="stay-consent-indented">1. Hai bên cùng cam kết thực hiện đúng các nội dung đã ký.</p>
      <p className="stay-consent-indented">2. Hợp đồng được lập thành 02 bản và có giá trị như nhau. Mỗi bên giữ 01 bản. Hợp đồng này có giá trị kể từ <Blank value={formatDateForDisplay(data.start_date)} className="stay-consent-effective-date" />/.</p>
    </div>
    <footer className="stay-consent-signatures"><div><strong>Bên Ở nhờ</strong></div><div><strong>Bên cho ở nhờ</strong></div></footer>
  </article>
}
