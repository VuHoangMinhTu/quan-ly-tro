import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { contractTemplateKeys, createContractTemplateVersion, deleteContractTemplate, getContractTemplates, setContractTemplateStatus } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDate } from '../../utils/formatters'
import ContractDemoCards from '../../components/contracts/ContractDemoCards'
import PageHeader from '../../components/ui/PageHeader'
import LoadingState from '../../components/ui/LoadingState'
import { FileText, ShieldCheck } from 'lucide-react'

export default function ContractTemplateListPage() {
  const navigate = useNavigate()
  const client = useQueryClient()
  const [message, setMessage] = useState('')
  const [showInactive, setShowInactive] = useState(false)
  const query = useQuery({ queryKey: contractTemplateKeys.all, queryFn: getContractTemplates })
  const mutation = useMutation({ mutationFn: async ({ type, template }) => type === 'version' ? createContractTemplateVersion(template.id) : setContractTemplateStatus(template.id, !template.is_active), onSuccess: async (response, { type }) => {
    await client.invalidateQueries({ queryKey: contractTemplateKeys.all })
    await client.invalidateQueries({ queryKey: ['contract-context'] })
    if (type === 'version') navigate(`/contract-templates/${response.data.data.id}`)
    else setMessage('Đã cập nhật trạng thái mẫu hợp đồng.')
  } })
  const remove = useMutation({ mutationFn: deleteContractTemplate, onSuccess: async () => {
    await client.invalidateQueries({ queryKey: contractTemplateKeys.all })
    await client.invalidateQueries({ queryKey: ['contract-context'] })
    setMessage('Đã xóa mẫu hợp đồng.')
  } })
  if (query.isPending) return <LoadingState label="Đang tải mẫu hợp đồng…" />
  if (query.isError) return <div role="alert"><p className="text-red-700">{getApiErrorMessage(query.error)}</p><button type="button" onClick={() => query.refetch()} className="min-h-11 underline">Thử lại</button></div>
  const { templates, can_manage: canManage } = query.data.data.data
  const inactiveCount = templates.filter(template => !template.is_active).length
  const visibleTemplates = showInactive ? templates : templates.filter(template => template.is_active)
  return <section className="bh-workspace min-w-0 space-y-6"><PageHeader title="Mẫu hợp đồng" eyebrow="THƯ VIỆN TÀI LIỆU" description="Chọn mẫu phù hợp. Kiểm tra nội dung trước khi sử dụng.">{canManage && <Link to="/contract-templates/new" className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white">+ Tạo mẫu riêng</Link>}</PageHeader>
    <p className="bh-library-summary"><ShieldCheck size={20} aria-hidden="true" />BeeHouse cung cấp mẫu cho thuê phòng tiêu chuẩn để tham khảo và tùy chỉnh. Chủ trọ hãy kiểm tra nội dung trước khi chốt hợp đồng.</p>
    <ContractDemoCards />
    {!canManage && <p className="text-sm text-slate-500">Chỉ chủ trọ được quản lý nội dung mẫu. Quản lý có thể chọn mẫu đang hoạt động khi lập hợp đồng được phân công.</p>}
    {inactiveCount > 0 && <div className="flex flex-wrap items-center gap-3"><button type="button" aria-expanded={showInactive} aria-controls="contract-template-list" onClick={() => setShowInactive(value => !value)} className="min-h-11 rounded-lg border px-3 text-sm text-slate-700">{showInactive ? 'Ẩn phiên bản cũ và mẫu ngừng sử dụng' : `Xem phiên bản cũ và mẫu ngừng sử dụng (${inactiveCount})`}</button><span className="text-sm text-slate-500">Mẫu BeeHouse dùng chung được giữ để bảo toàn lịch sử; mẫu riêng chưa sử dụng có thể xóa.</span></div>}
    {message && <p role="status" className="text-sm text-green-700">{message}</p>}{mutation.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(mutation.error)}</p>}{remove.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(remove.error)}</p>}
    <h3 className="bh-library-heading">Mẫu trong phạm vi tài khoản</h3>
    <div id="contract-template-list" className="bh-template-grid">{visibleTemplates.map(template => <article key={template.id} className="bh-template-card"><div className="bh-template-card-head"><span className="bh-template-document-icon"><FileText size={26} strokeWidth={1.5} aria-hidden="true" /></span><span className="bh-label">{template.template_key?.includes('_DEMO') ? 'DEMO' : 'MẪU CHÍNH THỨC'}</span></div><h3>{template.name}</h3><p>{template.is_active ? 'Đang hoạt động' : 'Ngừng sử dụng'} · {template.landlord_id == null ? 'BeeHouse' : 'Mẫu riêng'} · v{template.version}</p><p>Ngày tạo: {formatDate(template.created_at)}<br />{template.documents_count ? `Đã dùng trong ${template.documents_count} tài liệu thuộc phạm vi của bạn` : 'Chưa có tài liệu trong phạm vi của bạn'}</p><div className="bh-template-card-actions"><Link to={`/contract-templates/${template.id}`} className="bh-action-primary">Xem</Link>{canManage && <button type="button" disabled={mutation.isPending || remove.isPending} onClick={() => mutation.mutate({ type: 'version', template })}>{template.landlord_id == null ? 'Tạo bản sao' : 'Tạo phiên bản mới'}</button>}{canManage && template.landlord_id != null && <button type="button" disabled={mutation.isPending || remove.isPending} onClick={() => mutation.mutate({ type: 'status', template })}>{template.is_active ? 'Ngừng sử dụng' : 'Kích hoạt'}</button>}{canManage && template.landlord_id != null && <button type="button" disabled={mutation.isPending || remove.isPending} onClick={() => window.confirm(`Xóa mẫu “${template.name}”? Thao tác này không thể hoàn tác.`) && remove.mutate(template.id)} className="text-red-700">Xóa</button>}</div></article>)}</div>
    {!visibleTemplates.length && <p className="text-sm text-slate-500">{templates.length ? 'Chưa có mẫu đang hoạt động. Bạn có thể xem mẫu ngừng sử dụng ở trên.' : 'Chưa có mẫu hợp đồng.'}</p>}
  </section>
}
