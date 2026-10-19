import { formatDateForDisplay } from '../../utils/date'
import './StayConsentDocument.css'

function Value({ value }) {
  return <strong className={value ? '' : 'stay-consent-missing'}>{value || 'Chưa cập nhật'}</strong>
}

export default function StayConsentDocument({ data }) {
  const host = data.host ?? {}
  const guest = data.guest ?? {}
  return <article className="stay-consent-document">
    <header><strong>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</strong><p>Độc lập - Tự do - Hạnh phúc</p><p className="stay-consent-location"><Value value={data.location} />, ngày {data.captured_at ? data.captured_at.slice(8, 10) : '...'} tháng {data.captured_at ? data.captured_at.slice(5, 7) : '...'} năm {data.captured_at ? data.captured_at.slice(0, 4) : '...'}</p><h1>VĂN BẢN ĐỒNG Ý CHO Ở NHỜ NHÀ</h1></header>
    <section><h2>BÊN CHO Ở NHỜ — BÊN A</h2><p>Họ tên: <Value value={host.full_name} /></p><p>Năm sinh: <Value value={host.birth_year} /></p><p>Địa chỉ thường trú: <Value value={host.permanent_address} /></p><p>Số định danh cá nhân: <Value value={host.identity_number} /></p><p>Số điện thoại: <Value value={host.phone} /></p></section>
    <section><h2>BÊN Ở NHỜ — BÊN B</h2><p>Họ tên: <Value value={guest.full_name} /></p><p>Năm sinh: <Value value={guest.birth_year} /></p><p>Địa chỉ thường trú: <Value value={guest.permanent_address} /></p><p>Số định danh cá nhân: <Value value={guest.identity_number} /></p><p>Số điện thoại: <Value value={guest.phone} /></p></section>
    <p>Bên A đồng ý cho Bên B được phép ở nhờ tại <Value value={data.stay_address} />{data.room_code ? `, phòng ${data.room_code}` : ''}.</p>
    <section><h2>I. Mục đích, số người ở nhờ và thời hạn ở nhờ</h2><p>1. Mục đích ở nhờ: <Value value={data.purpose} />.</p><p>2. Số người ở nhờ: <Value value={data.occupant_count} /> người (bao gồm Bên B).</p><p>Những người ở nhờ kèm theo: {data.companions?.length ? data.companions.map(person => person.full_name || 'Chưa cập nhật').join(', ') : 'Không có.'}</p><p>3. Thời hạn ở nhờ: Từ ngày <Value value={formatDateForDisplay(data.start_date)} /> đến ngày <Value value={formatDateForDisplay(data.end_date)} />.</p></section>
    <section><h2>II. Quyền và nghĩa vụ của hai bên</h2><p>1. Bên B thanh toán các khoản phí phát sinh trong quá trình ở nhờ.</p><p>2. Khi cần sửa chữa, Bên B phải thông báo Bên A và được Bên A đồng ý.</p><p>3. Khi không cho ở nhờ nữa, Bên A thông báo cho Bên B trước 1 tháng (30 ngày).</p></section>
    <section><h2>III. Cam kết thực hiện của các bên</h2><p>Các bên cam kết thực hiện nội dung đã thỏa thuận trong văn bản này.</p></section>
    <footer><div><strong>BÊN Ở NHỜ</strong><p>(Ký, ghi rõ họ tên)</p><span>{guest.full_name || 'Chưa cập nhật'}</span></div><div><strong>BÊN CHO Ở NHỜ</strong><p>(Ký, ghi rõ họ tên)</p><span>{host.full_name || 'Chưa cập nhật'}</span></div></footer>
  </article>
}
