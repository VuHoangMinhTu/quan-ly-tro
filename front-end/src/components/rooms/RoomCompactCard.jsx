import { Link } from 'react-router-dom'
import { formatArea, formatCurrency } from '../../utils/formatters'

const statusStyles = {
  AVAILABLE: 'bg-green-100 text-green-800',
  RENTED: 'bg-blue-100 text-blue-800',
  RESERVED: 'bg-amber-100 text-amber-800',
  MAINTENANCE: 'bg-slate-200 text-slate-700',
  INACTIVE: 'bg-slate-200 text-slate-700',
}

const statusLabels = {
  AVAILABLE: 'Còn trống',
  RENTED: 'Đã thuê',
  RESERVED: 'Đã đặt',
  MAINTENANCE: 'Bảo trì',
  INACTIVE: 'Ngừng sử dụng',
}

export default function RoomCompactCard({ room }) {
  const isDorm = room.room_type === 'DORMITORY'
  const hasRent = !isDorm && room.monthly_rent != null

  return (
    <article className="flex h-full min-w-0 flex-col overflow-hidden rounded-xl bg-white p-2.5 shadow-sm">
      <p className="break-all text-sm font-bold leading-snug text-slate-900">{room.room_code}</p>
      <p className="mt-1 truncate text-[11px] text-slate-500" title={room.room_name || 'Chưa đặt tên phòng'}>
        {room.room_name || 'Chưa đặt tên phòng'}
      </p>
      <p className="mt-3 break-words text-[13px] font-semibold leading-tight text-slate-900 tabular-nums">
        {isDorm ? `${room.beds_count ?? 0} giường` : hasRent ? formatCurrency(room.monthly_rent) : 'Chưa có giá'}
      </p>
      {hasRent && <p className="mt-0.5 text-[10px] text-slate-500">/tháng</p>}
      <div className="mt-2">
        <span className={`inline-flex max-w-full break-words rounded-md px-2 py-1 text-[11px] font-medium leading-tight ${statusStyles[room.status] || 'bg-slate-100 text-slate-700'}`}>
          {statusLabels[room.status] || room.status}
        </span>
      </div>
      <p className="mt-2 break-words text-[11px] leading-snug text-slate-500">
        {formatArea(room.area)}
        {room.active_occupants_count != null && ` · ${room.active_occupants_count} người`}
      </p>
      <div className="mt-auto grid grid-cols-2 gap-1 border-t border-slate-100 pt-2 text-xs font-medium">
        <Link to={`/rooms/${room.id}`} aria-label={`Xem phòng ${room.room_code}`} className="inline-flex min-h-11 min-w-0 items-center justify-center rounded-md px-1 text-slate-800 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-700">
          Xem
        </Link>
        <Link to={`/rooms/${room.id}/edit`} aria-label={`Sửa phòng ${room.room_code}`} className="inline-flex min-h-11 min-w-0 items-center justify-center rounded-md px-1 text-slate-800 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-700">
          Sửa
        </Link>
      </div>
    </article>
  )
}
