import { useEffect, useState, useSyncExternalStore } from 'react'
import { ArrowDown, ArrowRight, Building2, FileCheck2, FileText, Gauge, Menu, Receipt, Users, X } from 'lucide-react'
import { Link, Navigate } from 'react-router-dom'
import { getSession, subscribeSession } from '../api/session'
import logo from '../assets/logo_bee_mau.png'
import ProductPreview from './ProductPreview'
import './landing.css'

const features = [
  [Building2, '01', 'Một góc nhìn cho mọi nhà trọ.', 'Sắp xếp nhà trọ, tầng và phòng. Theo dõi phòng trống, phòng đang có người ở và thông tin từng phòng.'],
  [Users, '02', 'Hồ sơ gọn. Tra cứu nhanh.', 'Quản lý người thuê, nhập hồ sơ từ Excel và theo dõi lịch sử cư trú theo phòng.'],
  [FileText, '03', 'Từ thỏa thuận đến hợp đồng.', 'Lập hợp đồng thuê phòng hoặc giường KTX, chọn mẫu và ghi nhận biên bản bàn giao.'],
  [Gauge, '04', 'Điện nước, không còn rời rạc.', 'Thiết lập dịch vụ, theo dõi đồng hồ và ghi chỉ số để chuẩn bị hóa đơn theo kỳ.'],
  [Receipt, '05', 'Rõ khoản thu. Rõ trạng thái.', 'Lập hóa đơn từ hợp đồng, theo dõi số đã thu và còn phải thu. Cấu hình thanh toán theo nhu cầu.'],
  [FileCheck2, '06', 'Chuẩn bị hồ sơ cư trú.', 'Tạo và in hồ sơ CT01 từ dữ liệu quản lý. Chủ trọ tự kiểm tra và thực hiện thủ tục với cơ quan có thẩm quyền.'],
]

