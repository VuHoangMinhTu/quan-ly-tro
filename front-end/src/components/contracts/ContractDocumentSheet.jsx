import { normalizeHandoverPrint } from '../../utils/contractHandoverPrint'

export default function ContractDocumentSheet({ html, demo = false }) {
  // Preserve the frozen document; only the paper view replaces legacy unsigned labels.
  const paperHtml = normalizeHandoverPrint(html).replaceAll('<p>Chưa ký</p>', '<div class="signature-handwritten-space"></div>')
  return <article className="contract-document-sheet">
    {demo && <p className="contract-demo-watermark">BẢN MẪU – DỮ LIỆU MINH HỌA</p>}
    <div className="contract-document-html" dangerouslySetInnerHTML={{ __html: paperHtml }} />
  </article>
}
