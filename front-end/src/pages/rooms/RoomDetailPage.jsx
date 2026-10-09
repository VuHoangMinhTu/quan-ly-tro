import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Trash2 } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { getContractsByRoom } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractStatusBadge from '../../components/contracts/ContractStatusBadge'
import RoomOccupantsSection from '../../components/rooms/RoomOccupantsSection'
import RoomUtilitiesSection from '../../components/rooms/RoomUtilitiesSection'
import RoomServicesSection from '../../components/rooms/RoomServicesSection'
import AmenityIcon from '../../components/amenities/AmenityIcon'
import RoomAmenitiesModal from '../../components/amenities/RoomAmenitiesModal'
import { getInvoicesByRoom } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoiceStatusBadge from '../../components/invoices/InvoiceStatusBadge'
import { roomKeys } from '../../api/roomKeys'
import { deleteRoom, getRoom } from '../../api/roomApi'
import { formatArea, formatCurrency, formatDate } from '../../utils/formatters'

const placeholders = []

export default function RoomDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [isConfirmingDelete, setIsConfirmingDelete] = useState(false)
  const [deleteError, setDeleteError] = useState('')
  const [isContractPickerOpen, setIsContractPickerOpen] = useState(false)
  const [isAmenityEditorOpen, setIsAmenityEditorOpen] = useState(false)
  const roomQuery = useQuery({ queryKey: roomKeys.detail(id), queryFn: () => getRoom(id) })
  const contractsQuery = useQuery({ queryKey: contractKeys.byRoom(id), queryFn: () => getContractsByRoom(id) })
  const invoicesQuery = useQuery({ queryKey: invoiceKeys.byRoom(id), queryFn: () => getInvoicesByRoom(id) })
  const deleteMutation = useMutation({
    mutationFn: () => deleteRoom(id),
    onSuccess: async () => {
      const room = roomQuery.data.data.data
      await queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(room.boarding_house_id) })
      queryClient.removeQueries({ queryKey: roomKeys.detail(id) })
      navigate(`/boarding-houses/${room.boarding_house_id}/rooms`, { replace: true, state: { message: 'Xóa phòng thành công.' } })
    },
    onError: (error) => setDeleteError(getApiErrorMessage(error)),
  })

  if (roomQuery.isPending) return <p>Đang tải thông tin phòng...</p>
  if (roomQuery.isError) return <p className="text-red-600">Không tìm thấy phòng.</p>

  const room = roomQuery.data.data.data
  const contracts = contractsQuery.data?.data?.data || []
  const activeContract = contracts.find((contract) => contract.status === 'ACTIVE')
  return (
    <section className="max-w-3xl">
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link to={`/boarding-houses/${room.boarding_house_id}/rooms`} className="text-sm text-slate-600 hover:text-slate-950">← Quay lại danh sách phòng</Link>
          <h2 className="mt-3 text-2xl font-bold">{room.room_code} {room.room_name ? `– ${room.room_name}` : ''}</h2>
        </div>
        <div className="flex items-center gap-3">
          <Link to={`/rooms/${id}/edit`} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"><Pencil size={16} /> Sửa</Link>
          {isConfirmingDelete ? (
            <span className="flex items-center gap-2"><button onClick={() => deleteMutation.mutate()} disabled={deleteMutation.isPending} className="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{deleteMutation.isPending ? 'Đang xóa...' : 'Xác nhận xóa'}</button><button onClick={() => setIsConfirmingDelete(false)} className="text-sm text-slate-600">Hủy</button></span>
          ) : (
            <button onClick={() => setIsConfirmingDelete(true)} className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"><Trash2 size={16} /> Xóa</button>
          )}
        </div>
      </div>

      {location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}
      {deleteError && <p className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{deleteError}</p>}

      <div className="rounded-xl bg-white p-6 shadow-sm">
        <h3 className="text-lg font-semibold">Thông tin phòng</h3>
        <dl className="mt-5 grid gap-5 sm:grid-cols-2">
          <div><dt className="text-sm text-slate-500">Giá thuê</dt><dd className="mt-1 font-medium">{formatCurrency(room.monthly_rent)}/tháng</dd></div>
          <div><dt className="text-sm text-slate-500">Diện tích</dt><dd className="mt-1 font-medium">{formatArea(room.area)}</dd></div>
          <div><dt className="text-sm text-slate-500">Số người tối đa</dt><dd className="mt-1 font-medium">{room.max_tenants || 'Chưa đặt'}</dd></div>
          <div><dt className="text-sm text-slate-500">Trạng thái</dt><dd className="mt-1 font-medium">{room.status}</dd></div>
          <div className="sm:col-span-2"><dt className="text-sm text-slate-500">Mô tả</dt><dd className="mt-1 whitespace-pre-wrap">{room.description || 'Chưa có mô tả.'}</dd></div>
        </dl>
      </div>

      <div className="mt-6 rounded-xl bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-semibold">Tiện nghi</h3><button type="button" onClick={() => setIsAmenityEditorOpen(true)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{room.amenities?.length ? 'Chỉnh sửa tiện nghi' : 'Thêm tiện nghi'}</button></div>
        {room.amenities?.length ? <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{room.amenities.map((amenity) => <div key={amenity.id} className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700"><AmenityIcon name={amenity.name} icon={amenity.icon} /><span>{amenity.name}</span></div>)}</div> : <p className="mt-3 text-sm text-slate-500">Chưa có tiện nghi nào.</p>}
      </div>

      <RoomOccupantsSection roomId={id} />

      <RoomServicesSection key={room.id} room={room} />

      <RoomUtilitiesSection room={room} />

      <div className="mt-6 rounded-xl bg-white p-6 shadow-sm"><div className="flex justify-between"><h3 className="text-lg font-semibold">Hóa đơn</h3><div className="flex gap-3"><Link to={`/rooms/${id}/invoices/new`} className="text-sm underline">Tạo hóa đơn</Link><Link to={`/rooms/${id}/invoices`} className="text-sm underline">Xem tất cả hóa đơn</Link></div></div>{(invoicesQuery.data?.data?.data||[]).slice(0,3).map(invoice=><div key={invoice.id} className="mt-3 flex justify-between rounded border p-3 text-sm"><Link to={`/invoices/${invoice.id}`}>{invoice.billing_period?.slice(5,7)}/{invoice.billing_period?.slice(0,4)} · {formatCurrency(invoice.total_amount)}</Link><InvoiceStatusBadge status={invoice.status}/></div>)}{!invoicesQuery.isPending&&(invoicesQuery.data?.data?.data||[]).length===0&&<p className="mt-3 text-sm text-slate-500">Phòng này chưa có hóa đơn.</p>}</div>

      <div className="mt-6 rounded-xl bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-semibold">Hợp đồng</h3><button type="button" onClick={() => setIsContractPickerOpen(true)} className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white">Thêm hợp đồng</button></div>
        {contractsQuery.isPending ? <p className="mt-3 text-sm text-slate-500">Đang tải hợp đồng...</p> : contractsQuery.isError ? <p className="mt-3 text-sm text-red-600">Không thể tải hợp đồng.</p> : activeContract ? <div className="mt-4 rounded-lg bg-green-50 p-4"><div className="flex items-center justify-between gap-3"><div><p className="font-medium">{activeContract.contract_code}</p><p className="mt-1 text-sm text-slate-600">Người thuê: {activeContract.tenant?.full_name || 'Chưa cập nhật'}</p></div><ContractStatusBadge status={activeContract.status} /></div><p className="mt-3 text-sm">{formatDate(activeContract.start_date)} - {formatDate(activeContract.end_date)}</p><p className="mt-1 text-sm font-medium">{formatCurrency(activeContract.monthly_rent)}/tháng</p><div className="mt-3 flex gap-3"><Link to={`/contracts/${activeContract.id}`} className="text-sm underline">Xem</Link><Link to={`/contracts/${activeContract.id}/edit`} className="text-sm underline">Sửa</Link></div></div> : <p className="mt-3 text-sm text-slate-500">{contracts.length > 0 ? 'Hiện không có hợp đồng đang hiệu lực.' : 'Phòng này chưa có hợp đồng.'}</p>}
        {contracts.length > 0 && <Link to={`/rooms/${id}/contracts`} className="mt-4 inline-block text-sm font-medium underline">Xem lịch sử hợp đồng</Link>}
      </div>

      <div className="mt-6 grid gap-3 sm:grid-cols-2">
        {placeholders.map((title) => <div key={title} className="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">{title} sẽ được triển khai ở phase tiếp theo.</div>)}
      </div>

      {isContractPickerOpen && <div role="dialog" aria-modal="true" aria-labelledby="contract-picker-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl"><div className="flex items-start justify-between gap-4"><div><h3 id="contract-picker-title" className="text-lg font-semibold">Chọn hợp đồng</h3><p className="mt-1 text-sm text-slate-600">Chỉ hiển thị hợp đồng của phòng {room.room_code}.</p></div><button type="button" aria-label="Đóng" onClick={() => setIsContractPickerOpen(false)} className="text-lg text-slate-500 hover:text-slate-950">✕</button></div>{contractsQuery.isPending ? <p className="mt-5 text-sm text-slate-500">Đang tải hợp đồng...</p> : contractsQuery.isError ? <p className="mt-5 text-sm text-red-600">Không thể tải hợp đồng.</p> : contracts.length === 0 ? <div className="mt-5 rounded-lg border border-dashed p-6 text-center"><p className="text-sm text-slate-600">Chưa có hợp đồng nào cho phòng này.</p><Link to={`/rooms/${id}/contracts/new`} className="mt-4 inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Tạo hợp đồng mới</Link></div> : <><div className="mt-5 space-y-3">{contracts.map((contract) => <div key={contract.id} className="rounded-lg border border-slate-200 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-medium">{contract.contract_code}</p><p className="mt-1 text-sm text-slate-600">{contract.tenant?.full_name || 'Chưa cập nhật người thuê'}</p><p className="mt-2 text-sm text-slate-600">{formatDate(contract.start_date)} - {formatDate(contract.end_date)}</p></div><div className="flex flex-col items-end gap-3"><ContractStatusBadge status={contract.status} /><Link to={`/contracts/${contract.id}`} className="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50">Chọn</Link></div></div></div>)}</div><Link to={`/rooms/${id}/contracts/new`} className="mt-5 inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">+ Tạo hợp đồng mới</Link></>}</div></div>}
      {isAmenityEditorOpen && <RoomAmenitiesModal key={room.id} room={room} onClose={() => setIsAmenityEditorOpen(false)} />}
    </section>
  )
}
