import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Eye, Pencil, Plus, Trash2 } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import { deleteBoardingHouse, getBoardingHouses } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

export default function BoardingHouseListPage() {
  const queryClient = useQueryClient()
  const location = useLocation()
  const [confirmingId, setConfirmingId] = useState(null)
  const [deleteError, setDeleteError] = useState('')
  const query = useQuery({ queryKey: boardingHouseKeys.all, queryFn: getBoardingHouses })
  const houses = query.data?.data?.data || []
  const deleteMutation = useMutation({
    mutationFn: deleteBoardingHouse,
    onSuccess: () => {
      setConfirmingId(null)
      queryClient.invalidateQueries({ queryKey: boardingHouseKeys.all })
    },
    onError: (error) => setDeleteError(getApiErrorMessage(error)),
  })

  const requestDelete = (id) => {
    setDeleteError('')
    setConfirmingId(id)
  }

  if (query.isPending) return <p>Đang tải danh sách nhà trọ...</p>
  if (query.isError) return <p className="text-red-600">{getApiErrorMessage(query.error)}</p>

  return (
    <section className="min-w-0">
      <div className="mb-6 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="break-words text-xl font-bold sm:text-2xl">Nhà trọ</h2>
          <p className="mt-1 text-sm text-slate-600">Quản lý các nhà trọ của bạn.</p>
        </div>
        <Link
          to="/boarding-houses/new"
          className="inline-flex min-h-11 sm:min-h-0 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
        >
          <Plus size={17} /> Thêm nhà trọ
        </Link>
      </div>

      {location.state?.message && (
        <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>
      )}
      {deleteError && <p className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{deleteError}</p>}

      {houses.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
          <Building2 className="mx-auto mb-3 text-slate-400" size={36} />
          <p className="font-medium">Bạn chưa có nhà trọ nào.</p>
          <Link className="mt-4 inline-block text-sm font-medium text-slate-900 underline" to="/boarding-houses/new">
            Thêm nhà trọ
          </Link>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="px-5 py-3 font-medium">Tên nhà trọ</th>
                <th className="px-5 py-3 font-medium">Địa chỉ</th>
                <th className="px-5 py-3 font-medium">Mô tả</th>
                <th className="px-5 py-3 text-right font-medium">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {houses.map((house) => (
                <tr key={house.id} className="align-top">
                  <td className="px-5 py-4 font-medium text-slate-900">{house.name}</td>
                  <td className="px-5 py-4 text-slate-600">{house.address}</td>
                  <td className="max-w-xs truncate px-5 py-4 text-slate-600">{house.description || '—'}</td>
                  <td className="px-5 py-4">
                    <div className="flex justify-end gap-3 whitespace-nowrap text-sm">
                      <Link className="inline-flex min-h-11 min-w-11 items-center gap-1 lg:min-h-0 lg:min-w-0 text-slate-700 hover:text-slate-950" to={`/boarding-houses/${house.id}`}>
                        <Eye size={16} /> Xem
                      </Link>
                      <Link className="inline-flex min-h-11 min-w-11 items-center gap-1 lg:min-h-0 lg:min-w-0 text-slate-700 hover:text-slate-950" to={`/boarding-houses/${house.id}/edit`}>
                        <Pencil size={16} /> Sửa
                      </Link>
                      {confirmingId === house.id ? (
                        <span className="flex flex-wrap items-center gap-2">
                          <button
                            onClick={() => deleteMutation.mutate(house.id)}
                            disabled={deleteMutation.isPending}
                            className="min-h-11 min-w-11 font-medium text-red-700 disabled:opacity-60 lg:min-h-0 lg:min-w-0"
                          >
                            {deleteMutation.isPending ? 'Đang xóa...' : 'Xác nhận'}
                          </button>
                          <button onClick={() => setConfirmingId(null)} className="min-h-11 min-w-11 px-2 text-slate-500 lg:min-h-0 lg:min-w-0">Hủy</button>
                        </span>
                      ) : (
                        <button onClick={() => requestDelete(house.id)} className="inline-flex min-h-11 min-w-11 items-center gap-1 lg:min-h-0 lg:min-w-0 text-red-700 hover:text-red-900">
                          <Trash2 size={16} /> Xóa
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
