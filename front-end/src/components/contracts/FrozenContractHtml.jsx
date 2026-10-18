import { useEffect, useRef, useState } from 'react'
import { getSignatureImage } from '../../api/contractSigningApi'

export default function FrozenContractHtml({ html, id, token, onReady }) {
  const container = useRef(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    const controller = new AbortController()
    const urls = []
    const images = [...container.current.querySelectorAll('img[data-signature-id]')]
    Promise.resolve().then(() => { if (!controller.signal.aborted) { setFailed(false); onReady?.(false) } }).then(() => Promise.all(images.map(async image => {
      const signatureId = image.dataset.signatureId
      if (!/^\d+$/.test(signatureId)) throw new Error('Invalid signature reference')
      const response = await getSignatureImage({ id, token }, signatureId, controller.signal)
      if (controller.signal.aborted) return
      const url = URL.createObjectURL(response.data); urls.push(url)
      await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; image.src = url })
    }))).then(() => { if (!controller.signal.aborted) onReady?.(true) })
      .catch(() => { if (!controller.signal.aborted) setFailed(true) })
    return () => { controller.abort(); urls.forEach(url => URL.revokeObjectURL(url)) }
  }, [html, id, token, onReady])
  return <>{failed && <p role="alert" className="no-print my-3 text-sm text-red-700">Chưa tải được ảnh chữ ký. Vui lòng tải lại trang trước khi in.</p>}<div ref={container} className="contract-document-html" dangerouslySetInnerHTML={{ __html: html }} /></>
}
