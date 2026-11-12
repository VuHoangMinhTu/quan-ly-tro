import { Link } from 'react-router-dom'
import { Ruler, Users } from 'lucide-react'
import { formatArea, formatCurrency } from '../../utils/formatters'

const roomStatuses = {
  AVAILABLE: { label: 'Còn trống', tone: 'border-emerald-200 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
  RENTED: { label: 'Đã thuê', tone: 'border-blue-200 bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
  RESERVED: { label: 'Đã đặt', tone: 'border-amber-200 bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
  MAINTENANCE: { label: 'Bảo trì', tone: 'border-slate-200 bg-slate-100 text-slate-700', dot: 'bg-slate-500' },
  INACTIVE: { label: 'Ngừng dùng', tone: 'border-slate-200 bg-slate-100 text-slate-700', dot: 'bg-slate-500' },
}

const fallbackStatus = { label: 'Chưa xác định', tone: 'border-slate-200 bg-slate-100 text-slate-700', dot: 'bg-slate-400' }

export default function RoomCompactCard({ room }) {
  const isDorm = room.room_type === 'DORMITORY'
  const status = roomStatuses[room.status] || { ...fallbackStatus, label: room.status || fallbackStatus.label }
  const price = isDorm
    ? `${room.beds_count ?? 0} giường`
    : room.monthly_rent == null
      ? 'Chưa có giá'
      : formatCurrency(room.monthly_rent)

  return (
    <article className="bh-room-compact flex h-full min-w-0 flex-col overflow-hidden rounded-[18px] border border-slate-200 bg-white shadow-[0_3px_14px_rgba(22,40,60,0.05)] transition-[border-color,box-shadow]">
      <div className="flex flex-1 flex-col p-3">
        <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
          <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-teal-600" />
          {isDorm ? 'Ký túc xá' : 'Phòng trọ'}
        </p>

        <h3 className="mt-1 break-words text-[17px] font-bold leading-tight tracking-tight text-slate-900">
          <Link
            to={`/rooms/${room.id}`}
            aria-label={`Xem chi tiết phòng ${room.room_code}`}
            className="bh-room-title-link inline-block max-w-full rounded-sm transition-colors active:text-teal-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"
          >
            {room.room_code}
          </Link>
        </h3>
        <p className="mt-1 line-clamp-1 text-[11px] leading-4 text-slate-500" title={room.room_name || 'Chưa đặt tên phòng'}>
          {room.room_name || 'Chưa đặt tên phòng'}
        </p>

        <div className="mt-3 min-w-0 rounded-xl border border-teal-100 bg-[#eff8f6] px-2.5 py-2">
          <p className="text-[10px] font-medium text-teal-800/80">{isDorm ? 'Sức chứa' : 'Giá thuê / tháng'}</p>
          <p className="mt-1 min-w-0 break-words text-[clamp(0.75rem,3.3vw,0.9rem)] font-bold leading-tight tracking-tight text-slate-900 tabular-nums">
            {price}
          </p>
        </div>

        <div className="mt-3">
          <span className={`inline-flex max-w-full items-center gap-1.5 rounded-full border px-2 py-1 text-[10px] font-semibold leading-snug ${status.tone}`}>
            <span aria-hidden="true" className={`h-1.5 w-1.5 shrink-0 rounded-full ${status.dot}`} />
            <span className="break-words">{status.label}</span>
          </span>
        </div>

        <div className="mt-2 space-y-1.5 text-[11px] leading-4 text-slate-600">
          <p className="flex min-w-0 items-center gap-1.5">
            <Ruler size={13} aria-hidden="true" className="shrink-0 text-slate-400" />
            <span className="min-w-0 break-words">{formatArea(room.area)}</span>
          </p>
          {room.active_occupants_count != null && (
            <p className="flex min-w-0 items-center gap-1.5">
              <Users size={13} aria-hidden="true" className="shrink-0 text-slate-400" />
              <span className="min-w-0 break-words">{room.active_occupants_count} người ở</span>
            </p>
          )}
        </div>
      </div>

      <div className="mt-auto grid grid-cols-2 divide-x divide-slate-200 border-t border-slate-200 bg-slate-50/80">
        <Link
          to={`/rooms/${room.id}`}
          aria-label={`Xem phòng ${room.room_code}`}
          className="bh-room-action-view inline-flex min-h-11 min-w-0 touch-manipulation items-center justify-center rounded-bl-[18px] px-1 text-xs font-semibold text-slate-700 transition-[background-color,transform] active:scale-[0.98] active:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-teal-700"
        >
          Xem
        </Link>
        <Link
          to={`/rooms/${room.id}/edit`}
          aria-label={`Sửa phòng ${room.room_code}`}
          className="bh-room-action-edit inline-flex min-h-11 min-w-0 touch-manipulation items-center justify-center rounded-br-[18px] px-1 text-xs font-semibold text-teal-700 transition-[background-color,transform] active:scale-[0.98] active:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-teal-700"
        >
          Sửa
        </Link>
      </div>
    </article>
  )
}
