import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Eye, Pencil, Plus, Search, Trash2, X } from 'lucide-react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { getBoardingHouses } from '../../api/boardingHouseApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getRoomsByBoardingHouse } from '../../api/roomApi'
import { roomKeys } from '../../api/roomKeys'
import { deleteTenant, getTenants } from '../../api/tenantApi'
import { tenantKeys } from '../../api/tenantKeys'
import { formatDate } from '../../utils/formatters'

const occupancyOptions = [['ACTIVE', 'Đang ở'], ['MOVED_OUT', 'Đã chuyển đi'], ['UNASSIGNED', 'Chưa xếp phòng']]
const roleOptions = [['PRIMARY', 'Người đứng tên'], ['ROOMMATE', 'Người ở cùng']]
const contractOptions = [['ACTIVE', 'Đang hiệu lực'], ['EXPIRED', 'Hết hạn'], ['TERMINATED', 'Đã chấm dứt'], ['NONE', 'Chưa có hợp đồng']]

function TenantSearch({ value, onCommit }) {
  const [search, setSearch] = useState({ urlValue: value, draft: value })
  // Restore back/forward URL changes without remounting the focused input.
  if (search.urlValue !== value) {
    setSearch({ urlValue: value, draft: value })
  }
  const draft = search.urlValue === value ? search.draft : value
  useEffect(() => {
    if (draft.trim() === value) return
    const timer = setTimeout(() => onCommit(draft.trim()), 350)
    return () => clearTimeout(timer)
  }, [draft, value, onCommit])

  return <div className="max-w-xl">
    <label className="sr-only" htmlFor="tenant-search">Tìm người thuê</label>
    <div className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 focus-within:ring-2 focus-within:ring-slate-400">
      <Search size={17} className="shrink-0 text-slate-400" aria-hidden="true" />
      <input id="tenant-search" type="search" value={draft} onChange={(event) => setSearch({ urlValue: value, draft: event.target.value })} placeholder="Tìm theo tên, SĐT, email, CCCD" className="min-w-0 w-full py-3 text-sm outline-none" />
    </div>
  </div>
}

function TenantFilter({ label, value, onChange, options, emptyLabel = 'Tất cả', disabled = false }) {
  const id = useId()
  return <div className="min-w-0">
    <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
    <select id={id} value={value} onChange={(event) => onChange(event.target.value)} disabled={disabled} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-normal focus:outline-none focus:ring-2 focus:ring-slate-400 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500">
      <option value="">{emptyLabel}</option>
      {options.map(([id, text]) => <option key={id} value={String(id)}>{text}</option>)}
    </select>
  </div>
}

