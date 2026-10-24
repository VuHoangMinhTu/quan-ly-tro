import { useEffect, useRef, useState } from 'react'
import { useIsMutating, useMutation, useQueryClient } from '@tanstack/react-query'
import { reorderRooms } from '../../api/roomApi'
import { roomKeys } from '../../api/roomKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { groupRoomsByFloor, moveRoom } from '../../utils/roomOrdering'

export default function RoomOrderButton({ rooms, boardingHouseId }) {
  const [open, setOpen] = useState(false)
  return <>
    <button type="button" disabled={!rooms.length} onClick={() => setOpen(true)} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium disabled:opacity-50">Sắp xếp phòng</button>
    {open && <RoomOrderDialog rooms={rooms} boardingHouseId={boardingHouseId} onClose={() => setOpen(false)} />}
  </>
}

function RoomOrderDialog({ rooms, boardingHouseId, onClose }) {
  const container = useRef(null)
  const saving = useIsMutating({ mutationKey: ['room-order', String(boardingHouseId)] }) > 0
  useEffect(() => {
    const previousFocus = document.activeElement
    container.current?.focus()
    return () => previousFocus?.focus()
  }, [])
  const groups = groupRoomsByFloor(rooms)
  const [selected, setSelected] = useState(groups[0]?.key ?? 'none')
  const group = groups.find(item => item.key === selected)
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 sm:p-6">
    <section ref={container} tabIndex={-1} onKeyDown={event => {
      if (event.key === 'Escape' && !saving) onClose()
      if (event.key === 'Tab') {
        const controls = [...container.current.querySelectorAll('button:not(:disabled), select:not(:disabled)')]
        const first = controls[0]
        const last = controls.at(-1)
        if (event.shiftKey && (document.activeElement === first || document.activeElement === container.current)) { event.preventDefault(); last?.focus() }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
      }
    }} role="dialog" aria-modal="true" aria-labelledby="room-order-title" className="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-4 shadow-xl sm:p-6">
      <h3 id="room-order-title" className="text-lg font-bold">Sắp xếp phòng</h3>
      <p className="my-3 text-sm text-slate-600">Chọn tầng, dùng nút lên/xuống rồi lưu thứ tự.</p>
      <label className="block text-sm font-medium">Tầng<select disabled={saving} value={selected} onChange={event => setSelected(event.target.value)} className="my-2 min-h-11 w-full rounded-lg border px-3">{groups.map(item => <option key={item.key} value={item.key}>{item.name}</option>)}</select></label>
      {group ? <RoomOrderGroup key={group.key} group={group} boardingHouseId={boardingHouseId} onClose={onClose} /> : <button type="button" onClick={onClose} className="min-h-11 rounded-lg border px-4">Đóng</button>}
    </section>
  </div>
}

function RoomOrderGroup({ group, boardingHouseId, onClose }) {
  const [rows, setRows] = useState(group.rooms)
  const queryClient = useQueryClient()
  const save = useMutation({
    mutationKey: ['room-order', String(boardingHouseId)],
    mutationFn: () => reorderRooms(boardingHouseId, group.floorId, rows.map(room => Number(room.id))),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(boardingHouseId) }),
        queryClient.invalidateQueries({ queryKey: ['residence-rooms', String(boardingHouseId)] }),
      ])
      onClose()
    },
  })
  return <>
    <ol className="space-y-2">{rows.map((room, index) => <li key={room.id} className="flex items-center justify-between gap-3 rounded-lg border p-2 pl-3">
      <span className="min-w-0 break-words text-sm"><span className="mr-2 text-slate-500">{index + 1}.</span>{room.room_code}</span>
      <span className="flex shrink-0 gap-2">
        <button type="button" aria-label={`Đưa phòng ${room.room_code} lên`} disabled={index === 0 || save.isPending} onClick={() => setRows(previous => moveRoom(previous, index, -1))} className="min-h-11 min-w-11 rounded-lg border disabled:opacity-30">↑</button>
        <button type="button" aria-label={`Đưa phòng ${room.room_code} xuống`} disabled={index === rows.length - 1 || save.isPending} onClick={() => setRows(previous => moveRoom(previous, index, 1))} className="min-h-11 min-w-11 rounded-lg border disabled:opacity-30">↓</button>
      </span>
    </li>)}</ol>
    {save.isError && <p role="alert" className="mt-3 text-sm text-red-600">{getApiErrorMessage(save.error)}</p>}
    <div className="mt-4 flex justify-end gap-3"><button type="button" disabled={save.isPending} onClick={onClose} className="min-h-11 rounded-lg border px-4">Hủy</button><button type="button" disabled={save.isPending} onClick={() => save.mutate()} className="min-h-11 rounded-lg bg-slate-900 px-4 text-white disabled:opacity-50">{save.isPending ? 'Đang lưu...' : 'Lưu thứ tự'}</button></div>
  </>
}
