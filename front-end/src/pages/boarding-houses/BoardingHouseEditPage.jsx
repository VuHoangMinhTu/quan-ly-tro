import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getBoardingHouse, updateBoardingHouse } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import BoardingHouseForm from '../../components/boarding-houses/BoardingHouseForm'

export default function BoardingHouseEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: boardingHouseKeys.detail(id), queryFn: () => getBoardingHouse(id) })
  const mutation = useMutation({ mutationFn: (payload) => updateBoardingHouse(id, payload) })

  const handleSubmit = async (payload) => {
    await mutation.mutateAsync(payload)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all }),
      queryClient.invalidateQueries({ queryKey: boardingHouseKeys.detail(id) }),
    ])
    navigate(`/boarding-houses/${id}`, { replace: true, state: { message: 'Cập nhật nhà trọ thành công.' } })
  }

  if (query.isPending) return <p>Đang tải thông tin nhà trọ...</p>
  if (query.isError) return <p className="text-red-600">{getApiErrorMessage(query.error)}</p>

  return (
    <section className="max-w-2xl">
      <Link to={`/boarding-houses/${id}`} className="text-sm text-slate-600 hover:text-slate-950">← Quay lại chi tiết</Link>
      <h2 className="mb-6 mt-3 text-2xl font-bold">Sửa nhà trọ</h2>
      <BoardingHouseForm initialValues={query.data.data.data} onSubmit={handleSubmit} submitLabel="Lưu thay đổi" />
    </section>
  )
}