export default function TenantListPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filterParams = useRef(searchParams)
  useEffect(() => { filterParams.current = searchParams }, [searchParams])
  const [searchReset, setSearchReset] = useState(0)
  const [confirmingId, setConfirmingId] = useState(null)
  const [deleteError, setDeleteError] = useState('')
  const location = useLocation()
  const queryClient = useQueryClient()
  const filters = {
    q: (searchParams.get('q') || '').trim(),
    boardingHouseId: searchParams.get('boarding_house_id') || '',
    roomId: searchParams.get('room_id') || '',
    occupancyStatus: searchParams.get('occupancy_status') || '',
    role: searchParams.get('role') || '',
    contractStatus: searchParams.get('contract_status') || '',
  }
  const hasFilters = Object.values(filters).some(Boolean)
  const updateFilter = useCallback((name, value) => {
    // Router's functional search-param setter is not a queued React state updater.
    // Keep the latest selection so rapid filter changes cannot overwrite each other.
    const next = new URLSearchParams(filterParams.current)
    if (value) next.set(name, value)
    else next.delete(name)
    if (name === 'boarding_house_id') next.delete('room_id')
    filterParams.current = next
    setSearchParams(next)
  }, [setSearchParams])
  const commitSearch = useCallback((value) => updateFilter('q', value), [updateFilter])
  const resetFilters = () => {
    filterParams.current = new URLSearchParams()
    setSearchParams(filterParams.current)
    setSearchReset((previous) => previous + 1)
  }

  const housesQuery = useQuery({ queryKey: boardingHouseKeys.all, queryFn: getBoardingHouses })
  const roomsQuery = useQuery({
    queryKey: roomKeys.byBoardingHouse(filters.boardingHouseId),
    queryFn: () => getRoomsByBoardingHouse(filters.boardingHouseId),
    enabled: Boolean(filters.boardingHouseId),
  })
  const query = useQuery({
    queryKey: tenantKeys.list(filters),
    queryFn: ({ signal }) => getTenants({ ...filters, signal }),
    placeholderData: keepPreviousData,
  })
  const mutation = useMutation({
    mutationFn: deleteTenant,
    onSuccess: () => {
      setConfirmingId(null)
      queryClient.invalidateQueries({ queryKey: tenantKeys.all })
    },
    onError: (error) => setDeleteError(getApiErrorMessage(error)),
  })
  const tenants = query.data?.data?.data || []
  const houses = housesQuery.data?.data?.data || []
  const rooms = roomsQuery.data?.data?.data || []

  const renderActions = (tenant) => <div className="flex flex-wrap items-center gap-2">
    <Link to={'/tenants/' + tenant.id} className="inline-flex min-h-11 sm:min-h-0 items-center gap-1 rounded-lg border border-slate-200 px-3 text-sm hover:bg-slate-50"><Eye size={15} /> Xem</Link>
    <Link to={'/tenants/' + tenant.id + '/edit'} className="inline-flex min-h-11 sm:min-h-0 items-center gap-1 rounded-lg border border-slate-200 px-3 text-sm hover:bg-slate-50"><Pencil size={15} /> Sửa</Link>
    {confirmingId === tenant.id ? <><button type="button" onClick={() => mutation.mutate(tenant.id)} disabled={mutation.isPending || query.isPlaceholderData} className="min-h-11 sm:min-h-0 rounded-lg px-3 text-sm font-medium text-red-700 disabled:opacity-60">{mutation.isPending ? 'Đang xóa...' : 'Xác nhận'}</button><button type="button" onClick={() => setConfirmingId(null)} className="min-h-11 sm:min-h-0 rounded-lg px-3 text-sm">Hủy</button></>
      : <button type="button" disabled={query.isPlaceholderData} onClick={() => { setDeleteError(''); setConfirmingId(tenant.id) }} className="inline-flex min-h-11 sm:min-h-0 items-center gap-1 rounded-lg px-3 text-sm text-red-700 hover:bg-red-50 disabled:opacity-60"><Trash2 size={15} /> Xóa</button>}
  </div>

  return <section className="min-w-0">
    <div className="mb-6 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div><h2 className="break-words text-xl font-bold sm:text-2xl">Người thuê</h2><p className="mt-1 text-sm text-slate-600">Quản lý hồ sơ người thuê của bạn.</p></div>
      <Link to="/tenants/new" className="inline-flex min-h-11 sm:min-h-0 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white"><Plus size={17} /> Thêm người thuê</Link>
    </div>
    {location.state?.message && <p className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{location.state.message}</p>}
    {deleteError && <p role="alert" className="mb-4 text-sm text-red-600">{deleteError}</p>}
    <div className="mb-4 space-y-3">
      <TenantSearch key={searchReset} value={filters.q} onCommit={commitSearch} />
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <TenantFilter label="Nhà trọ" value={filters.boardingHouseId} onChange={(value) => updateFilter('boarding_house_id', value)} options={houses.map((house) => [house.id, house.name])} emptyLabel={housesQuery.isPending ? 'Đang tải nhà trọ...' : 'Tất cả nhà trọ'} disabled={housesQuery.isPending || housesQuery.isError} />
        <TenantFilter label="Phòng" value={filters.roomId} onChange={(value) => updateFilter('room_id', value)} options={rooms.map((room) => [room.id, 'Phòng ' + room.room_code + (room.room_name ? ' — ' + room.room_name : '')])} emptyLabel={!filters.boardingHouseId ? 'Chọn nhà trọ trước' : roomsQuery.isPending ? 'Đang tải phòng...' : 'Tất cả phòng'} disabled={!filters.boardingHouseId || roomsQuery.isPending || roomsQuery.isError} />
        <TenantFilter label="Trạng thái cư trú" value={filters.occupancyStatus} onChange={(value) => updateFilter('occupancy_status', value)} options={occupancyOptions} />
        <TenantFilter label="Vai trò" value={filters.role} onChange={(value) => updateFilter('role', value)} options={roleOptions} />
        <TenantFilter label="Hợp đồng" value={filters.contractStatus} onChange={(value) => updateFilter('contract_status', value)} options={contractOptions} />
      </div>
      {housesQuery.isError && <p role="alert" className="text-sm text-red-600">Không tải được bộ lọc nhà trọ. {getApiErrorMessage(housesQuery.error)}</p>}
      {filters.boardingHouseId && roomsQuery.isError && <p role="alert" className="text-sm text-red-600">Không tải được bộ lọc phòng. {getApiErrorMessage(roomsQuery.error)}</p>}
      {hasFilters && <button type="button" onClick={resetFilters} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"><X size={15} aria-hidden="true" /> Xóa bộ lọc</button>}
    </div>
    <div role="status" aria-live="polite" className="mb-2 min-h-5 text-sm text-slate-500">{query.isFetching ? 'Đang tải danh sách người thuê...' : !query.isError ? tenants.length + ' người thuê' : ''}</div>
    {query.isError ? <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{getApiErrorMessage(query.error)}</p>
      : query.isPending ? <div className="rounded-xl border bg-white p-8 text-center text-sm text-slate-500">Đang tải danh sách người thuê...</div>
        : tenants.length === 0 ? <div className="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
          <p className="font-medium">{hasFilters ? 'Không tìm thấy người thuê phù hợp với bộ lọc.' : 'Bạn chưa có người thuê nào.'}</p>
          {hasFilters ? <button type="button" onClick={resetFilters} className="mt-4 rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Xóa bộ lọc</button> : <Link to="/tenants/new" className="mt-4 inline-block underline">Thêm người thuê</Link>}
        </div>
          : <div aria-busy={query.isFetching}>
            <div className="space-y-3 md:hidden">{tenants.map((tenant) => <article key={tenant.id} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
              <h3 className="break-words font-semibold text-slate-900">{tenant.full_name}</h3>
              <p className="mt-1 break-words text-sm text-slate-600">{tenant.phone || 'Chưa cập nhật số điện thoại'}</p>
              <p className="mt-2 break-words text-sm text-slate-600">{tenant.current_residence ? tenant.current_residence.boarding_house_name + ' · Phòng ' + tenant.current_residence.room_code : 'Chưa xếp phòng'}</p>
              <div className="mt-4 border-t border-slate-100 pt-3">{renderActions(tenant)}</div>
            </article>)}</div>
            <div className="hidden overflow-x-auto rounded-xl bg-white shadow-sm md:block">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead className="bg-slate-50 text-slate-600"><tr>{['Họ tên', 'Số điện thoại', 'Email', 'CCCD / Số định danh', 'Đang ở', 'Ngày sinh', 'Thao tác'].map((label) => <th scope="col" key={label} className="px-4 py-3 font-medium">{label}</th>)}</tr></thead>
              <tbody className="divide-y divide-slate-100">{tenants.map((tenant) => <tr key={tenant.id}>
                <td className="px-4 py-4 font-medium">{tenant.full_name}</td>
                <td className="whitespace-nowrap px-4 py-4">{tenant.phone || '—'}</td>
                <td className="max-w-48 break-words px-4 py-4">{tenant.email || '—'}</td>
                <td className="px-4 py-4">{tenant.identity_number || '—'}</td>
                <td className="min-w-36 max-w-48 px-4 py-4">{tenant.current_residence ? <><p className="font-medium">{tenant.current_residence.boarding_house_name}</p><p className="mt-1 text-xs text-slate-500">Phòng {tenant.current_residence.room_code}</p></> : <span className="text-slate-500">Chưa xếp phòng</span>}</td>
                <td className="whitespace-nowrap px-4 py-4">{formatDate(tenant.date_of_birth)}</td>
                <td className="px-4 py-4">{renderActions(tenant)}</td>
              </tr>)}</tbody>
            </table>
            </div>
          </div>}
  </section>
}
