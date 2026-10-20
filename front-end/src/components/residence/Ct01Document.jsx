import { formatDateForDisplay } from '../../utils/date'
import { genderLabels } from '../../utils/formatters'
import './Ct01Document.css'

function Value({ value, className = '' }) {
  return <span className={`ct01-value ${className}`}>{value ?? ''}</span>
}

function IdentityBoxes({ value }) {
  const digits = String(value ?? '').replace(/\s/g, '').slice(0, 12)
  return <span className="ct01-identity-boxes" aria-label={digits || 'Chưa điền số định danh'}>
    {Array.from({ length: 12 }, (_, index) => <span key={index}>{digits[index] ?? ''}</span>)}
  </span>
}

const memberHeaders = ['TT', 'Họ, chữ đệm và tên', 'Ngày, tháng, năm sinh', 'Giới tính', 'Số định danh cá nhân', 'Mối quan hệ với chủ hộ']
const signatureTitles = [
  'Ý KIẾN CỦA CHỦ HỘ⁽³⁾',
  'Ý KIẾN CỦA CHỦ SỞ HỮU CHỖ Ở HỢP PHÁP⁽⁴⁾',
  'Ý KIẾN CỦA CHA HOẶC MẸ HOẶC NGƯỜI GIÁM HỘ⁽⁵⁾',
  'NGƯỜI KÊ KHAI⁽⁶⁾',
]

export default function Ct01Document({ data }) {
  const person = data.declarant ?? {}
  const head = data.household_head ?? {}
  const members = data.members ?? []
  const opinionKeys = ['household_head', 'legal_owner', 'guardian']

  return <article className="ct01-document">
    <p className="ct01-template">Mẫu CT01 ban hành kèm theo Thông tư số 116/2026/TT-BCA<br />ngày 29/06/2026 của Bộ trưởng Bộ Công an</p>
    <header className="ct01-heading"><strong>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</strong><strong className="ct01-motto">Độc lập – Tự do – Hạnh phúc</strong><h1>TỜ KHAI THAY ĐỔI THÔNG TIN CƯ TRÚ</h1></header>
    <p className="ct01-recipient">Kính gửi<sup>(1)</sup>: <Value value={data.authority_name} /></p>
    <div className="ct01-fields">
      <p>1. Họ, chữ đệm và tên khai sinh: <Value value={person.full_name} className="ct01-dotted" /></p>
      <div className="ct01-inline-pair"><p>2. Ngày, tháng, năm sinh: <Value value={formatDateForDisplay(person.date_of_birth)} className="ct01-dotted" /></p><p>3. Giới tính: <Value value={genderLabels[person.gender]} className="ct01-dotted" /></p></div>
      <p className="ct01-identity-row">4. Số định danh cá nhân: <IdentityBoxes value={person.identity_number} /></p>
      <div className="ct01-inline-pair"><p>5. Số điện thoại liên hệ: <Value value={person.phone} className="ct01-dotted" /></p><p>6. Email: <Value value={person.email} className="ct01-dotted" /></p></div>
      <p>7. Họ, chữ đệm và tên chủ hộ: <Value value={head.name} className="ct01-dotted" /></p>
      <p>8. Mối quan hệ với chủ hộ: <Value value={data.relationship_to_household_head} className="ct01-dotted" /></p>
      <p className="ct01-identity-row">9. Số định danh cá nhân của chủ hộ: <IdentityBoxes value={head.identity_number} /></p>
      <p>10. Nội dung đề nghị<sup>(2)</sup>: <Value value={data.request_content} className="ct01-dotted" /></p>
      <p className="ct01-request-extra ct01-dotted">&nbsp;</p>
      <p>11. Những thành viên trong hộ gia đình cùng thay đổi:</p>
    </div>
    <table className="ct01-member-table"><colgroup>{[6, 29, 17, 8, 23, 17].map((width, index) => <col key={index} style={{ width: `${width}%` }} />)}</colgroup><thead><tr>{memberHeaders.map(label => <th key={label}>{label}</th>)}</tr></thead><tbody>{Array.from({ length: Math.max(8, members.length) }, (_, index) => {
      const member = members[index]
      return <tr key={member?.tenant_id ?? `blank-${index}`}><td>{member ? index + 1 : ''}</td><td>{member?.full_name ?? ''}</td><td>{formatDateForDisplay(member?.date_of_birth)}</td><td>{genderLabels[member?.gender] ?? ''}</td><td>{member?.identity_number ?? ''}</td><td>{member?.relationship_to_household_head ?? ''}</td></tr>
    })}</tbody></table>
    <div className="ct01-signatures">{signatureTitles.map((title, index) => <div key={title} className="ct01-signature"><p className="ct01-signature-date">....., ngày ..... tháng ..... năm .....</p><strong>{title}</strong>{index < 3 && data.opinions?.[opinionKeys[index]] && <p className="ct01-opinion">{data.opinions[opinionKeys[index]]}</p>}{index === 1 || index === 2 ? <div className="ct01-signature-extra"><p>(7) Họ và tên: ........................</p><p>(7) Số định danh cá nhân: ................</p></div> : null}{index === 3 && <p className="ct01-signer-name">{person.full_name ?? ''}</p>}</div>)}</div>
  </article>
}
