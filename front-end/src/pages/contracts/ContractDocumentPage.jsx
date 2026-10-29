import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import '@fontsource/be-vietnam-pro/400.css'
import '@fontsource/be-vietnam-pro/600.css'
import '@fontsource/be-vietnam-pro/700.css'
import { contractDocumentKeys, getContractDocument, previewContractDocument } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDocumentFinalizedAt } from '../../utils/contractDocuments'
import { contractPrintTitle } from '../../utils/printDocument'
import ContractDocumentSheet from '../../components/contracts/ContractDocumentSheet'
import './ContractDocumentPage.css'

export default function ContractDocumentPage() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const preview = params.get('preview') === '1'
  const query = useQuery({ queryKey: preview ? contractDocumentKeys.preview(id) : contractDocumentKeys.detail(id), queryFn: () => preview ? previewContractDocument(id) : getContractDocument(id), staleTime: 0 })
  const data = query.data?.data?.data
  const printTitle = data ? contractPrintTitle(data.source_snapshot) : ''
  useEffect(() => {
    if (!printTitle) return undefined
    const previous = document.title
    document.title = printTitle
    return () => { document.title = previous }
  }, [printTitle])
  if (query.isPending) return <main className="p-5" role="status">Đang tải văn bản hợp đồng...</main>
  if (query.isError) return <main className="space-y-3 p-5"><p role="alert" className="text-red-700">{getApiErrorMessage(query.error)}</p><Link to={`/contracts/${id}`} className="inline-flex min-h-11 items-center underline">Quay lại hợp đồng</Link></main>
  return <main className="contract-document-page"><nav className="contract-document-actions no-print" aria-label="Thao tác tài liệu hợp đồng"><Link to={`/contracts/${id}`}>← Quay lại hợp đồng</Link>{!data.is_preview && <button type="button" onClick={async () => { await document.fonts?.ready; window.print() }}>In hợp đồng / Lưu PDF</button>}<div className="w-full text-sm"><strong>{data.is_preview ? 'BẢN XEM TRƯỚC — CHƯA CHỐT' : 'TÀI LIỆU ĐÃ CHỐT'}</strong><p>{data.template_name} — v{data.template_version}{data.finalized_at ? ` · ${formatDocumentFinalizedAt(data.finalized_at)}` : ''}</p><p>{data.is_preview ? 'Nội dung bản nháp có thể thay đổi trước khi chốt.' : 'Khi lưu PDF, chọn A4 và tắt “Headers and footers” trong hộp thoại in để không xuất URL, ngày giờ và số trang của trình duyệt.'}</p></div></nav>
    <ContractDocumentSheet html={data.rendered_html} />
  </main>
}
