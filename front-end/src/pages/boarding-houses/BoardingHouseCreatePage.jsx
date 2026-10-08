import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { createBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import BoardingHouseForm from '../../components/boarding-houses/BoardingHouseForm'

export default function BoardingHouseCreatePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const mutation = useMutation({ mutationFn: createBoardingHouse })

  const handleSubmit = async (payload) => {
    await mutation.mutateAsync(payload)
    await queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all })
    navigate('/boarding-houses', { replace: true, state: { message: 'Tạo nhà trọ thành công.' } })
  }

  return (
    <section className="max-w-2xl">
      <Link to="/boarding-houses" className="text-sm text-slate-600 hover:text-slate-950">← Quay lại danh sách</Link>
      <h2 className="mb-6 mt-3 text-2xl font-bold">Thêm nhà trọ</h2>
      <BoardingHouseForm onSubmit={handleSubmit} submitLabel="Tạo nhà trọ" />
    </section>
  )
}
