import { Users } from 'lucide-react'
import { formatArea, formatCurrency } from '../../utils/formatters'

const statusStyles = {
  AVAILABLE: 'bg-green-100 text-green-800',
  RENTED: 'bg-blue-100 text-blue-800',
  RESERVED: 'bg-amber-100 text-amber-800',
  MAINTENANCE: 'bg-slate-200 text-slate-700',
}

const statusLabels = {
  AVAILABLE: 'Còn trống',
  RENTED: 'Đã thuê',
  RESERVED: 'Đã đặt',
  MAINTENANCE: 'Bảo trì',
}

export default function RoomCard({ room, actions }) {
  return (
    <article className="min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 break-words">
          <p className="text-lg font-bold text-slate-900">{room.room_code}</p>
          <p className="mt-1 text-sm text-slate-600">{room.room_name || 'Chưa đặt tên phòng'}</p>
        </div>
        <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyles[room.status] || 'bg-slate-100 text-slate-700'}`}>
          {statusLabels[room.status] || room.status}
        </span>
      </div>
      <p className="mt-5 break-words text-lg font-semibold text-slate-900 sm:text-xl">{room.monthly_rent == null ? 'Chưa thiết lập giá thuê' : formatCurrency(room.monthly_rent)}{room.monthly_rent != null && <span className="text-sm font-normal text-slate-500">/tháng</span>}</p>
      <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
        {room.active_occupants_count != null && <span className="w-full">{room.active_occupants_count > 0 ? `Đang có người · ${room.active_occupants_count} người ở` : 'Chưa có người ở'}</span>}
        <span>{formatArea(room.area)}</span>
        <span className="inline-flex items-center gap-1"><Users size={15} /> {room.max_tenants ? `Tối đa ${room.max_tenants}` : 'Chưa đặt'}</span>
      </div>
      {actions && <div className="mt-5 border-t border-slate-100 pt-4">{actions}</div>}
    </article>
  )
}
