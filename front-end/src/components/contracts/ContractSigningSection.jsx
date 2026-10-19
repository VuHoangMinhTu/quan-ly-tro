import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getContractSigning, resendContractSigning, signingKeys, startContractSigning, voidContractSigning } from '../../api/contractSigningApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { SIGNER_STATUS_LABELS, SIGNING_EVENT_LABELS, SIGNING_STATUS_LABELS } from '../../utils/contractSigning'
import { formatDocumentFinalizedAt } from '../../utils/contractDocuments'
import { useRef, useState } from 'react'

const button = 'inline-flex min-h-11 items-center justify-center rounded-lg border px-4 py-2 text-sm disabled:opacity-50'

export default function ContractSigningSection({ contract }) {
  const client = useQueryClient()
  const [reason, setReason] = useState('')
  const [showVoid, setShowVoid] = useState(false)
  const [message, setMessage] = useState('')
  const [checkingRecipient, setCheckingRecipient] = useState(false)
  const [recipientError, setRecipientError] = useState('')
  const preparing = useRef(false)
  const query = useQuery({ queryKey: signingKeys.detail(contract.id), queryFn: () => getContractSigning(contract.id), enabled: Boolean(contract.document), staleTime: 0, refetchOnWindowFocus: true })
  const mutation = useMutation({ mutationFn: action => action(), onSuccess: async response => {
    setMessage(response.data.message)
    await Promise.all([client.invalidateQueries({ queryKey: signingKeys.detail(contract.id) }), client.invalidateQueries({ queryKey: ['contract-document', String(contract.id)] })])
  } })
  const sendToTenant = async () => {
    if (preparing.current || mutation.isPending) return
    preparing.current = true
    setCheckingRecipient(true)
    setRecipientError('')
    setMessage('')
    try {
      // Re-read the contact, not the frozen document or a cached tenant profile.
      const response = await getContractSigning(contract.id)
      const current = response.data.data
      client.setQueryData(signingKeys.detail(contract.id), response)
      if (!current.tenant_contact) {
        setRecipientError('Backend chưa cung cấp email nhận ký hiện tại. Vui lòng kiểm tra phiên bản backend đã deploy.')
        return
      }
      const email = current.tenant_contact.email
      if (!email) {
        setRecipientError('Người thuê của hợp đồng này chưa có email. Vui lòng cập nhật đúng hồ sơ người thuê trước khi gửi.')
        return
      }
      if (['VOID', 'DECLINED', 'COMPLETED'].includes(current.status) || current.signers.some(signer => signer.role === 'TENANT' && signer.status === 'SIGNED')) {
        setRecipientError('Yêu cầu ký đã thay đổi trạng thái. Vui lòng kiểm tra lại tình trạng ký.')
        return
      }
      if (!window.confirm(`Gửi yêu cầu ký cho ${current.tenant_contact.name ?? 'người thuê'} tới email ${email}?`)) return
      mutation.mutate(async () => {
        const result = await (current.status === 'NOT_STARTED' ? startContractSigning(contract.id, email) : resendContractSigning(contract.id, email))
        if (!result.data.data?.recipient_email) {
          const failure = new Error('Máy chủ chưa xác nhận địa chỉ nhận email. Vui lòng kiểm tra phiên bản backend đã deploy trước khi gửi lại.')
          failure.response = { status: 409, data: { message: failure.message } }
          throw failure
        }
        return result
      })
    } catch (failure) {
      setRecipientError(getApiErrorMessage(failure))
    } finally {
      preparing.current = false
      setCheckingRecipient(false)
    }
  }
  if (!contract.document) return null
  if (query.isPending) return <p role="status">Đang tải tình trạng ký...</p>
  if (query.isError) return <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(query.error)}</p>
  const data = query.data.data.data
  const terminal = ['VOID', 'DECLINED', 'COMPLETED'].includes(data.status)
  const tenantSigned = data.signers.some(signer => signer.role === 'TENANT' && signer.status === 'SIGNED')
  const lessorSigned = data.signers.some(signer => signer.role === 'LESSOR' && signer.status === 'SIGNED')
  return <section className="min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6"><h3 className="text-lg font-semibold">Tình trạng ký</h3><p className="text-sm font-semibold">{SIGNING_STATUS_LABELS[data.status]}</p>
    <div className="grid gap-3 sm:grid-cols-2">{data.signers.map(signer => <div key={signer.role} className="min-w-0 space-y-1 rounded-lg border p-3"><h4 className="text-xs font-semibold text-slate-500">{signer.role === 'LESSOR' ? 'BÊN A — Bên cho thuê' : 'BÊN B — Người thuê'}</h4><p className="break-words font-medium">{signer.name}</p><p className={`text-sm ${signer.status === 'SIGNED' ? 'text-green-700' : 'text-slate-600'}`}>{signer.status === 'SIGNED' ? '✓ ' : '○ '}{SIGNER_STATUS_LABELS[signer.status] ?? 'Chưa ký'}</p>{signer.signed_at && <p className="text-xs text-slate-500">{formatDocumentFinalizedAt(signer.signed_at)}</p>}</div>)}</div>
    <div className="space-y-2 rounded-lg bg-slate-50 p-3 text-sm">
      <p className="break-words">Email người thuê hiện tại: <strong>{data.tenant_contact?.email ?? 'Chưa cập nhật'}</strong></p>
      {data.signers.filter(signer => signer.role === 'TENANT' && signer.last_sent_at).map(signer => <p key={signer.role} className="break-words text-slate-600">Lần gửi gần nhất: {signer.delivery_email} · {formatDocumentFinalizedAt(signer.last_sent_at)}{signer.status === 'REVOKED' ? ' — liên kết đã thu hồi' : ''}</p>)}
      {data.is_owner && data.tenant_contact?.tenant_id && <Link className="inline-block underline" to={`/tenants/${data.tenant_contact.tenant_id}/edit`}>Kiểm tra / sửa đúng người thuê của hợp đồng này</Link>}
      <p className="text-xs text-slate-500">Email nhận link/OTP lấy từ hồ sơ người thuê hiện tại, không phải email trong nội dung hợp đồng đã chốt.</p>
    </div>
    <div className="flex flex-wrap gap-3">{!terminal && !tenantSigned && <button type="button" disabled={mutation.isPending || checkingRecipient} className={`${button} bg-slate-900 text-white`} onClick={sendToTenant}>{checkingRecipient ? 'Đang kiểm tra email...' : data.status === 'NOT_STARTED' ? 'Gửi yêu cầu ký cho người thuê' : 'Gửi lại yêu cầu ký'}</button>}
      {!terminal && data.status !== 'NOT_STARTED' && data.is_representative && !lessorSigned && <Link to={`/contracts/${contract.id}/sign`} className={button}>Ký hợp đồng — Bên A</Link>}
      {!terminal && tenantSigned && data.is_representative && !lessorSigned && <button type="button" className={button} disabled={mutation.isPending} onClick={() => mutation.mutate(() => resendContractSigning(contract.id))}>Làm mới yêu cầu ký Bên A</button>}
      {data.completed_at && <Link to={`/contracts/${contract.id}/document/signed`} className={button}>Xem / In hợp đồng đã ký</Link>}
      {data.is_owner && data.status !== 'VOID' && <button type="button" className={`${button} text-red-700`} disabled={mutation.isPending} onClick={() => setShowVoid(value => !value)}>Hủy tài liệu ký</button>}
    </div>
    {showVoid && <div className="space-y-3 rounded-lg bg-red-50 p-3"><label className="block text-sm font-medium">Lý do hủy *<textarea value={reason} maxLength={1000} onChange={event => setReason(event.target.value)} className="mt-2 w-full rounded-lg border bg-white p-3" rows={3} /></label><p className="text-xs text-red-800">Liên kết chưa ký sẽ bị thu hồi. Không xóa chữ ký/lịch sử đã có và không thay đổi trạng thái nghiệp vụ của hợp đồng.</p><button type="button" className={`${button} text-red-700`} disabled={mutation.isPending || !reason.trim()} onClick={() => { if (window.confirm('Hủy tài liệu ký này? Không thể tiếp tục ký bằng liên kết cũ.')) mutation.mutate(() => voidContractSigning(contract.id, reason.trim())) }}>Xác nhận hủy tài liệu ký</button></div>}
    {data.void_reason && <p className="break-words text-sm text-red-700">Lý do hủy: {data.void_reason}</p>}
    {recipientError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{recipientError}</p>}
    {mutation.isError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{getApiErrorMessage(mutation.error)}</p>}{message && <p role="status" className="text-sm text-green-700">{message}</p>}
    <details><summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">Lịch sử ký ({data.events.length})</summary><ol className="space-y-3 border-l pl-4">{data.events.map(event => <li key={event.id} className="space-y-1"><p className="text-sm">{SIGNING_EVENT_LABELS[event.event] ?? 'Cập nhật quá trình ký'}</p><p className="text-xs text-slate-500">{formatDocumentFinalizedAt(event.created_at)}</p>{event.metadata?.reason && <p className="break-words text-sm text-slate-600">Lý do: {event.metadata.reason}</p>}</li>)}</ol></details>
    <p className="text-xs leading-relaxed text-slate-500">Tài liệu đã chốt được xác nhận bằng OTP và chữ ký vẽ tay. Nếu thông tin sai, hãy hủy tài liệu và lập hợp đồng mới; không chuyển chữ ký sang bản khác.</p>
  </section>
}
