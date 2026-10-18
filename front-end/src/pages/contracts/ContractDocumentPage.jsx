import { useQuery } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import '@fontsource/be-vietnam-pro/400.css'
import '@fontsource/be-vietnam-pro/600.css'
import '@fontsource/be-vietnam-pro/700.css'
import { contractDocumentKeys, getContractDocument, previewContractDocument } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDocumentFinalizedAt } from '../../utils/contractDocuments'
import './ContractDocumentPage.css'

export default function ContractDocumentPage() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const preview = params.get('preview') === '1'
  const query = useQuery({ queryKey: preview ? contractDocumentKeys.preview(id) : contractDocumentKeys.detail(id), queryFn: () => preview ? previewContractDocument(id) : getContractDocument(id), staleTime: 0 })
  if (query.isPending) return <main className="p-5" role="status">Đang tải văn bản hợp đồng...</main>
  if (query.isError) return <main className="space-y-3 p-5"><p role="alert" className="text-red-700">{getApiErrorMessage(query.error)}</p><Link to={`/contracts/${id}`} className="inline-flex min-h-11 items-center underline">Quay lại hợp đồng</Link></main>
  const data = query.data.data.data
  return <main className="contract-document-page"><nav className="contract-document-actions no-print" aria-label="Thao tác tài liệu hợp đồng"><Link to={`/contracts/${id}`}>← Quay lại hợp đồng</Link>{!data.is_preview && <button type="button" onClick={async () => { await document.fonts?.ready; window.print() }}>In hợp đồng / Lưu PDF</button>}<div className="w-full text-sm"><strong>{data.is_preview ? 'BẢN XEM TRƯỚC — CHƯA CHỐT' : 'TÀI LIỆU ĐÃ CHỐT'}</strong><p>{data.template_name} — v{data.template_version}{data.finalized_at ? ` · ${formatDocumentFinalizedAt(data.finalized_at)}` : ''}</p><p>{data.is_preview ? 'Nội dung bản nháp có thể thay đổi trước khi chốt.' : 'Dùng hộp thoại in của trình duyệt để lưu PDF. Tài liệu này chưa có chữ ký điện tử hoặc chữ ký số.'}</p></div></nav>
    <article className="contract-document-sheet"><div className="contract-document-html" dangerouslySetInnerHTML={{ __html: data.rendered_html }} /></article>
  </main>
}
