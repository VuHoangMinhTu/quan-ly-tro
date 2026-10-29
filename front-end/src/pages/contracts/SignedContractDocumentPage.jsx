import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { getSignedContractDocument } from '../../api/contractSigningApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDocumentFinalizedAt } from '../../utils/contractDocuments'
import { contractPrintTitle } from '../../utils/printDocument'
import FrozenContractHtml from '../../components/contracts/FrozenContractHtml'
import '@fontsource/be-vietnam-pro/400.css'
import '@fontsource/be-vietnam-pro/600.css'
import '@fontsource/be-vietnam-pro/700.css'
import './ContractDocumentPage.css'

export default function SignedContractDocumentPage() {
  const { id } = useParams()
  return <SignedDocument key={id} id={id} />
}

function SignedDocument({ id }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [ready, setReady] = useState(false)
  useEffect(() => {
    const controller = new AbortController()
    getSignedContractDocument(id, controller.signal).then(response => { if (!controller.signal.aborted) setData(response.data.data) })
      .catch(failure => { if (!controller.signal.aborted) setError(getApiErrorMessage(failure)) })
    return () => controller.abort()
  }, [id])
  const printTitle = data ? contractPrintTitle(data.source_snapshot) : ''
  useEffect(() => {
    if (!printTitle) return undefined
    const previous = document.title
    document.title = printTitle
    return () => { document.title = previous }
  }, [printTitle])
  return <main className="contract-document-page"><meta name="robots" content="noindex, nofollow, noarchive" /><nav className="contract-document-actions no-print"><Link to={`/contracts/${id}`}>← Quay lại hợp đồng</Link><button type="button" disabled={!ready} onClick={async () => { await document.fonts?.ready; window.print() }}>In hợp đồng / Lưu PDF</button>{data && <div className="w-full space-y-1 text-sm"><strong>{data.status === 'VOID' ? 'TÀI LIỆU ĐÃ HỦY — CHỈ XEM LƯU TRỮ' : 'HỢP ĐỒNG ĐÃ HOÀN TẤT KÝ ĐIỆN TỬ'}</strong><p>Hoàn tất lúc: {formatDocumentFinalizedAt(data.completed_at)}</p></div>}</nav>{error ? <p role="alert" className="text-red-700">{error}</p> : data ? <article className="contract-document-sheet">{data.status === 'VOID' && <p className="mb-4 border border-red-700 p-3 font-bold text-red-700">TÀI LIỆU ĐÃ HỦY — BẢN LƯU TRỮ LỊCH SỬ</p>}<FrozenContractHtml html={data.rendered_html} id={id} onReady={setReady} /></article> : <p role="status">Đang tải hợp đồng đã ký...</p>}</main>
}
