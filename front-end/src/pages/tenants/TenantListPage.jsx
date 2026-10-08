import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Eye, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { deleteTenant, getTenants } from '../../api/tenantApi'
import { tenantKeys } from '../../api/tenantKeys'
import { formatDate } from '../../utils/formatters'

const emptyTenants = []

export default function TenantListPage() {
  const [search, setSearch] = useState('')
  const [confirmingId, setConfirmingId] = useState(null)
  const [deleteError, setDeleteError] = useState('')
  const location = useLocation(); const queryClient = useQueryClient()
  const query = useQuery({ queryKey: tenantKeys.all, queryFn: getTenants })
  const mutation = useMutation({ mutationFn: deleteTenant, onSuccess: () => { setConfirmingId(null); queryClient.invalidateQueries({ queryKey: tenantKeys.all }) }, onError: (error) => setDeleteError(getApiErrorMessage(error)) })
  const tenants = query.data?.data?.data || emptyTenants
  const filtered = useMemo(() => { const term = search.toLowerCase().trim(); return !term ? tenants : tenants.filter((tenant) => [tenant.full_name, tenant.phone, tenant.identity_number].some((value) => value?.toLowerCase().includes(term))) }, [search, tenants])
  if (query.isPending) return <p>Đang tải danh sách người thuê...</p>
  if (query.isError) return <p className="text-red-600">{getApiErrorMessage(query.error)}</p>
  return <section><div className="mb-6 flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-2xl font-bold">Người thuê</h2><p className="mt-1 text-sm text-slate-600">Quản lý hồ sơ người thuê của bạn.</p></div><Link to="/tenants/new" className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white"><Plus size={17} /> Thêm người thuê</Link></div>{location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}{deleteError && <p className="mb-4 text-sm text-red-600">{deleteError}</p>}<div className="mb-4 max-w-md"><label className="sr-only" htmlFor="tenant-search">Tìm người thuê</label><div className="flex items-center gap-2 rounded-lg border bg-white px-3"><Search size={17} className="text-slate-400" /><input id="tenant-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tìm theo tên, SĐT, CCCD" className="w-full py-2 outline-none" /></div></div>{tenants.length === 0 ? <div className="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center"><p className="font-medium">Bạn chưa có người thuê nào.</p><Link to="/tenants/new" className="mt-4 inline-block underline">Thêm người thuê</Link></div> : <div className="overflow-x-auto rounded-xl bg-white shadow-sm"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-600"><tr>{['Họ tên', 'Số điện thoại', 'Email', 'CCCD / Số định danh', 'Ngày sinh', 'Thao tác'].map((label) => <th key={label} className="px-5 py-3 font-medium">{label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{filtered.map((tenant) => <tr key={tenant.id}><td className="px-5 py-4 font-medium">{tenant.full_name}</td><td className="px-5 py-4">{tenant.phone || '—'}</td><td className="px-5 py-4">{tenant.email || '—'}</td><td className="px-5 py-4">{tenant.identity_number || '—'}</td><td className="px-5 py-4">{formatDate(tenant.date_of_birth)}</td><td className="px-5 py-4"><div className="flex gap-3 whitespace-nowrap"><Link to={`/tenants/${tenant.id}`} className="inline-flex items-center gap-1"><Eye size={15} />Xem</Link><Link to={`/tenants/${tenant.id}/edit`} className="inline-flex items-center gap-1"><Pencil size={15} />Sửa</Link>{confirmingId === tenant.id ? <><button onClick={() => mutation.mutate(tenant.id)} disabled={mutation.isPending} className="text-red-700">{mutation.isPending ? 'Đang xóa...' : 'Xác nhận'}</button><button onClick={() => setConfirmingId(null)}>Hủy</button></> : <button onClick={() => { setDeleteError(''); setConfirmingId(tenant.id) }} className="inline-flex items-center gap-1 text-red-700"><Trash2 size={15} />Xóa</button>}</div></td></tr>)}</tbody></table>{filtered.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Không tìm thấy người thuê phù hợp.</p>}</div>}</section>
}
