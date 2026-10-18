import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { buildSignaturePayload, SIGNING_STATUS_LABELS } from '../../utils/contractSigning'
import { declineContractSigning, getSigningContext, requestSigningOtp, submitContractSignature, verifySigningOtp } from '../../api/contractSigningApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import SignatureCanvas from '../../components/contracts/SignatureCanvas'
import FrozenContractHtml from '../../components/contracts/FrozenContractHtml'
import beeHouseLogo from '../../assets/logo_bee_mau.png'
import '@fontsource/be-vietnam-pro/400.css'
import '@fontsource/be-vietnam-pro/600.css'
import '@fontsource/be-vietnam-pro/700.css'
import './ContractDocumentPage.css'

const button = 'inline-flex min-h-11 items-center justify-center rounded-lg border px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50'
const input = 'mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base'

export default function ContractSigningPage({ publicSigning = false }) {
  const { id, token } = useParams()
  // Remount local PII/OTP/canvas state on route identity changes; never cache opaque tokens in query keys.
  return <SigningForm key={publicSigning ? token : id} id={id} token={publicSigning ? token : undefined} />
}

function SigningForm({ id, token }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [otp, setOtp] = useState('')
  const [consent, setConsent] = useState(false)
  const [hasInk, setHasInk] = useState(false)
  const [reason, setReason] = useState('')
  const [showDecline, setShowDecline] = useState(false)
  const [imagesReady, setImagesReady] = useState(false)
  const canvas = useRef(null)
  const busy = useRef(false)
  const mounted = useRef(true)
  const verification = useRef('')
  const target = { id, token }
  const refresh = async () => { const response = await getSigningContext({ id, token, verificationToken: verification.current }); if (mounted.current) setData(response.data.data) }
  useEffect(() => {
    mounted.current = true
    const controller = new AbortController()
    getSigningContext({ id, token }, controller.signal).then(response => { if (!controller.signal.aborted) setData(response.data.data) })
      .catch(failure => { if (!controller.signal.aborted) setError(getApiErrorMessage(failure)) })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => { mounted.current = false; controller.abort() }
  }, [id, token])
  const perform = async action => {
    if (busy.current) return
    busy.current = true; setPending(true); setError(''); setMessage('')
    try { await action() } catch (failure) { if (mounted.current) setError(getApiErrorMessage(failure)) }
    finally { busy.current = false; if (mounted.current) setPending(false) }
  }
  const ready = useCallback(value => setImagesReady(value), [])
  const signed = data?.request_status === 'SIGNED'
  const complete = data?.signing_status === 'COMPLETED'
  return <main className="contract-signing-page min-h-dvh bg-slate-100 px-3 py-5 text-slate-900 sm:px-6" style={{ fontFamily: "'Be Vietnam Pro', Arial, sans-serif" }}>
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <div className="contract-signing-shell mx-auto max-w-4xl space-y-5">
      <header className="no-print flex items-center gap-3"><img src={beeHouseLogo} alt="" className="h-12 w-12 rounded-lg bg-white object-contain p-1" /><div><h1 className="text-xl font-bold">BeeHouse</h1><p className="text-sm text-slate-600">Xác nhận ký hợp đồng điện tử</p></div></header>
      {loading && <p role="status">Đang tải tài liệu cần ký...</p>}
      {error && <p role="alert" className="no-print break-words rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}{!data && <span className="mt-2 block">Vui lòng liên hệ bên cho thuê để nhận liên kết phù hợp.</span>}</p>}
      {data && <>
        <section className="no-print space-y-2 rounded-xl bg-white p-4 shadow-sm sm:p-6"><h2 className="text-lg font-bold">Hợp đồng {data.contract_code}</h2><p className="break-words text-sm">Phòng {data.room_code} — {data.boarding_house_name}</p><p className="text-sm">Người ký: <strong>{data.signer_name}</strong> — {data.signer_role === 'LESSOR' ? 'Bên A' : 'Bên B'}</p><p className="text-sm font-semibold">{SIGNING_STATUS_LABELS[data.signing_status]}</p><a href="#signing-document" className="inline-flex min-h-11 items-center text-sm underline">Xem toàn bộ hợp đồng</a></section>
        <section id="signing-document" className="contract-document-sheet scroll-mt-4 !w-full !p-4 sm:!p-8"><FrozenContractHtml html={complete ? data.signed_rendered_html : data.rendered_html} id={id} token={token} onReady={ready} /></section>
        {signed ? <section className="no-print space-y-3 rounded-xl bg-green-50 p-4 text-green-900"><h2 className="font-semibold">{complete ? 'Hợp đồng đã hoàn tất ký.' : 'Bạn đã xác nhận ký hợp đồng. Đang chờ bên còn lại.'}</h2>{!complete && <button type="button" className={button} disabled={pending} onClick={() => perform(refresh)}>Cập nhật tình trạng ký</button>}{complete && <button type="button" className={button} disabled={!imagesReady} onClick={async () => { await document.fonts?.ready; window.print() }}>In hợp đồng / Lưu PDF</button>}</section> : <section className="no-print space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
          <div className="space-y-3"><h2 className="font-semibold">Xác thực danh tính</h2><p className="text-sm text-slate-600">Mã OTP sẽ được gửi tới {data.masked_email}. Không chia sẻ mã hoặc liên kết này.</p><button type="button" disabled={pending} className={button} onClick={() => perform(async () => { await requestSigningOtp(target); verification.current = ''; setMessage('Đã gửi mã OTP. Vui lòng kiểm tra email và thư mục Spam / Thư rác.'); await refresh() })}>Gửi / Gửi lại mã OTP</button>
            <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-end"><label className="w-full max-w-xs text-sm font-medium">Mã OTP<input value={otp} onChange={event => setOtp(event.target.value.replace(/\D/g, '').slice(0, 6))} autoComplete="one-time-code" inputMode="numeric" maxLength={6} placeholder="6 chữ số" className={input} disabled={pending} /></label><button type="button" disabled={pending || otp.length !== 6} className={button} onClick={() => perform(async () => { const response = await verifySigningOtp(target, otp); verification.current = response.data.data.verification_token; setOtp(''); await refresh(); setMessage('Đã xác thực OTP. Vui lòng đọc, đồng ý và vẽ chữ ký bên dưới.') })}>Xác thực OTP</button></div>
            {data.otp_verified && <p className="text-sm font-medium text-green-700">Đã xác thực OTP. Vui lòng hoàn tất ký trước khi mã hết hạn.</p>}
          </div>
          <label className="flex min-h-11 items-start gap-3 text-sm leading-relaxed"><input type="checkbox" checked={consent} disabled={pending} onChange={event => setConsent(event.target.checked)} className="mt-1 h-5 w-5 shrink-0" /><span>{data.consent_text}</span></label>
          <div className="space-y-3"><h3 className="text-sm font-semibold">Chữ ký vẽ tay</h3><p className="text-xs text-slate-500">Dùng chuột hoặc ngón tay để ký trong khung. Nếu không thể sử dụng khung ký, vui lòng liên hệ bên cho thuê để chọn cách xác nhận khác.</p><SignatureCanvas ref={canvas} onChange={setHasInk} disabled={pending} /><button type="button" disabled={pending || !hasInk} className={button} onClick={() => canvas.current.clear()}>Xóa chữ ký</button></div>
          <button type="button" disabled={pending || !consent || !hasInk || !data.otp_verified} className={`${button} w-full bg-slate-900 text-white sm:w-auto`} onClick={() => perform(async () => { const blob = await canvas.current.exportPng(); if (!blob) { setError('Vui lòng vẽ chữ ký trước khi xác nhận.'); return } await submitContractSignature(target, buildSignaturePayload(data, blob, verification.current)); await refresh(); setConsent(false); setHasInk(false); setMessage('Xác nhận ký hợp đồng thành công.') })}>{pending ? 'Đang xử lý...' : 'Xác nhận ký hợp đồng'}</button>
          {token && <div className="border-t pt-4"><button type="button" className={`${button} text-red-700`} disabled={pending} onClick={() => setShowDecline(value => !value)}>Từ chối ký</button>{showDecline && <div className="mt-3 space-y-3"><label className="block text-sm">Lý do từ chối<textarea value={reason} onChange={event => setReason(event.target.value)} maxLength={1000} className={input} rows={3} /></label><p className="text-xs text-slate-500">Cần xác thực OTP trước khi từ chối ký. Nội dung đã chốt không bị chỉnh sửa.</p><button type="button" disabled={pending || !reason.trim() || !data.otp_verified} className={`${button} text-red-700`} onClick={() => perform(async () => { await declineContractSigning({ ...target, verificationToken: verification.current }, reason.trim()); setData(null); setMessage('Đã ghi nhận từ chối ký. Vui lòng liên hệ bên cho thuê.') })}>Xác nhận từ chối ký</button></div>}</div>}
        </section>}
      </>}
      {message && <p role="status" className="no-print rounded-lg bg-blue-50 p-4 text-sm text-blue-900">{message}</p>}
      {!token && <Link to={`/contracts/${id}`} className="no-print inline-flex min-h-11 items-center text-sm underline">← Quay lại hợp đồng</Link>}
      <p className="no-print text-xs leading-relaxed text-slate-500">Xác nhận ký điện tử bằng OTP và chữ ký vẽ tay. Vui lòng đọc toàn bộ nội dung trước khi xác nhận.</p>
    </div>
  </main>
}
