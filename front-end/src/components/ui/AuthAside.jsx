import { Link } from 'react-router-dom'
import { ArrowUpRight, Layers3, FileText, Receipt } from 'lucide-react'
import logo from '../../assets/logo_bee_mau.png'

export default function AuthAside() {
  return <aside className="bh-auth-aside"><Link to="/" className="bh-auth-brand"><img src={logo} width="42" height="42" alt="" />BeeHouse</Link><div className="bh-auth-story"><span className="bh-eyebrow">MỘT KHÔNG GIAN. MỌI VIỆC QUẢN LÝ.</span><h2>Gọn gàng hơn.<br /><span>Chủ động hơn.</span></h2><p>Kết nối phòng, người thuê, hợp đồng và hóa đơn trong nhịp vận hành mỗi ngày.</p><div className="bh-auth-modules"><span><Layers3 size={17} aria-hidden="true" />Nhà trọ & phòng</span><span><FileText size={17} aria-hidden="true" />Hợp đồng</span><span><Receipt size={17} aria-hidden="true" />Hóa đơn</span></div></div><Link to="/" className="bh-auth-home">Khám phá BeeHouse <ArrowUpRight size={17} aria-hidden="true" /></Link></aside>
}
