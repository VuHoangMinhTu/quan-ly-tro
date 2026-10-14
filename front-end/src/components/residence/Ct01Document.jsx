import { formatDateForDisplay } from '../../utils/date'
import { genderLabels } from '../../utils/formatters'
import './Ct01Document.css'

function Value({ value }) {
  return <span className={`ct01-value ${value ? '' : 'ct01-missing'}`}>{value || <><span className="ct01-screen-missing">Chưa cập nhật</span><span className="ct01-print-blank">........................</span></>}</span>
}
export default function Ct01Document({ data }) {
  const person = data.declarant
  return <article className="ct01-document">
    <p className="ct01-template">Mẫu CT01</p><header><strong>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</strong><p>Độc lập - Tự do - Hạnh phúc</p><h1>TỜ KHAI THAY ĐỔI THÔNG TIN CƯ TRÚ</h1></header>
    <p className="ct01-row">Kính gửi: <Value value={data.authority_name} /></p>
    <p className="ct01-row">1. Họ, chữ đệm và tên khai sinh: <Value value={person.full_name} /></p>
    <div className="ct01-pair"><p className="ct01-row">2. Ngày, tháng, năm sinh: <Value value={formatDateForDisplay(person.date_of_birth)} /></p><p className="ct01-row">3. Giới tính: <Value value={genderLabels[person.gender]} /></p></div>
    <p className="ct01-row">4. Số định danh cá nhân: <Value value={person.identity_number} /></p>
    <div className="ct01-pair"><p className="ct01-row">5. Số điện thoại liên hệ: <Value value={person.phone} /></p><p className="ct01-row">6. Email: <Value value={person.email} /></p></div>
    <p className="ct01-row">7. Họ, chữ đệm và tên chủ hộ: <Value value={data.household_head.name} /></p>
    <p className="ct01-row">8. Mối quan hệ với chủ hộ: <Value value={data.relationship_to_household_head} /></p>
    <p className="ct01-row">9. Số định danh cá nhân của chủ hộ: <Value value={data.household_head.identity_number} /></p>
    <p>10. Nội dung đề nghị:</p><p className="ct01-request"><Value value={data.request_content} /></p>
    <p>11. Những thành viên trong hộ gia đình cùng thay đổi:</p>
    {data.members.length ? <table className="ct01-member-table"><thead><tr>{['TT', 'Họ, chữ đệm và tên', 'Ngày sinh', 'Giới tính', 'Số định danh cá nhân', 'Mối quan hệ với chủ hộ'].map(label => <th key={label}>{label}</th>)}</tr></thead><tbody>{data.members.map((member, index) => <tr key={member.tenant_id ?? index}>{[index + 1, member.full_name, formatDateForDisplay(member.date_of_birth), genderLabels[member.gender], member.identity_number, member.relationship_to_household_head].map((value, column) => <td key={column}><span className="ct01-mobile-label">{['TT', 'Họ tên', 'Ngày sinh', 'Giới tính', 'Số định danh', 'Quan hệ với chủ hộ'][column]}</span><Value value={value} /></td>)}</tr>)}</tbody></table> : <p className="ct01-no-members">Không có thành viên cùng khai báo.</p>}
    <div className="ct01-signatures">{['Ý KIẾN CỦA CHỦ HỘ', 'Ý KIẾN CỦA CHỦ SỞ HỮU CHỖ Ở HỢP PHÁP', 'Ý KIẾN CỦA CHA, MẸ HOẶC NGƯỜI GIÁM HỘ', 'NGƯỜI KÊ KHAI'].map((title, index) => <div key={title}><p>..., ngày ... tháng ... năm ...</p><strong>{title}</strong><p>(Ký, ghi rõ họ tên khi áp dụng)</p>{index === 3 && <span>{person.full_name}</span>}</div>)}</div>
  </article>
}
