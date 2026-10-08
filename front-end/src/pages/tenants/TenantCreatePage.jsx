import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { createTenant } from '../../api/tenantApi'
import { tenantKeys } from '../../api/tenantKeys'
import TenantForm from '../../components/forms/TenantForm'
export default function TenantCreatePage() { const navigate = useNavigate(); const queryClient = useQueryClient(); const mutation = useMutation({ mutationFn: createTenant }); const submit = async (payload) => { await mutation.mutateAsync(payload); await queryClient.invalidateQueries({ queryKey: tenantKeys.all }); navigate('/tenants', { replace: true, state: { message: 'Tạo người thuê thành công.' } }) }; return <section className="max-w-3xl"><Link to="/tenants" className="text-sm text-slate-600">← Quay lại danh sách</Link><h2 className="mb-6 mt-3 text-2xl font-bold">Thêm người thuê</h2><TenantForm onSubmit={submit} submitLabel="Tạo người thuê" /></section> }