export default function LandingPage() {
  const session = useSyncExternalStore(subscribeSession, getSession, getSession)
  const [menuOpen, setMenuOpen] = useState(false)
  useEffect(() => {
    const previous = document.title
    document.title = 'BeeHouse — Quản lý nhà trọ, gọn gàng hơn mỗi ngày'
    return () => { document.title = previous }
  }, [])
  if (session.token) return <Navigate to="/dashboard" replace />
  return <div className="landing">
    <a className="skip-link" href="#main-content">Đến nội dung chính</a>
    <header className="landing-nav"><Link to="/" className="landing-brand" aria-label="BeeHouse — Trang chủ"><img src={logo} width="38" height="38" alt="" /><span>BeeHouse</span></Link><nav aria-label="Điều hướng giới thiệu" className="landing-desktop-nav"><a href="#features">Tính năng</a><a href="#how-it-works">Cách hoạt động</a><a href="#about">Giới thiệu</a></nav><div className="landing-nav-actions"><Link to="/login" className="landing-login">Đăng nhập</Link><Link to="/register" className="bh-button bh-button-dark">Bắt đầu ngay <ArrowUpRight /></Link></div><button className="landing-menu-button" aria-label={menuOpen?'Đóng menu':'Mở menu'} aria-expanded={menuOpen} aria-controls="landing-mobile-menu" onClick={()=>setMenuOpen(v=>!v)}>{menuOpen?<X />:<Menu />}</button>
    {menuOpen && <nav id="landing-mobile-menu" className="landing-mobile-nav" aria-label="Điều hướng di động" onKeyDown={event=>{if(event.key==='Escape')setMenuOpen(false)}}>{[['#features','Tính năng'],['#how-it-works','Cách hoạt động'],['#about','Giới thiệu']].map(([href,label])=><a key={href} href={href} onClick={()=>setMenuOpen(false)}>{label}<ArrowRight size={16} /></a>)}<Link to="/login">Đăng nhập</Link><Link to="/register">Đăng ký tài khoản <ArrowRight size={16} /></Link></nav>}
    </header>
    <main id="main-content"><section className="landing-hero"><div className="hero-eyebrow"><span /> Phần mềm quản lý nhà trọ</div><h1>Quản lý nhà trọ.<br /><span>Gọn gàng hơn mỗi ngày.</span></h1><p>Phòng, người thuê, hợp đồng, điện nước và hóa đơn. Tất cả được kết nối trong một không gian rõ ràng, dễ dùng.</p><div className="hero-actions"><Link to="/register" className="bh-button bh-button-dark">Bắt đầu với BeeHouse <ArrowRight size={18} /></Link><a href="#features" className="bh-button bh-button-light">Khám phá tính năng <ArrowDown size={17} /></a></div></section>
    <section className="landing-showcase" aria-label="Xem trước sản phẩm"><ProductPreview /></section>
    <section id="features" className="landing-section"><div className="section-heading"><div><span className="section-kicker">01 — CÔNG CỤ VỪA ĐỦ, KẾT NỐI ĐẦY ĐỦ</span><h2>Từ căn phòng đầu tiên<br />đến nhịp vận hành mỗi ngày.</h2></div><p>Mỗi thông tin có một nơi để lưu.<br />Mỗi công việc có một bước tiếp theo rõ ràng.</p></div><div className="feature-grid">{features.map(([Icon,n,title,description])=><article key={n} className="feature-item"><div className="feature-top"><Icon size={25} strokeWidth={1.5} aria-hidden="true" /><span>{n}</span></div><h3>{title}</h3><p>{description}</p></article>)}</div></section>
    <section id="how-it-works" className="landing-process"><div className="process-intro"><span className="section-kicker">02 — BẮT ĐẦU THEO CÁCH CỦA BẠN</span><h2>Một khởi đầu đơn giản.<br />Một cách làm gọn hơn.</h2><Link to="/register" className="text-link">Tạo tài khoản BeeHouse <ArrowRight size={18} /></Link></div><ol>{[['Tạo tài khoản','Đăng ký tài khoản để có không gian quản lý riêng.'],['Thiết lập nhà trọ và phòng','Thêm nhà trọ, sắp xếp tầng và tạo danh sách phòng.'],['Thêm người thuê','Lưu hồ sơ, lập hợp đồng và ghi nhận người ở.'],['Quản lý công việc mỗi kỳ','Ghi chỉ số, lập hóa đơn và theo dõi tình hình vận hành.']].map(([title,text],i)=><li key={title}><span>0{i+1}</span><div><h3>{title}</h3><p>{text}</p></div></li>)}</ol></section>
    <section id="about" className="landing-about"><span className="section-kicker">03 — VỀ BEEHOUSE</span><h2>Phần mềm nên giúp bạn<br /><span>nhẹ việc hơn.</span></h2><p>BeeHouse tập trung vào những công việc quen thuộc của chủ nhà trọ: biết phòng nào còn trống, hồ sơ nào cần tìm, hợp đồng nào sắp hết hạn và hóa đơn nào cần theo dõi. Không gian quản lý rõ ràng để bạn chủ động hơn mỗi ngày.</p></section>
    <section className="landing-final"><div><span className="section-kicker">KHÔNG GIAN QUẢN LÝ TIẾP THEO CỦA BẠN</span><h2>Bắt đầu gọn gàng.<br />Vận hành chủ động.</h2></div><Link to="/register" className="bh-button bh-button-white">Tạo tài khoản BeeHouse <ArrowRight size={20} /></Link></section></main>
    <footer className="landing-footer"><Link to="/" className="landing-brand"><img src={logo} width="32" height="32" alt="" />BeeHouse</Link><p>Quản lý nhà trọ. Gọn gàng hơn mỗi ngày.</p><nav aria-label="Liên kết cuối trang"><Link to="/login">Đăng nhập</Link><Link to="/register">Đăng ký</Link><a href="#features">Tính năng</a></nav><small>© {new Date().getFullYear()} BeeHouse</small></footer>
  </div>
}

function ArrowUpRight() { return <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M5 19 19 5M5 5h14v14" /></svg> }
