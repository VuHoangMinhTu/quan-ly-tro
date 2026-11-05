import { Link } from 'react-router-dom'
import { ArrowUpRight, Download, FileText, BedDouble } from 'lucide-react'

export default function ContractDemoCards() {
  return <section className="space-y-4" aria-labelledby="contract-demo-heading">
    <h3 id="contract-demo-heading" className="bh-library-heading">Mẫu hợp đồng minh họa <span className="bh-label">DEMO</span></h3>
    <div className="bh-template-demo-grid">{[{ type: 'room', name: 'Hợp đồng phòng trọ', detail: 'Phòng 101 có gác · 3.000.000đ/tháng · 12 tháng', icon: FileText }, { type: 'dorm', name: 'Hợp đồng KTX', detail: 'Phòng K201, giường G01 · 1.500.000đ/tháng · 6 tháng', icon: BedDouble }].map(item => <article key={item.type} className="bh-template-card">
      <div className="bh-template-card-head"><span className="bh-template-document-icon"><item.icon size={26} strokeWidth={1.5} aria-hidden="true" /></span><span className="bh-label">DỮ LIỆU MINH HỌA</span></div>
      <h4>{item.name}</h4><p>{item.detail}</p><p>Dữ liệu giả lập, không dùng cho giao dịch thực tế.</p>
      <div className="bh-template-card-actions"><Link to={`/contract-templates/demos/${item.type}`}>Xem mẫu DEMO <ArrowUpRight size={15} aria-hidden="true" /></Link><Link to={`/contract-templates/demos/${item.type}?print=1`} target="_blank" rel="noreferrer" className="bh-action-primary"><Download size={15} aria-hidden="true" />Xuất PDF</Link></div>
    </article>)}</div>
  </section>
}
