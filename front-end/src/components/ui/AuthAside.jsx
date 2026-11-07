import { Link } from 'react-router-dom'
import { ArrowUpRight, Layers3, FileText, Receipt } from 'lucide-react'
import logo from '../../assets/logo_bee_mau.png'

export default function AuthAside() {
  return (
    <aside className="bh-auth-aside">
      <Link to="/" className="bh-auth-brand"><img src={logo} width="40" height="40" alt="" />BeeHouse</Link>
      <div className="bh-auth-story">
        <p className="bh-auth-tagline">Quản lý nhà trọ.<br />Gọn gàng hơn mỗi ngày.</p>
        <p className="bh-auth-summary">Kết nối phòng, người thuê, hợp đồng và hóa đơn trong một không gian quản lý.</p>
        <ul className="bh-auth-modules">
          <li><Layers3 size={17} aria-hidden="true" />Nhà trọ & phòng</li>
          <li><FileText size={17} aria-hidden="true" />Hợp đồng</li>
          <li><Receipt size={17} aria-hidden="true" />Hóa đơn</li>
        </ul>
      </div>
      <Link to="/" className="bh-auth-home">Khám phá BeeHouse <ArrowUpRight size={17} aria-hidden="true" /></Link>
    </aside>
  )
}
