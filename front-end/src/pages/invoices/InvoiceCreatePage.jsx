import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { getRoom } from '../../api/roomApi'
import { getBillingV2Status } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import MonthlyInvoicePanel from './MonthlyInvoicePanel'

// V1 creation is retired. Reuse the existing V2 monthly flow rather than
// silently redirecting a "create" action to the read-only invoice list.
// The monthly endpoint is boarding-house scoped, NOT room scoped.
export default function InvoiceCreatePage() {
  const { roomId } = useParams()
  const roomQuery = useQuery({ queryKey: ['room', String(roomId)], queryFn: () => getRoom(roomId) })
  const statusQuery = useQuery({ queryKey: ['billing-v2-status'], queryFn: getBillingV2Status, staleTime: 30_000 })
  const room = roomQuery.data?.data?.data
  const enabled = statusQuery.data?.data?.data?.enabled === true

  return <section className="w-full min-w-0 max-w-3xl space-y-4">
    <Link to={`/rooms/${roomId}`} className="inline-flex min-h-11 items-center text-sm underline">← Quay lại chi tiết phòng</Link>
    <h2 className="text-xl font-bold sm:text-2xl">Tạo hóa đơn tháng · Billing V2</h2>
    {roomQuery.isPending && <p>Đang tải thông tin phòng...</p>}
    {roomQuery.isError && <p role="alert" className="text-red-700">{getApiErrorMessage(roomQuery.error)}</p>}
    {room && <>
      <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" role="note">
        Bạn đi từ phòng <strong>{room.room_code}</strong>. Chỉ tạo hóa đơn cho <strong>phòng này</strong>; không tác động đến phòng khác. Hệ thống chỉ tạo nháp để chủ trọ kiểm tra.
      </p>
      <Link className="inline-flex min-h-11 items-center underline" to={`/rooms/${roomId}/invoices`}>Xem hóa đơn của phòng này</Link>
      {statusQuery.isPending && <p>Đang kiểm tra quyền tạo hóa đơn V2...</p>}
      {statusQuery.isError && <p role="alert" className="text-red-700">Không kiểm tra được trạng thái Billing V2. {getApiErrorMessage(statusQuery.error)}</p>}
      {!statusQuery.isPending && !statusQuery.isError && !enabled && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">Tạo hóa đơn Billing V2 hiện tạm khóa. Không chuyển về luồng V1.</p>}
      {enabled && room.boarding_house_id && <MonthlyInvoicePanel houseId={room.boarding_house_id} roomId={roomId} />}
    </>}
  </section>
}
