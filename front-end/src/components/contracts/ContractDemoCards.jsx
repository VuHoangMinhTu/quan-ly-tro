import { Link } from 'react-router-dom'

export default function ContractDemoCards() {
  return <section className="space-y-3" aria-labelledby="contract-demo-heading">
    <h3 id="contract-demo-heading" className="text-lg font-semibold">Mẫu hợp đồng minh họa</h3>
    <div className="grid gap-4 sm:grid-cols-2">{[{ type: 'room', name: 'Hợp đồng phòng trọ', detail: 'Phòng 101 có gác · 3.000.000đ/tháng · 12 tháng' }, { type: 'dorm', name: 'Hợp đồng KTX', detail: 'Phòng K201, giường G01 · 1.500.000đ/tháng · 6 tháng' }].map(item => <article key={item.type} className="min-w-0 space-y-3 rounded-xl border border-slate-200 bg-white p-4">
      <h4 className="font-semibold">{item.name}</h4><p className="text-sm text-slate-600">{item.detail}</p><p className="text-xs text-slate-500">Dữ liệu giả lập, không dùng cho giao dịch thực tế.</p>
      <div className="flex flex-wrap gap-2"><Link to={`/contract-templates/demos/${item.type}`} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Xem mẫu DEMO</Link><Link to={`/contract-templates/demos/${item.type}?print=1`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-3 text-sm text-white">Xuất PDF</Link></div>
    </article>)}</div>
  </section>
}
