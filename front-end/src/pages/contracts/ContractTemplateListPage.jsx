import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { contractTemplateKeys, createContractTemplateVersion, getContractTemplates, setContractTemplateStatus } from '../../api/contractTemplateApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatDate } from '../../utils/formatters'

export default function ContractTemplateListPage() {
  const navigate = useNavigate()
  const client = useQueryClient()
  const [message, setMessage] = useState('')
  const query = useQuery({ queryKey: contractTemplateKeys.all, queryFn: getContractTemplates })
  const mutation = useMutation({ mutationFn: async ({ type, template }) => type === 'version' ? createContractTemplateVersion(template.id) : setContractTemplateStatus(template.id, !template.is_active), onSuccess: async (response, { type }) => {
    await client.invalidateQueries({ queryKey: contractTemplateKeys.all })
    await client.invalidateQueries({ queryKey: ['contract-context'] })
    if (type === 'version') navigate(`/contract-templates/${response.data.data.id}`)
    else setMessage('Đã cập nhật trạng thái mẫu hợp đồng.')
  } })
  if (query.isPending) return <p role="status">Đang tải mẫu hợp đồng...</p>
  if (query.isError) return <div role="alert"><p className="text-red-700">{getApiErrorMessage(query.error)}</p><button type="button" onClick={() => query.refetch()} className="min-h-11 underline">Thử lại</button></div>
  const { templates, can_manage: canManage } = query.data.data.data
  return <section className="min-w-0 space-y-5"><header className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-bold sm:text-2xl">Mẫu hợp đồng</h2>{canManage && <Link to="/contract-templates/new" className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white">+ Tạo mẫu riêng</Link>}</header>
    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Mẫu BeeHouse ban đầu chỉ minh họa cách điền dữ liệu. Chủ trọ cần bổ sung nội dung điều khoản từ mẫu hợp đồng của mình trước khi sử dụng chính thức.</p>
    {!canManage && <p className="text-sm text-slate-500">Chỉ chủ trọ được quản lý nội dung mẫu. Quản lý có thể chọn mẫu đang hoạt động khi lập hợp đồng được phân công.</p>}
    {message && <p role="status" className="text-sm text-green-700">{message}</p>}{mutation.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(mutation.error)}</p>}
    <div className="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">{templates.map(template => <article key={template.id} className="min-w-0 space-y-3 rounded-xl bg-white p-4 shadow-sm"><div className="flex items-start justify-between gap-2"><h3 className="min-w-0 break-words font-semibold">{template.name}</h3><span className={`shrink-0 rounded-full px-2 py-1 text-xs ${template.is_active ? 'bg-green-50 text-green-800' : 'bg-slate-100 text-slate-600'}`}>{template.is_active ? 'Đang sử dụng' : 'Ngừng sử dụng'}</span></div><p className="break-all text-xs text-slate-500">{template.template_key} · v{template.version}</p><p className="text-sm text-slate-600">{template.landlord_id == null ? 'Mẫu mặc định BeeHouse' : 'Mẫu riêng của chủ trọ'}<br />Ngày tạo: {formatDate(template.created_at)}<br />{template.documents_count ? `Đã dùng trong ${template.documents_count} tài liệu thuộc phạm vi của bạn` : 'Chưa có tài liệu trong phạm vi của bạn'}</p><div className="flex flex-wrap gap-2"><Link to={`/contract-templates/${template.id}`} className="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm">Xem</Link>{canManage && <button type="button" disabled={mutation.isPending} onClick={() => mutation.mutate({ type: 'version', template })} className="min-h-11 rounded-lg border px-3 text-sm disabled:opacity-50">{template.landlord_id == null ? 'Tạo mẫu riêng từ mẫu này' : 'Tạo phiên bản mới'}</button>}{canManage && template.landlord_id != null && <button type="button" disabled={mutation.isPending} onClick={() => mutation.mutate({ type: 'status', template })} className="min-h-11 rounded-lg border px-3 text-sm disabled:opacity-50">{template.is_active ? 'Ngừng sử dụng' : 'Kích hoạt'}</button>}</div></article>)}</div>
    {!templates.length && <p className="text-sm text-slate-500">Chưa có mẫu hợp đồng.</p>}
  </section>
}
