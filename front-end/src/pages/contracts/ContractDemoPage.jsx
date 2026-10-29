import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { getContractDemo } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import ContractDocumentSheet from '../../components/contracts/ContractDocumentSheet'
import { contractPrintTitle } from '../../utils/printDocument'
import './ContractDocumentPage.css'

export default function ContractDemoPage() {
  const { type } = useParams()
  const [params] = useSearchParams()
  const printRequested = params.get('print') === '1'
  const validType = ['room', 'dorm'].includes(type)
  const query = useQuery({ queryKey: ['contract-template-demo', type], queryFn: () => getContractDemo(type), enabled: validType, retry: false })
  const demo = query.data?.data?.data
  useEffect(() => {
    if (!demo || !validType) return
    let active = true
    const previous = document.title
    document.title = contractPrintTitle(demo.source_snapshot, true)
    if (printRequested) document.fonts.ready.then(() => { if (active) window.print() })
    return () => { active = false; document.title = previous }
  }, [demo, validType, printRequested])
  if (!validType) return <main className="p-5"><p role="alert">Không có mẫu DEMO này.</p><Link to="/contract-templates">Quay lại mẫu hợp đồng</Link></main>
  if (query.isPending) return <main className="p-5" role="status">Đang tải mẫu hợp đồng minh họa...</main>
  if (query.isError) return <main className="space-y-3 p-5"><p role="alert" className="text-red-700">{getApiErrorMessage(query.error)}</p><Link to="/contract-templates">Quay lại mẫu hợp đồng</Link></main>
  return <main className="contract-document-page contract-demo-page">
    <nav className="contract-document-actions no-print" aria-label="Thao tác mẫu minh họa">
      <Link to="/contract-templates">← Mẫu hợp đồng</Link>
      <button type="button" onClick={async () => { await document.fonts.ready; window.print() }}>In / Xuất PDF</button>
      <div className="w-full space-y-1 text-sm"><strong>{demo.name} — DEMO</strong><p>Chỉ để giới thiệu, không tạo hợp đồng hoặc dữ liệu giao dịch. Các bên ký trực tiếp trên giấy; vùng chữ ký để trống.</p><p>Chọn Save as PDF, khổ A4 và tắt Headers and footers. Tên file gợi ý: {demo.filename}</p></div>
    </nav>
    <ContractDocumentSheet html={demo.rendered_html} demo />
  </main>
}
