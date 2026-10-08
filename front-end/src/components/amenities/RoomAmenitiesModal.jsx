import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createAmenity, getAmenities } from '../../api/amenityApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { updateRoom } from '../../api/roomApi'
import { roomKeys } from '../../api/roomKeys'
import AmenityIcon from './AmenityIcon'

const normalizeName = (value) => value.trim().replace(/\s+/g, ' ')
const emptyAmenities = []

export default function RoomAmenitiesModal({ room, onClose }) {
  const queryClient = useQueryClient()
  const [selectedIds, setSelectedIds] = useState(() => (room.amenities || []).map((amenity) => Number(amenity.id)))
  const [searchTerm, setSearchTerm] = useState('')
  const [isCreating, setIsCreating] = useState(false)
  const [newAmenityName, setNewAmenityName] = useState('')
  const [createError, setCreateError] = useState('')
  const [saveError, setSaveError] = useState('')
  const amenitiesQuery = useQuery({ queryKey: roomKeys.amenities, queryFn: getAmenities })
  const amenities = amenitiesQuery.data?.data?.data || emptyAmenities
  const filteredAmenities = useMemo(() => {
    const keyword = searchTerm.trim().toLocaleLowerCase('vi-VN')
    if (!keyword) return amenities
    return amenities.filter((amenity) => amenity.name.toLocaleLowerCase('vi-VN').includes(keyword))
  }, [amenities, searchTerm])

  const createMutation = useMutation({
    mutationFn: (name) => createAmenity({ name }),
    onSuccess: async (response) => {
      const amenity = response.data.data.amenity
      const amenityId = Number(amenity.id)
      setSelectedIds((currentIds) => currentIds.includes(amenityId) ? currentIds : [...currentIds, amenityId])
      setNewAmenityName('')
      setCreateError('')
      setIsCreating(false)
      setSearchTerm('')
      await queryClient.invalidateQueries({ queryKey: roomKeys.amenities })
    },
    onError: (error) => setCreateError(error.response?.data?.errors?.name?.[0] || getApiErrorMessage(error)),
  })

  const saveMutation = useMutation({
    mutationFn: () => updateRoom(room.id, {
      room_code: room.room_code,
      room_name: room.room_name,
      area: room.area,
      monthly_rent: Number(room.monthly_rent),
      max_tenants: room.max_tenants,
      status: room.status,
      description: room.description,
      amenity_ids: selectedIds,
    }),
    onSuccess: async () => {
      setSaveError('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: roomKeys.detail(room.id) }),
        queryClient.invalidateQueries({ queryKey: roomKeys.byBoardingHouse(room.boarding_house_id) }),
      ])
      onClose()
    },
    onError: (error) => setSaveError(getApiErrorMessage(error)),
  })

  const toggleAmenity = (amenityId) => {
    setSelectedIds((currentIds) => currentIds.includes(amenityId)
      ? currentIds.filter((id) => id !== amenityId)
      : [...currentIds, amenityId])
  }

  const submitNewAmenity = (event) => {
    event.preventDefault()
    const name = normalizeName(newAmenityName)
    if (!name) {
      setCreateError('Vui lòng nhập tên tiện nghi.')
      return
    }
    createMutation.mutate(name)
  }

  return <div role="dialog" aria-modal="true" aria-labelledby="amenity-editor-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
      <div className="flex items-start justify-between gap-4"><div><h3 id="amenity-editor-title" className="text-lg font-semibold">Tiện nghi phòng</h3><p className="mt-1 text-sm text-slate-600">Chọn tiện nghi cho phòng {room.room_code}.</p></div><button type="button" aria-label="Đóng" onClick={onClose} className="text-lg text-slate-500 hover:text-slate-950">✕</button></div>

      <div className="mt-5 flex flex-wrap gap-3"><input type="search" value={searchTerm} onChange={(event) => setSearchTerm(event.target.value)} placeholder="Tìm tiện nghi..." className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" /><button type="button" onClick={() => { setCreateError(''); setIsCreating(true) }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">+ Thêm tiện nghi mới</button></div>

      {isCreating && <form onSubmit={submitNewAmenity} className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4"><label htmlFor="new-amenity-name" className="text-sm font-medium">Tên tiện nghi *</label><input id="new-amenity-name" autoFocus value={newAmenityName} onChange={(event) => setNewAmenityName(event.target.value)} placeholder="VD: Máy lọc nước" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />{createError && <p className="mt-2 text-sm text-red-600">{createError}</p>}<div className="mt-3 flex justify-end gap-3"><button type="button" onClick={() => { setIsCreating(false); setCreateError('') }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Hủy</button><button disabled={createMutation.isPending} className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-60">{createMutation.isPending ? 'Đang tạo...' : 'Tạo tiện nghi'}</button></div></form>}

      {amenitiesQuery.isPending ? <p className="mt-5 text-sm text-slate-500">Đang tải tiện nghi...</p> : amenitiesQuery.isError ? <p className="mt-5 text-sm text-red-600">Không thể tải danh mục tiện nghi.</p> : amenities.length === 0 ? <div className="mt-5 rounded-lg border border-dashed p-6 text-center"><p className="text-sm text-slate-600">Chưa có tiện nghi trong danh mục.</p><button type="button" onClick={() => setIsCreating(true)} className="mt-4 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">+ Tạo tiện nghi đầu tiên</button></div> : filteredAmenities.length === 0 ? <p className="mt-5 text-sm text-slate-500">Không tìm thấy tiện nghi phù hợp.</p> : <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{filteredAmenities.map((amenity) => { const amenityId = Number(amenity.id); const checked = selectedIds.includes(amenityId); return <label key={amenity.id} className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm ${checked ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'}`}><input type="checkbox" checked={checked} onChange={() => toggleAmenity(amenityId)} /><AmenityIcon name={amenity.name} icon={amenity.icon} /><span>{amenity.name}</span></label> })}</div>}

      {saveError && <p className="mt-4 text-sm text-red-600">{saveError}</p>}
      <div className="mt-6 flex justify-end gap-3"><button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Hủy</button><button type="button" disabled={amenitiesQuery.isPending || saveMutation.isPending} onClick={() => saveMutation.mutate()} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{saveMutation.isPending ? 'Đang lưu...' : 'Lưu tiện nghi'}</button></div>
    </div>
  </div>
}
