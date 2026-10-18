import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { contractTemplateKeys, createContractTemplate, createContractTemplateVersion, getContractTemplate, getContractTemplates, previewContractTemplate, updateContractTemplate } from '../../api/contractTemplateApi'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage'
import { insertContractTemplateVariable, validateContractTemplateVariables } from '../../utils/contractDocuments'
import ContractTemplatePreview from '../../components/contracts/ContractTemplatePreview'

const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 disabled:bg-slate-50'

function TemplateEditor({ initial, variables, canManage, save, clone }) {
  const [name, setName] = useState(initial?.name ?? '')
  const [key, setKey] = useState(initial?.template_key ?? 'ROOM_RENTAL_STANDARD')
  const [content, setContent] = useState(initial?.content_html ?? '<h1 class="text-center">Hợp đồng {{contract.code}}</h1>\n<p>{{tenant.full_name}}</p>\n{{services_table}}\n{{occupants_table}}')
  const [preview, setPreview] = useState('')
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [pending, setPending] = useState(false)
  const [message, setMessage] = useState('')
  const textarea = useRef(null)
  const readOnly = !canManage || (initial && (initial.landlord_id == null || initial.documents_count > 0))
  const groups = variables.reduce((result, variable) => { (result[variable.group] ??= []).push(variable); return result }, {})
  const insert = placeholder => {
    const field = textarea.current
    const next = insertContractTemplateVariable(content, placeholder, field?.selectionStart ?? content.length, field?.selectionEnd ?? content.length)
    setContent(next.content); setPreview('')
    requestAnimationFrame(() => { field?.focus(); field?.setSelectionRange(next.caret, next.caret) })
  }
  const perform = async operation => {
    const invalid = validateContractTemplateVariables(content, variables)
    if (invalid) { setError(invalid); return }
    setPending(true); setError(''); setFieldErrors({}); setMessage('')
    try { await operation() } catch (failure) { setError(getApiErrorMessage(failure)); setFieldErrors(getApiFieldErrors(failure)) } finally { setPending(false) }
  }
  return <div className="min-w-0 space-y-4">
    {readOnly && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><p>{initial?.landlord_id == null ? 'Mẫu mặc định BeeHouse không được sửa trực tiếp. Hãy tạo mẫu riêng để chỉnh nội dung.' : 'Mẫu này đã được sử dụng hoặc bạn không có quyền sửa. Hãy tạo phiên bản mới để chỉnh sửa.'}</p>{canManage && <button type="button" disabled={pending} onClick={() => perform(clone)} className="mt-2 min-h-11 rounded-lg border border-amber-300 px-3">Tạo phiên bản riêng mới</button>}</div>}
    <form onSubmit={event => { event.preventDefault(); if (!readOnly) perform(async () => { await save({ name: name.trim(), template_key: key.trim(), content_html: content }); setMessage('Đã lưu nội dung mẫu hợp đồng.') }) }} className="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div className="min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6"><div className="grid min-w-0 gap-4 sm:grid-cols-2"><label className="text-sm font-medium">Tên mẫu *<input required disabled={readOnly || pending} value={name} onChange={event => setName(event.target.value)} maxLength={255} className={inputClass} /></label><label className="text-sm font-medium">Mã mẫu *<input required disabled={Boolean(initial) || readOnly || pending} value={key} onChange={event => setKey(event.target.value.toUpperCase())} pattern="[A-Z][A-Z0-9_]*" maxLength={80} className={inputClass} /></label></div>
        <label className="block text-sm font-medium">Nội dung HTML *<textarea ref={textarea} value={content} readOnly={readOnly} disabled={pending} onChange={event => { setContent(event.target.value); setPreview('') }} rows={20} maxLength={200000} className="mt-2 w-full min-w-0 rounded-lg border p-3 font-mono text-sm leading-relaxed" /></label>
        <p className="text-xs leading-relaxed text-slate-500">Hỗ trợ tiêu đề h1–h4, đoạn văn, strong/em, danh sách, bảng và div/span. Không dùng script, ảnh, liên kết, iframe hoặc style tùy ý. Class hỗ trợ: text-center, text-right, text-justify, document-signatures, page-break. Đặt các biến bảng trên dòng riêng.</p>
        {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}{Object.entries(fieldErrors).filter(([field]) => field !== 'content_html').map(([field, value]) => <p key={field} className="text-sm text-red-700">{value}</p>)}{message && <p role="status" className="text-sm text-green-700">{message}</p>}
        <div className="flex flex-wrap gap-3">{!readOnly && <button disabled={pending} className="min-h-11 rounded-lg bg-slate-900 px-4 text-sm text-white disabled:opacity-50">{pending ? 'Đang xử lý...' : 'Lưu nội dung'}</button>}{canManage && <button type="button" disabled={pending} onClick={() => perform(async () => { const response = await previewContractTemplate(content); setPreview(response.data.data.rendered_html) })} className="min-h-11 rounded-lg border px-4 text-sm disabled:opacity-50">Xem trước</button>}</div>
      </div>
      <aside className="min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm"><h3 className="font-semibold">Biến có thể sử dụng</h3>{Object.entries(groups).map(([group, items]) => <details key={group} open className="min-w-0"><summary className="cursor-pointer text-sm font-medium">{group}</summary><div className="mt-2 flex flex-wrap gap-2">{items.map(variable => <button key={variable.key} type="button" disabled={readOnly || pending} onClick={() => insert(variable.placeholder)} title={variable.placeholder} className="min-h-11 max-w-full rounded-lg border px-2 py-1 text-left text-xs disabled:opacity-60">{variable.label}</button>)}</div></details>)}</aside>
    </form>
    {preview && <section className="min-w-0 space-y-3 rounded-xl bg-white p-4 shadow-sm"><h3 className="font-semibold">Xem trước bằng dữ liệu minh họa</h3><p className="text-sm text-slate-500">Đây chưa phải tài liệu của một hợp đồng thật.</p><ContractTemplatePreview html={preview} /></section>}
  </div>
}

