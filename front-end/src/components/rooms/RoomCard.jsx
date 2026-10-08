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
    <article className="rounded-xl bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-lg font-bold text-slate-900">{room.room_code}</p>
          <p className="mt-1 text-sm text-slate-600">{room.room_name || 'Chưa đặt tên phòng'}</p>
        </div>
        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusStyles[room.status] || 'bg-slate-100 text-slate-700'}`}>
          {statusLabels[room.status] || room.status}
        </span>
      </div>
      <p className="mt-5 text-xl font-semibold text-slate-900">{formatCurrency(room.monthly_rent)}<span className="text-sm font-normal text-slate-500">/tháng</span></p>
      <div className="mt-4 flex items-center gap-4 text-sm text-slate-600">
        <span>{formatArea(room.area)}</span>
        <span className="inline-flex items-center gap-1"><Users size={15} /> {room.max_tenants ? `Tối đa ${room.max_tenants}` : 'Chưa đặt'}</span>
      </div>
      {actions && <div className="mt-5 border-t border-slate-100 pt-4">{actions}</div>}
    </article>
  )
}
