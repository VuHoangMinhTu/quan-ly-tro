import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { changeContractRoom } from '../../api/contractApi'
import { getResidenceHouses, getResidenceRooms, residenceKeys } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

export default function ContractDraftRoomSelector({ contract }) {
  const client = useQueryClient()
  const [open, setOpen] = useState(false)
  const [houseId, setHouseId] = useState(String(contract.room.boarding_house_id))
  const [roomId, setRoomId] = useState(String(contract.room_id))
  const houses = useQuery({ queryKey: residenceKeys.houses, queryFn: getResidenceHouses, enabled: open })
  const rooms = useQuery({ queryKey: residenceKeys.rooms(houseId), queryFn: () => getResidenceRooms(houseId), enabled: open && Boolean(houseId) })
  const save = useMutation({ mutationFn: () => changeContractRoom(contract.id, Number(roomId)), onSuccess: async () => {
    setOpen(false)
    await Promise.all([client.invalidateQueries({ queryKey: ['contract'] }), client.invalidateQueries({ queryKey: ['contracts'] }), client.invalidateQueries({ queryKey: ['contract-context'] })])
  } })
  return <div className="space-y-3 rounded-lg border bg-white p-3">{!open ? <button type="button" onClick={() => setOpen(true)} className="min-h-11 text-sm underline">Đổi phòng cho hợp đồng nháp</button> : <form onSubmit={event => { event.preventDefault(); save.mutate() }} className="space-y-3"><p className="text-sm text-slate-500">Lưu các chỉnh sửa đang nhập trước khi đổi phòng. Chỉ đổi trong cùng workspace; giá thuê/lịch đã nhập được giữ, dịch vụ và chỉ số đầu lấy lại từ phòng mới.</p><label className="block text-sm">Nhà trọ<select value={houseId} onChange={event => { setHouseId(event.target.value); setRoomId('') }} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2"><option value="">Chọn nhà trọ</option>{(houses.data?.data?.data ?? []).map(house => <option key={house.id} value={String(house.id)}>{house.name}</option>)}</select></label><label className="block text-sm">Phòng<select value={roomId} onChange={event => setRoomId(event.target.value)} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2"><option value="">Chọn phòng</option>{(rooms.data?.data?.data ?? []).map(room => <option key={room.id} value={String(room.id)}>{room.room_code}</option>)}</select></label>{(save.isError || houses.isError || rooms.isError) && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(save.error || houses.error || rooms.error)}</p>}<div className="flex flex-wrap gap-3"><button disabled={!roomId || save.isPending || rooms.isPending || rooms.isError} className="min-h-11 rounded-lg bg-slate-900 px-3 text-sm text-white disabled:opacity-50">Xác nhận đổi phòng</button><button type="button" disabled={save.isPending} onClick={() => setOpen(false)} className="min-h-11 px-3 text-sm">Hủy</button></div></form>}</div>
}
