import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { createBoardingHouseWithRooms } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { roomKeys } from '../../api/roomKeys'
import BoardingHouseForm from '../../components/boarding-houses/BoardingHouseForm'
import BulkRoomForm from '../../components/boarding-houses/BulkRoomForm'

export default function BoardingHouseCreatePage() {
  const [phase, setPhase] = useState(1)
  const [house, setHouse] = useState({ name: '', address: '', ward: '', description: '' })
  const [rooms, setRooms] = useState([])
  const [floors, setFloors] = useState([])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const mutation = useMutation({ mutationFn: createBoardingHouseWithRooms })

  const handleSubmit = async (newRooms, newFloors = []) => {
    const response = await mutation.mutateAsync({ boarding_house: house, floors: newFloors, rooms: newRooms })
    const id = response.data.data.id
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all }),
      queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(id) }),
    ])
    navigate('/boarding-houses/' + id, { replace: true, state: { message: response.data.message } })
  }

  return <section className="min-w-0 max-w-5xl">
    <Link to="/boarding-houses" className="inline-flex min-h-11 items-center text-sm text-slate-600 hover:text-slate-950 sm:min-h-0">← Quay lại danh sách</Link>
    <h2 className="mb-4 mt-3 text-xl sm:text-2xl font-bold">Thêm nhà trọ</h2>
    <ol aria-label="Các bước tạo nhà trọ" className="mb-4 grid grid-cols-2 gap-2 text-sm sm:mb-6 sm:gap-3">
      {['Thông tin nhà trọ', 'Tạo phòng'].map((label, index) => <li key={label} aria-current={phase === index + 1 ? 'step' : undefined} className={'min-w-0 rounded-lg border px-3 py-3 text-xs sm:px-4 sm:text-sm ' + (phase === index + 1 ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-500')}>{index + 1}. {label}</li>)}
    </ol>
    {phase === 1
      ? <BoardingHouseForm initialValues={{ ...house, description: house.description ?? '' }} onSubmit={async (values) => { setHouse(values); setPhase(2) }} submitLabel="Tiếp tục: Tạo phòng →" />
      : <><div className="mb-4 break-words rounded-lg bg-slate-100 p-4 text-sm"><p className="font-semibold">{house.name}</p><p className="mt-1 text-slate-600">{house.address}</p><p className="mt-2 text-xs text-slate-500">Chưa lưu dữ liệu. Nhà trọ, tầng và các phòng chỉ được tạo khi bạn xác nhận bên dưới.</p></div><BulkRoomForm initialRooms={rooms} initialFloors={floors} onBack={(draftRooms, draftFloors) => { setRooms(draftRooms); setFloors(draftFloors); setPhase(1) }} onSubmit={handleSubmit} /></>}
  </section>
}
