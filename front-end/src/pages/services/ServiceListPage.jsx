import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { createService, deleteService, getServices, updateService } from '../../api/serviceApi'
import { createServicePriceTier, deleteServicePriceTier, getServicePriceTiers } from '../../api/servicePriceTierApi'
import { serviceKeys } from '../../api/serviceKeys'
import ServiceForm from '../../components/forms/ServiceForm'
import MoneyInput from '../../components/ui/MoneyInput'
import { formatCurrency } from '../../utils/formatters'
import { BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS } from '../../utils/service'
import PageHeader from '../../components/ui/PageHeader'
import EmptyState from '../../components/ui/EmptyState'
import LoadingState from '../../components/ui/LoadingState'
import { Gauge, Plus } from 'lucide-react'

function TierRange({ tier, unit }) {
  if (tier.to_quantity === null || tier.to_quantity === undefined) return <span>Trên {tier.from_quantity} {unit || ''}</span>
  return <span>{tier.from_quantity} - {tier.to_quantity} {unit || ''}</span>
}

function Tiers({ service }) {
  const client = useQueryClient()
  const [values, setValues] = useState({ from_quantity: '', to_quantity: '', unit_price: '', tier_order: '' })
  const [error, setError] = useState('')
  const query = useQuery({ queryKey: serviceKeys.tiers(service.id), queryFn: () => getServicePriceTiers(service.id) })
  const createMutation = useMutation({
    mutationFn: (payload) => createServicePriceTier(service.id, payload),
    onSuccess: () => { setError(''); setValues({ from_quantity: '', to_quantity: '', unit_price: '', tier_order: '' }); client.invalidateQueries({ queryKey: serviceKeys.tiers(service.id) }) },
    onError: (requestError) => setError(getApiErrorMessage(requestError)),
  })
  const deleteMutation = useMutation({ mutationFn: deleteServicePriceTier, onSuccess: () => client.invalidateQueries({ queryKey: serviceKeys.tiers(service.id) }) })
  const fieldErrors = getApiFieldErrors(createMutation.error)
  const add = (event) => {
    event.preventDefault()
    setError('')
    createMutation.mutate({ ...values, from_quantity: Number(values.from_quantity), to_quantity: values.to_quantity === '' ? null : Number(values.to_quantity), unit_price: Number(values.unit_price), tier_order: Number(values.tier_order) })
  }

  return <div className="mt-4 rounded-lg bg-slate-50 p-4"><p className="font-medium">Bậc giá</p>{query.isError && <p className="mt-2 text-sm text-red-600">{getApiErrorMessage(query.error)}</p>}{deleteMutation.isError && <p role="alert" className="mt-2 text-sm text-red-600">{getApiErrorMessage(deleteMutation.error)}</p>}<div className="mt-3 space-y-2">{(query.data?.data?.data || []).map((tier) => <div key={tier.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white px-3 py-2 text-sm"><span><b>Bậc {tier.tier_order}</b> · <TierRange tier={tier} unit={service.unit} /> · {formatCurrency(tier.unit_price)}{service.unit ? ` / ${service.unit}` : ''}</span><button onClick={() => window.confirm('Bạn có chắc muốn xóa bậc giá này?') && deleteMutation.mutate(tier.id)} className="min-h-11 sm:min-h-0 rounded-lg px-3 text-red-700 hover:bg-red-50">Xóa</button></div>)}</div><form onSubmit={add} className="mt-4 grid gap-3 sm:grid-cols-2"><div><label className="block text-sm font-medium">Từ</label><input type="number" min="0" className="mt-1 min-h-11 min-w-0 w-full rounded border p-2 text-sm sm:min-h-0" value={values.from_quantity} onChange={(event) => setValues({ ...values, from_quantity: event.target.value })} />{fieldErrors.from_quantity && <p className="mt-1 text-sm text-red-600">{fieldErrors.from_quantity}</p>}</div><div><label className="block text-sm font-medium">Đến</label><input type="number" min="0" className="mt-1 min-h-11 min-w-0 w-full rounded border p-2 text-sm sm:min-h-0" value={values.to_quantity} onChange={(event) => setValues({ ...values, to_quantity: event.target.value })} />{fieldErrors.to_quantity && <p className="mt-1 text-sm text-red-600">{fieldErrors.to_quantity}</p>}<p className="mt-1 text-xs text-slate-500">Để trống nếu không giới hạn.</p></div><div><label className="block text-sm font-medium">Đơn giá</label><MoneyInput value={values.unit_price} onChange={(value) => setValues({ ...values, unit_price: value })} placeholder="0" className="mt-1 min-h-11 min-w-0 w-full rounded border p-2 text-sm sm:min-h-0" />{fieldErrors.unit_price && <p className="mt-1 text-sm text-red-600">{fieldErrors.unit_price}</p>}</div><div><label className="block text-sm font-medium">Thứ tự bậc</label><input type="number" min="1" className="mt-1 min-h-11 min-w-0 w-full rounded border p-2 text-sm sm:min-h-0" value={values.tier_order} onChange={(event) => setValues({ ...values, tier_order: event.target.value })} />{fieldErrors.tier_order && <p className="mt-1 text-sm text-red-600">{fieldErrors.tier_order}</p>}</div>{error && <p className="text-sm text-red-600 sm:col-span-2">{error}</p>}<button disabled={createMutation.isPending} className="min-h-11 rounded bg-slate-900 p-2 text-sm text-white disabled:opacity-60 sm:col-span-2 sm:min-h-0">{createMutation.isPending ? 'Đang lưu...' : 'Thêm bậc giá'}</button></form></div>
}

export default function ServiceListPage() {
  const { boardingHouseId } = useParams()
  const client = useQueryClient()
  const [editing, setEditing] = useState(null)
  const query = useQuery({ queryKey: serviceKeys.byBoardingHouse(boardingHouseId), queryFn: () => getServices(boardingHouseId) })
  const saveMutation = useMutation({ mutationFn: (payload) => editing?.id ? updateService(editing.id, payload) : createService(boardingHouseId, payload), onSuccess: () => { setEditing(null); client.invalidateQueries({ queryKey: serviceKeys.byBoardingHouse(boardingHouseId) }) } })
  const deleteMutation = useMutation({ mutationFn: deleteService, onSuccess: () => client.invalidateQueries({ queryKey: serviceKeys.byBoardingHouse(boardingHouseId) }) })
  const services = query.data?.data?.data || []

  return <section className="bh-workspace min-w-0">
    <Link to={`/boarding-houses/${boardingHouseId}`} className="text-sm text-slate-600 inline-flex min-h-11 items-center">← Quay lại nhà trọ</Link>
    <PageHeader title="Dịch vụ" eyebrow="DANH MỤC & ĐƠN GIÁ" description="Thiết lập khoản thu rõ ràng cho từng nhu cầu vận hành."><button onClick={() => setEditing({})} className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white"><Plus size={17} aria-hidden="true" />Thêm dịch vụ</button></PageHeader>
    {deleteMutation.isError && <p role="alert" className="mb-3 text-sm text-red-600">{getApiErrorMessage(deleteMutation.error)}</p>}
    {query.isPending ? <LoadingState label="Đang tải dịch vụ…" /> : query.isError ? <p role="alert" className="text-sm text-red-600">{getApiErrorMessage(query.error)}</p> : services.length === 0 ? <EmptyState icon={Gauge} title="Nhà trọ này chưa có dịch vụ nào." description="Thêm dịch vụ và chọn cách tính phù hợp trước khi áp dụng cho phòng." /> : <div className="bh-service-list">{services.map(service => <article key={service.id} className="bh-service-card"><div className="bh-service-card-top"><div className="min-w-0"><span className="bh-label mb-4">{service.is_active ? 'ĐANG ÁP DỤNG' : 'TẠM NGƯNG'}</span><h3>{service.name}</h3><p className="bh-service-card-meta">{SERVICE_TYPE_LABELS[service.type] || service.type} · {BILLING_METHOD_LABELS[service.billing_method] || service.billing_method}</p><p className="bh-service-price">{service.billing_method === 'TIERED' ? 'Theo bậc giá' : formatCurrency(service.base_price)}{service.billing_method !== 'TIERED' && service.unit && <small> / {service.unit}</small>}</p></div><div className="bh-service-actions"><button onClick={() => setEditing(service)}>Sửa</button><button onClick={() => window.confirm('Bạn có chắc muốn xóa dịch vụ này?') && deleteMutation.mutate(service.id)} className="text-red-700">Xóa</button></div></div>{service.billing_method === 'TIERED' && <Tiers service={service} />}</article>)}</div>}
    {editing && <div role="dialog" aria-modal="true" aria-labelledby="service-dialog-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4"><div className="max-h-[90dvh] w-full max-w-lg min-w-0 overscroll-contain overflow-y-auto rounded-xl bg-white p-5 sm:p-7"><div className="mb-5 flex items-start justify-between gap-3"><div><p className="bh-eyebrow">THIẾT LẬP DỊCH VỤ</p><h3 id="service-dialog-title" className="text-xl font-semibold">{editing.id ? 'Sửa dịch vụ' : 'Thêm dịch vụ'}</h3></div><button type="button" aria-label="Đóng" onClick={() => setEditing(null)} className="flex size-11 shrink-0 items-center justify-center rounded-lg border hover:bg-slate-100">✕</button></div>{editing.id && editing.billing_method === 'TIERED' && <p className="mb-3 text-sm text-amber-700">Các bậc giá hiện tại sẽ không còn được sử dụng khi dịch vụ không tính theo bậc thang.</p>}<ServiceForm initialValues={editing} onSubmit={(payload) => saveMutation.mutateAsync(payload)} label="Lưu dịch vụ" /></div></div>}
  </section>
}
