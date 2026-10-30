import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { getBoardingHouse, getBoardingHouses } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { roomKeys } from '../../api/roomKeys'
import { getRoomsByBoardingHouse } from '../../api/roomApi'
import RoomCard from '../../components/rooms/RoomCard'
import RoomOrderButton from '../../components/rooms/RoomOrderButton'

export default function RoomListPage() {
  const { boardingHouseId } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const housesQuery = useQuery({ queryKey: boardingHouseKeys.all, queryFn: getBoardingHouses })
  const boardingHouseQuery = useQuery({ queryKey: boardingHouseKeys.detail(boardingHouseId), queryFn: () => getBoardingHouse(boardingHouseId) })
  const roomsQuery = useQuery({ queryKey: roomKeys.byBoardingHouse(boardingHouseId), queryFn: () => getRoomsByBoardingHouse(boardingHouseId) })

  if (boardingHouseQuery.isPending || roomsQuery.isPending) return <p>Đang tải danh sách phòng...</p>
  if (boardingHouseQuery.isError) return <p className="text-red-600">{getApiErrorMessage(boardingHouseQuery.error)}</p>
  if (roomsQuery.isError) return <p className="text-red-600">{getApiErrorMessage(roomsQuery.error)}</p>

  const boardingHouse = boardingHouseQuery.data.data.data
  const rooms = roomsQuery.data.data.data || []
// phân loại room theo tầng
function groupRoomsByFloor(rooms) {
  const groups = new Map()

  for (const room of rooms) {
    const floorId = room.floor_id ?? room.floor?.id ?? null
    const key = floorId === null ? 'unassigned' : String(floorId)

    if (!groups.has(key)) {
      groups.set(key, {
        floorId,
        floor: room.floor ?? null,
        rooms: [],
      })
    }

    const group = groups.get(key)

    // Ưu tiên dữ liệu tầng có đầy đủ thông tin.
    if (!group.floor && room.floor) {
      group.floor = room.floor
    }

    group.rooms.push(room)
  }

  return [...groups.values()].sort((a, b) => {
    // Phòng không có tầng nằm cuối.
    if (a.floorId === null) return 1
    if (b.floorId === null) return -1

    // Sắp xếp theo thứ tự tầng cấu hình.
    const orderA = a.floor?.sort_order ?? Number.MAX_SAFE_INTEGER
    const orderB = b.floor?.sort_order ?? Number.MAX_SAFE_INTEGER

    if (orderA !== orderB) {
      return orderA - orderB
    }

    // Nếu sort_order bằng nhau, sắp theo tên tầng.
    const nameA = a.floor?.name ?? ''
    const nameB = b.floor?.name ?? ''

    const nameComparison = nameA.localeCompare(nameB, 'vi', {
      numeric: true,
      sensitivity: 'base',
    })

    if (nameComparison !== 0) return nameComparison

    return a.floorId - b.floorId
  })
}
const floorGroups = groupRoomsByFloor(rooms)

  return (
    <section className="min-w-0">
      <Link to={`/boarding-houses/${boardingHouseId}`} className="max-w-full break-words text-sm text-slate-600 hover:text-slate-950 inline-flex min-h-11 items-center lg:min-h-0">← {boardingHouse.name}</Link>
      <div className="mb-6 mt-3 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="break-words text-xl font-bold sm:text-2xl">Phòng</h2>
          <label className="mt-2 block text-sm text-slate-600" htmlFor="room-list-house">Nhà trọ</label>
          <select id="room-list-house" aria-label="Chọn nhà trọ" value={String(boardingHouseId)} disabled={housesQuery.isPending || housesQuery.isError} onChange={(event) => navigate(`/boarding-houses/${event.target.value}/rooms`)} className="mt-1 min-h-11 max-w-full rounded-lg border bg-white px-3 text-sm text-slate-900">
            <option value={String(boardingHouseId)}>{boardingHouse.name}</option>
            {(housesQuery.data?.data?.data ?? []).filter((house) => String(house.id) !== String(boardingHouseId)).map((house) => <option key={house.id} value={house.id}>{house.name}</option>)}
          </select>
          {housesQuery.isError && <p className="mt-1 text-sm text-amber-700">Chưa tải được danh sách nhà trọ để chuyển.</p>}
        </div>
        <div className="flex flex-wrap gap-3"><RoomOrderButton rooms={rooms} boardingHouseId={boardingHouseId} /><Link to={`/boarding-houses/${boardingHouseId}/rooms/new`} className="inline-flex min-h-11 sm:min-h-0 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
          <Plus size={17} /> Thêm phòng
        </Link></div>
      </div>
      {location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}
      {rooms.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
          <p className="font-medium">Nhà trọ này chưa có phòng.</p>
          <Link className="mt-4 inline-flex min-h-11 sm:min-h-0 items-center text-sm font-medium underline" to={`/boarding-houses/${boardingHouseId}/rooms/new`}>Thêm phòng</Link>
        </div>
      ) : (
 
<div className="space-y-8">
  {floorGroups.map((group) => (
    <section
      key={group.floorId ?? 'unassigned'}
      className="min-w-0"
    >
      {/* Tiêu đề tầng */}
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-xl">
            🏢
          </div>

          <div>
            <h3 className="text-lg font-bold text-slate-900">
              {group.floor?.name ??
                (group.floorId === null
                  ? 'Phòng chưa phân tầng'
                  : `Tầng (ID ${group.floorId})`)}
            </h3>

            <p className="text-sm text-slate-500">
              {group.rooms.length} phòng
            </p>
          </div>
        </div>
      </div>

      {/* Danh sách phòng thuộc tầng */}
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {group.rooms.map((room) => (
          <RoomCard
            key={room.id}
            room={room}
            actions={
              <div className="flex flex-wrap gap-3 text-sm font-medium">
                <Link
                  to={`/rooms/${room.id}`}
                  className="inline-flex min-h-11 items-center rounded-lg px-2 text-slate-700 hover:text-slate-950 sm:min-h-0"
                >
                  Xem
                </Link>

                <Link
                  to={`/rooms/${room.id}/edit`}
                  className="inline-flex min-h-11 items-center rounded-lg px-2 text-slate-700 hover:text-slate-950 sm:min-h-0"
                >
                  Sửa
                </Link>
              </div>
            }
          />
        ))}
      </div>
    </section>
  ))}
</div>

      )}
    </section>
  )
}
