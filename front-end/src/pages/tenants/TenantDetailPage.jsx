import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Trash2 } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { deleteTenant, getTenant } from '../../api/tenantApi'
import { tenantKeys } from '../../api/tenantKeys'
import { formatDate, genderLabels } from '../../utils/formatters'

const missing = (value) => value || 'Chưa cập nhật'
function Field({ label, value }) {
  return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-medium text-slate-900">{missing(value)}</dd></div>
}
export default function TenantDetailPage() {
  const { id } = useParams(); const navigate = useNavigate(); const location = useLocation(); const queryClient = useQueryClient(); const [confirming, setConfirming] = useState(false); const [deleteError, setDeleteError] = useState('')
  const query = useQuery({ queryKey: tenantKeys.detail(id), queryFn: () => getTenant(id) })
  const mutation = useMutation({ mutationFn: () => deleteTenant(id), onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: tenantKeys.all }); queryClient.removeQueries({ queryKey: tenantKeys.detail(id) }); navigate('/tenants', { replace: true, state: { message: 'Xóa người thuê thành công.' } }) }, onError: (error) => setDeleteError(getApiErrorMessage(error)) })
  if (query.isPending) return <p>Đang tải thông tin người thuê...</p>
  if (query.isError) return <p className="text-red-600">Không tìm thấy người thuê.</p>
  const tenant = query.data.data.data
  return <section className="max-w-4xl"><div className="mb-6 flex flex-wrap items-start justify-between gap-4"><div><Link to="/tenants" className="text-sm text-slate-600">← Quay lại danh sách</Link><h2 className="mt-3 text-2xl font-bold">{tenant.full_name}</h2></div><div className="flex items-center gap-3"><Link to={`/tenants/${id}/edit`} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium"><Pencil size={16} /> Sửa</Link>{confirming ? <span className="flex items-center gap-2"><button onClick={() => mutation.mutate()} disabled={mutation.isPending} className="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white">{mutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setConfirming(false)} className="text-sm">Hủy</button></span> : <button onClick={() => setConfirming(true)} className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700"><Trash2 size={16} /> Xóa</button>}</div></div>{location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}{deleteError && <p className="mb-4 text-sm text-red-600">{deleteError}</p>}<div className="grid gap-6 md:grid-cols-2"><section className="rounded-xl bg-white p-6 shadow-sm"><h3 className="text-lg font-semibold">Thông tin cá nhân</h3><dl className="mt-5 grid gap-5"><Field label="Họ tên" value={tenant.full_name} /><Field label="SĐT" value={tenant.phone} /><Field label="Email" value={tenant.email} /><Field label="Ngày sinh" value={formatDate(tenant.date_of_birth)} /><Field label="Giới tính" value={tenant.gender ? genderLabels[tenant.gender] : null} /></dl></section><section className="rounded-xl bg-white p-6 shadow-sm"><h3 className="text-lg font-semibold">Giấy tờ</h3><dl className="mt-5 grid gap-5"><Field label="CCCD / Số định danh" value={tenant.identity_number} /><Field label="Ngày cấp" value={formatDate(tenant.identity_issue_date)} /><Field label="Nơi cấp" value={tenant.identity_issue_place} /></dl></section><section className="rounded-xl bg-white p-6 shadow-sm md:col-span-2"><h3 className="text-lg font-semibold">Địa chỉ</h3><dl className="mt-5"><Field label="Địa chỉ thường trú" value={tenant.permanent_address} /></dl></section></div><div className="mt-6 grid gap-3 sm:grid-cols-2"><div className="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">Hợp đồng sẽ được triển khai ở phase tiếp theo.</div><div className="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">Lịch sử ở sẽ được triển khai ở phase tiếp theo.</div></div></section>
}
