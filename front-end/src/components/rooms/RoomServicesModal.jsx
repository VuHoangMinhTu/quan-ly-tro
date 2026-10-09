import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { updateRoomServices } from '../../api/roomServiceApi'
import { roomKeys } from '../../api/roomKeys'
import { getServices } from '../../api/serviceApi'
import { serviceKeys } from '../../api/serviceKeys'
import { utilityKeys } from '../../api/utilityKeys'
import { BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS } from '../../utils/service'
import { formatRoomServicePrice, getSelectedRoomServiceIds, isActiveFlag, toggleRoomService } from '../../utils/roomServices'

export default function RoomServicesModal({ room, assignedServices, onClose }) {
  const queryClient = useQueryClient()
  // The parent opens this editor only after room assignments have loaded.
  // Catalog fetches/refetches must not reset choices already made by the user.
  const [selectedIds, setSelectedIds] = useState(() => getSelectedRoomServiceIds(assignedServices))
  const [saveError, setSaveError] = useState('')
  const catalogQuery = useQuery({
    queryKey: serviceKeys.byBoardingHouse(room.boarding_house_id),
    queryFn: () => getServices(room.boarding_house_id),
  })
  const catalog = (catalogQuery.data?.data?.data || []).filter((service) => isActiveFlag(service.is_active)
    && Number(service.boarding_house_id) === Number(room.boarding_house_id))
  const suspendedServices = assignedServices.filter((service) => !isActiveFlag(service.is_active))
  const saveMutation = useMutation({
    mutationFn: () => updateRoomServices(room.id, { service_ids: selectedIds.map(Number) }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: roomKeys.services(room.id) }),
        queryClient.invalidateQueries({ queryKey: roomKeys.detail(room.id) }),
        queryClient.invalidateQueries({ queryKey: utilityKeys.meters(room.id) }),
      ])
      onClose()
    },
    onError: (error) => {
      const fieldError = Object.values(error.response?.data?.errors || {}).flat()[0]
      setSaveError(fieldError || getApiErrorMessage(error))
    },
  })

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape' && !saveMutation.isPending) onClose()
    }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [onClose, saveMutation.isPending])

  return (
    <div role="dialog" aria-modal="true" aria-labelledby="room-services-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h3 id="room-services-title" className="text-lg font-semibold">Dịch vụ áp dụng</h3>
            <p className="mt-1 text-sm text-slate-600">Chọn dịch vụ cho phòng {room.room_code}. Không chọn dịch vụ nào vẫn hợp lệ.</p>
          </div>
          <button type="button" aria-label="Đóng" disabled={saveMutation.isPending} onClick={onClose} className="text-lg text-slate-500 hover:text-slate-950 disabled:opacity-50">✕</button>
        </div>
        <p className="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">Mỗi phòng chỉ áp dụng một dịch vụ Điện và một dịch vụ Nước. Chọn dịch vụ mới sẽ thay dịch vụ cùng loại đang chọn. Các hóa đơn đã lập không thay đổi.</p>
        {suspendedServices.length > 0 && <p className="mt-3 text-sm text-slate-600">Dịch vụ đã tạm ngưng không thể chọn và sẽ được bỏ áp dụng khi lưu: {suspendedServices.map((service) => service.name).join(', ')}.</p>}
        {catalogQuery.isPending ? <p className="mt-5 text-sm text-slate-500">Đang tải danh mục dịch vụ...</p>
          : catalogQuery.isError ? <div className="mt-5 text-sm"><p className="text-red-600">Không thể tải danh mục dịch vụ. Các lựa chọn chưa được thay đổi.</p><button type="button" onClick={() => catalogQuery.refetch()} className="mt-2 font-medium underline">Thử lại</button></div>
            : catalog.length === 0 ? <div className="mt-5 rounded-lg border border-dashed p-5 text-center"><p className="text-sm text-slate-600">Nhà trọ chưa có dịch vụ đang áp dụng.</p><Link to={`/boarding-houses/${room.boarding_house_id}/services`} className="mt-3 inline-block text-sm font-medium underline">Quản lý danh mục dịch vụ</Link></div>
              : <div className="mt-5 grid gap-3 sm:grid-cols-2">{catalog.map((service) => {
                const checked = selectedIds.includes(Number(service.id))
                return <label key={service.id} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 text-sm transition ${checked ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'}`}>
                  <input type="checkbox" checked={checked} disabled={saveMutation.isPending} onChange={() => { setSaveError(''); setSelectedIds((ids) => toggleRoomService(ids, service, catalog)) }} className="mt-1 accent-slate-900" />
                  <span className="min-w-0"><span className="block font-semibold text-slate-900">{service.name}</span><span className="mt-1 block text-slate-500">{SERVICE_TYPE_LABELS[service.type]} · {BILLING_METHOD_LABELS[service.billing_method]}</span><span className="mt-2 block font-medium">{formatRoomServicePrice(service)}</span>{['PER_UNIT', 'TIERED'].includes(service.billing_method) && <span className="mt-1 block text-xs text-slate-500">Cần đồng hồ và chỉ số để lập hóa đơn.</span>}</span>
                </label>
              })}</div>}
        {saveError && <p role="alert" className="mt-4 text-sm text-red-600">{saveError}</p>}
        <div className="mt-6 flex justify-end gap-3">
          <button type="button" disabled={saveMutation.isPending} onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium disabled:opacity-50">Hủy</button>
          <button type="button" disabled={catalogQuery.isPending || catalogQuery.isError || saveMutation.isPending} onClick={() => { setSaveError(''); saveMutation.mutate() }} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{saveMutation.isPending ? 'Đang lưu...' : 'Lưu dịch vụ'}</button>
        </div>
      </div>
    </div>
  )
}
