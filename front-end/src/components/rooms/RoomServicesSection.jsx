import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getRoomServices } from '../../api/roomServiceApi'
import { roomKeys } from '../../api/roomKeys'
import { getUtilityMeters } from '../../api/utilityMeterApi'
import { utilityKeys } from '../../api/utilityKeys'
import { BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS } from '../../utils/service'
import { formatRoomServicePrice, isActiveFlag, isAppliedRoomService } from '../../utils/roomServices'
import RoomServicesModal from './RoomServicesModal'

export default function RoomServicesSection({ room }) {
  const [isEditing, setIsEditing] = useState(false)
  const servicesQuery = useQuery({ queryKey: roomKeys.services(room.id), queryFn: () => getRoomServices(room.id) })
  const metersQuery = useQuery({ queryKey: utilityKeys.meters(room.id), queryFn: () => getUtilityMeters(room.id) })
  const services = servicesQuery.data?.data?.data || []
  const meters = metersQuery.data?.data?.data || []

  return (
    <section className="mt-6 rounded-xl bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h3 className="text-lg font-semibold">Dịch vụ áp dụng</h3><p className="mt-1 text-sm text-slate-500">Chỉ các dịch vụ được chọn cho phòng mới được tính khi lập hóa đơn.</p></div>
        <button type="button" disabled={servicesQuery.isPending || servicesQuery.isError} onClick={() => setIsEditing(true)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">Chỉnh sửa dịch vụ</button>
      </div>
      {servicesQuery.isPending ? <p className="mt-4 text-sm text-slate-500">Đang tải dịch vụ áp dụng...</p>
        : servicesQuery.isError ? <div className="mt-4 text-sm"><p className="text-red-600">Không thể tải dịch vụ của phòng.</p><button type="button" onClick={() => servicesQuery.refetch()} className="mt-2 font-medium underline">Thử lại</button></div>
          : services.length === 0 ? <p className="mt-4 text-sm text-slate-500">Phòng này chưa áp dụng dịch vụ nào.</p>
            : <div className="mt-4 grid gap-3 sm:grid-cols-2">{services.map((service) => {
              const active = isAppliedRoomService(service)
              const requiresMeter = active && ['PER_UNIT', 'TIERED'].includes(service.billing_method)
              const hasMeter = meters.some((meter) => Number(meter.service_id) === Number(service.id) && isActiveFlag(meter.is_active))
              return <article key={service.id} className="rounded-lg border border-slate-200 p-4">
                <div className="flex items-start justify-between gap-3"><div className="min-w-0"><h4 className="font-semibold">{SERVICE_TYPE_LABELS[service.type] || 'Dịch vụ'}</h4><p className="mt-1 text-sm text-slate-500">{service.name}</p></div><span className={`shrink-0 rounded-full px-2 py-1 text-xs font-medium ${active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{active ? 'Đang áp dụng' : 'Tạm ngưng'}</span></div>
                <p className="mt-3 font-medium">{formatRoomServicePrice(service)}</p><p className="mt-1 text-sm text-slate-600">{BILLING_METHOD_LABELS[service.billing_method]}</p>
                {service.billing_method === 'TIERED' && <Link to={`/boarding-houses/${room.boarding_house_id}/services`} className="mt-2 inline-block text-sm font-medium underline">Xem bảng giá</Link>}
                {requiresMeter && !metersQuery.isPending && !metersQuery.isError && !hasMeter && <p className="mt-3 rounded-md bg-amber-50 p-2 text-xs text-amber-800">Chưa có đồng hồ đang hoạt động. Hãy thêm đồng hồ ở phần Điện nước và ghi chỉ số trước khi lập hóa đơn.</p>}
                {requiresMeter && metersQuery.isError && <p className="mt-3 text-xs text-slate-500">Chưa kiểm tra được đồng hồ. Dịch vụ này cần đồng hồ và chỉ số trước khi lập hóa đơn.</p>}
              </article>
            })}</div>}
      {isEditing && <RoomServicesModal key={room.id} room={room} assignedServices={services} onClose={() => setIsEditing(false)} />}
    </section>
  )
}
