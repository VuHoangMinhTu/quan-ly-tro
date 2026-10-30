import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { contractDocumentKeys, finalizeContractDocument } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDocumentFinalizedAt } from '../../utils/contractDocuments'

export default function ContractDocumentSection({ contract, handoverConfirmed = false }) {
  const client = useQueryClient()
  const navigate = useNavigate()
  const finalize = useMutation({ mutationFn: () => finalizeContractDocument(contract.id), onSuccess: async () => {
    await Promise.all(['contract', 'contracts', 'room', 'room-tenants', 'contract-context', 'residence-context', 'contract-templates'].map(key => client.invalidateQueries({ queryKey: [key] })))
    await client.invalidateQueries({ queryKey: contractDocumentKeys.detail(contract.id) })
    navigate(`/contracts/${contract.id}/document`)
  } })
  const document = contract.document
  const template = contract.contract_template
  return <section className="min-w-0 space-y-3 rounded-xl bg-white p-4 shadow-sm sm:p-6"><h3 className="text-lg font-semibold">Tài liệu hợp đồng</h3>
    {contract.is_demo ? <><p className="text-sm">DEMO — bản trải nghiệm không phải hợp đồng đầy đủ. Chỉ lưu nháp và in/PDF; không thể kích hoạt hay chốt chính thức.</p><Link to={`/contracts/${contract.id}/document?preview=1`} className="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm">Xem bản DEMO / In / Lưu PDF</Link></> : document ? <><p className="break-words font-medium">{document.template_name}</p><p className="text-sm text-slate-500">Phiên bản mẫu: v{document.template_version}<br />Đã chốt lúc: {formatDocumentFinalizedAt(document.finalized_at)}</p><div className="flex flex-wrap gap-3"><Link to={`/contracts/${contract.id}/document`} className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white">Xem hợp đồng</Link><Link to={`/contracts/${contract.id}/document`} className="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm">In hợp đồng / Lưu PDF</Link></div><p className="text-xs text-slate-500">Tài liệu được giữ nguyên theo thời điểm chốt. Chốt dữ liệu không đồng nghĩa các bên đã ký.</p></> : contract.status === 'DRAFT' ? <>
      <p className="break-words text-sm">Mẫu: {template ? `${template.name} — v${template.version}` : 'Mẫu đang hoạt động phù hợp khi chốt'}</p><p className="text-sm text-slate-500">Xem trước dùng dữ liệu hiện tại của bản nháp. Chốt hợp đồng sẽ kích hoạt hợp đồng và lưu một bản tài liệu không tự thay đổi theo danh mục.</p>{!handoverConfirmed && <p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Cần tạo và xác nhận <a href="#contract-handover" className="font-medium underline">biên bản bàn giao tình trạng đồ vật</a> trước khi chốt hợp đồng.</p>}<div className="flex flex-wrap gap-3"><Link to={`/contracts/${contract.id}/document?preview=1`} className="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm">Preview hợp đồng</Link><button type="button" disabled={finalize.isPending || !handoverConfirmed} onClick={() => { if (window.confirm('Chốt sẽ kích hoạt hợp đồng và lưu tài liệu cố định; thao tác này chưa tạo chữ ký. Bạn có muốn tiếp tục?')) finalize.mutate() }} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:cursor-not-allowed disabled:opacity-50">{finalize.isPending ? 'Đang chốt...' : 'Chốt hợp đồng'}</button><Link to={`/contracts/${contract.id}/edit`} className="inline-flex min-h-11 items-center text-sm underline">Đổi mẫu / bổ sung thông tin</Link></div>
    </> : <p className="text-sm text-slate-500">Chưa có tài liệu hợp đồng được chốt. Hợp đồng cũ không được tự tạo lại tài liệu rồi coi là bản đã xác nhận trước đó.</p>}
    {finalize.isError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{getApiErrorMessage(finalize.error)}</p>}
  </section>
}
