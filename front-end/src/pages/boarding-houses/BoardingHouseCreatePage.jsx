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
  const [house, setHouse] = useState({ name: '', address: '', description: '' })
  const [rooms, setRooms] = useState([])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const mutation = useMutation({ mutationFn: createBoardingHouseWithRooms })

  const handleSubmit = async (newRooms) => {
    const response = await mutation.mutateAsync({ boarding_house: house, rooms: newRooms })
    const id = response.data.data.id
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all }),
      queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(id) }),
    ])
    navigate('/boarding-houses/' + id, { replace: true, state: { message: response.data.message } })
  }

  return <section className="max-w-5xl">
    <Link to="/boarding-houses" className="text-sm text-slate-600 hover:text-slate-950">← Quay lại danh sách</Link>
    <h2 className="mb-4 mt-3 text-2xl font-bold">Thêm nhà trọ</h2>
    <ol aria-label="Các bước tạo nhà trọ" className="mb-6 grid grid-cols-2 gap-3 text-sm">
      {['Thông tin nhà trọ', 'Tạo phòng'].map((label, index) => <li key={label} aria-current={phase === index + 1 ? 'step' : undefined} className={'rounded-lg border px-4 py-3 ' + (phase === index + 1 ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-500')}>{index + 1}. {label}</li>)}
    </ol>
    {phase === 1
      ? <BoardingHouseForm initialValues={{ ...house, description: house.description ?? '' }} onSubmit={async (values) => { setHouse(values); setPhase(2) }} submitLabel="Tiếp tục: Tạo phòng →" />
      : <><div className="mb-4 rounded-lg bg-slate-100 p-4 text-sm"><p className="font-semibold">{house.name}</p><p className="mt-1 text-slate-600">{house.address}</p><p className="mt-2 text-xs text-slate-500">Chưa lưu dữ liệu. Nhà trọ và các phòng chỉ được tạo khi bạn xác nhận bên dưới.</p></div><BulkRoomForm initialRooms={rooms} onBack={(draftRooms) => { setRooms(draftRooms); setPhase(1) }} onSubmit={handleSubmit} /></>}
  </section>
}