export default function ContractTemplateEditorPage() {
  const { id } = useParams()
  const client = useQueryClient()
  const navigate = useNavigate()
  const list = useQuery({ queryKey: contractTemplateKeys.all, queryFn: getContractTemplates })
  const query = useQuery({ queryKey: contractTemplateKeys.detail(id), queryFn: () => getContractTemplate(id), enabled: Boolean(id) })
  const mutation = useMutation({ mutationFn: payload => id ? updateContractTemplate(id, payload) : createContractTemplate(payload), onSuccess: async response => {
    await Promise.all([client.invalidateQueries({ queryKey: contractTemplateKeys.all }), client.invalidateQueries({ queryKey: contractTemplateKeys.detail(id) }), client.invalidateQueries({ queryKey: ['contract-context'] })])
    if (!id) navigate(`/contract-templates/${response.data.data.id}`, { replace: true })
  } })
  if (list.isPending || (id && query.isPending)) return <p role="status">Đang tải mẫu hợp đồng...</p>
  if (list.isError || (id && query.isError)) return <p role="alert" className="text-red-700">{getApiErrorMessage(list.error ?? query.error)}</p>
  const data = list.data.data.data
  if (!id && !data.can_manage) return <p>Chỉ chủ trọ được tạo mẫu hợp đồng riêng.</p>
  return <section className="min-w-0 space-y-4"><Link to="/contract-templates" className="inline-flex min-h-11 items-center text-sm underline">← Danh sách mẫu hợp đồng</Link><h2 className="text-xl font-bold sm:text-2xl">{id ? `Mẫu hợp đồng — v${query.data.data.data.version}` : 'Tạo mẫu hợp đồng riêng'}</h2><TemplateEditor key={id ?? 'new'} initial={id ? query.data.data.data : null} variables={data.variables} canManage={data.can_manage} save={payload => mutation.mutateAsync(payload)} clone={async () => { const response = await createContractTemplateVersion(id); await client.invalidateQueries({ queryKey: contractTemplateKeys.all }); navigate(`/contract-templates/${response.data.data.id}`) }} /></section>
}
