import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  createUtilityMeter,
  deleteUtilityMeter,
  getUtilityMeters,
  updateUtilityMeter,
} from '../../api/utilityMeterApi'
import { utilityKeys } from '../../api/utilityKeys'
import { getRoomServices } from '../../api/roomServiceApi'
import { roomKeys } from '../../api/roomKeys'
import UtilityMeterForm from '../forms/UtilityMeterForm'
import UtilityReadingModal from './UtilityReadingModal'
import { formatDate, formatUtilityValue } from '../../utils/formatters'
import { BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS } from '../../utils/service'
import { getMeterServices } from '../../utils/roomServices'
import { formatMeterPrice } from '../../utils/utilityReading'

// Visual grouping only. Never combine readings or change which meters are billed.
function utilityMeterKind(meter) {
  const type = meter.service?.type
  if (type === 'ELECTRICITY' || /điện/i.test(meter.service?.name || '')) return 'electric'
  if (type === 'WATER' || /nước/i.test(meter.service?.name || '')) return 'water'
  return 'other'
}

function getMeterTitle(meter) {
  return SERVICE_TYPE_LABELS[meter.service?.type] || meter.service?.name || 'Dịch vụ'
}

function formatMeterValue(value, unit) {
  const formattedValue = formatUtilityValue(value)
  if (!formattedValue) return '—'

  return unit ? `${formattedValue} ${unit}` : formattedValue
}

function getConsumptionSinceInitialReading(meter) {
  const currentReading = meter.current_reading || meter.latest_reading
  if (currentReading?.reading_value == null || currentReading.source === 'METER_INITIAL') return null

  const consumption = Number(currentReading.reading_value) - Number(meter.initial_reading)
  return Number.isFinite(consumption) && consumption >= 0 ? consumption : null
}

export default function RoomUtilitiesSection({ room }) {
  const queryClient = useQueryClient()
  const [editingMeter, setEditingMeter] = useState(null)
  const [meterForNewReading, setMeterForNewReading] = useState(null)
  const [selectedMeterGroup, setSelectedMeterGroup] = useState('electric')
  const useV2 = room.room_type === 'PRIVATE_ROOM' && room.can_manage !== false

  const metersQuery = useQuery({
    queryKey: utilityKeys.meters(room.id),
    queryFn: () => getUtilityMeters(room.id),
  })
  const servicesQuery = useQuery({
    queryKey: roomKeys.services(room.id),
    queryFn: () => getRoomServices(room.id),
  })

  const saveMeter = useMutation({
    mutationFn: (payload) => (
      editingMeter?.id
        ? updateUtilityMeter(editingMeter.id, payload)
        : createUtilityMeter(room.id, payload)
    ),
    onSuccess: async () => {
      setEditingMeter(null)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: utilityKeys.meters(room.id) }),
        queryClient.invalidateQueries({ queryKey: roomKeys.detail(room.id) }),
      ])
    },
  })
  const deleteMeter = useMutation({
    mutationFn: deleteUtilityMeter,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: utilityKeys.meters(room.id) }),
        queryClient.invalidateQueries({ queryKey: roomKeys.detail(room.id) }),
      ])
    },
  })

  const meters = metersQuery.data?.data?.data || []
  const meterGroups = [
    { key: 'electric', name: 'Điện', symbol: '⚡', subtitle: 'Công tơ và chỉ số điện', meters: meters.filter(meter => utilityMeterKind(meter) === 'electric') },
    { key: 'water', name: 'Nước', symbol: '💧', subtitle: 'Công tơ và chỉ số nước', meters: meters.filter(meter => utilityMeterKind(meter) === 'water') },
    { key: 'other', name: 'Công tơ khác', symbol: '▦', subtitle: 'Thiết bị đo khác đang gắn với phòng', meters: meters.filter(meter => utilityMeterKind(meter) === 'other') },
  ].filter(group => group.meters.length > 0)
  const selectedGroupKey = meterGroups.some(
    group => group.key === selectedMeterGroup
  ) ? selectedMeterGroup : meterGroups[0]?.key
  const meterServices = getMeterServices(servicesQuery.data?.data?.data || [])
  const canAddMeter = !servicesQuery.isPending && !servicesQuery.isError && meterServices.length > 0

  return <section className="mt-6 min-w-0 rounded-xl bg-white p-4 sm:p-6 shadow-sm">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 className="text-lg font-semibold text-slate-900">Điện và nước</h3>
        <p className="mt-1 text-sm text-slate-500">{useV2 ? 'Tóm tắt chỉ số đã ghi nhận. Ghi và chỉnh sửa chỉ số tại Quyết toán V2.' : 'Theo dõi đồng hồ và ghi chỉ số ngay tại phòng.'}</p>
      </div>
      {useV2 ? <Link to={`/rooms/${room.id}/billing-v2`} className="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white active:brightness-95 sm:w-auto">Mở quyết toán V2</Link> : <button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="min-h-11 sm:min-h-0 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Thêm đồng hồ</button>}
    </div>

    {!servicesQuery.isPending && !servicesQuery.isError && meterServices.length === 0 && <p className="mt-4 text-sm text-slate-500">Muốn thêm đồng hồ, hãy chọn dịch vụ Theo đơn vị hoặc Bậc thang ở phần Dịch vụ áp dụng. Dịch vụ Cố định / Theo người không cần đồng hồ.</p>}
    {servicesQuery.isError && <p className="mt-4 text-sm text-red-600">{getApiErrorMessage(servicesQuery.error)}</p>}
    {deleteMeter.isError && <p role="alert" className="mt-4 text-sm text-red-600">{getApiErrorMessage(deleteMeter.error)}</p>}

    {metersQuery.isPending ? <p className="mt-5 text-sm text-slate-500">Đang tải đồng hồ...</p>
      : metersQuery.isError ? <p className="mt-5 text-sm text-red-600">{getApiErrorMessage(metersQuery.error)}</p>
        : meters.length === 0 ? <div className="mt-5 rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center"><p className="text-sm text-slate-500">Phòng này chưa có đồng hồ điện/nước.</p>{useV2 ? <Link to={`/rooms/${room.id}/billing-v2`} className="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-slate-900 underline underline-offset-4">Mở V2 để thêm đồng hồ và ghi mốc</Link> : <button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-slate-900 underline underline-offset-4 disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-0">Thêm đồng hồ</button>}</div>
          : <div className="mt-5 space-y-3">
              <div
                className="grid grid-cols-2 gap-2 xl:hidden"
                role="group"
                aria-label="Chọn nhóm đồng hồ điện nước"
              >
                {meterGroups.map(group => (
                  <button
                    key={group.key}
                    type="button"
                    aria-pressed={selectedGroupKey === group.key}
                    onClick={() => setSelectedMeterGroup(group.key)}
                    className={selectedGroupKey === group.key
                      ? 'flex min-h-16 min-w-0 flex-col justify-center rounded-xl border border-teal-500 bg-teal-50 p-3 text-left text-teal-900 ring-1 ring-teal-200 active:bg-teal-100'
                      : 'flex min-h-16 min-w-0 flex-col justify-center rounded-xl border border-slate-200 bg-white p-3 text-left text-slate-800 active:bg-slate-100'}
                  >
                    <span className="text-sm font-bold">
                      {group.symbol} {group.name}
                    </span>
                    <span className="mt-1 text-xs opacity-80">
                      {group.meters.length} đồng hồ
                    </span>
                  </button>
                ))}
              </div>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                {meterGroups.map(group => <section
                  key={group.key}
                  aria-label={'Nhóm ' + group.name.toLowerCase()}
                  className={selectedGroupKey === group.key
                    ? 'min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-3 sm:p-4'
                    : 'hidden min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-3 sm:p-4 xl:block'}
                >
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="flex min-w-0 items-center gap-2">
                <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white text-xl shadow-sm">{group.symbol}</span>
                <div className="min-w-0"><h4 className="font-bold text-slate-900">{group.name}</h4><p className="text-xs text-slate-500">{group.subtitle}</p></div>
              </div>
              <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{group.meters.length} đồng hồ</span>
            </div>
            <div className="grid min-w-0 grid-cols-1 gap-3">{group.meters.map((meter) => {
            const unit = meter.service?.unit || ''
            const currentReading = meter.current_reading || meter.latest_reading
            const hasLatestReading = currentReading?.reading_value != null && currentReading.source !== 'METER_INITIAL'
            const consumption = useV2
              ? (currentReading?.source === 'BILLING_V2_CHECKPOINT' ? currentReading.recent_boundary_usage : null)
              : getConsumptionSinceInitialReading(meter)

            return <article key={meter.id} className="min-w-0 rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm sm:p-5">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0"><h4 className="text-base font-semibold text-slate-900">{getMeterTitle(meter)}</h4>{meter.service?.name && <p className="mt-1 truncate text-sm text-slate-500" title={meter.service.name}>{meter.service.name}</p>}</div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${meter.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{meter.is_active ? 'Đang hoạt động' : 'Ngừng sử dụng'}</span>
              </div>

              <dl className="mt-4 grid gap-3 border-y border-slate-100 py-4 text-sm">
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Cách tính</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{BILLING_METHOD_LABELS[meter.service?.billing_method] || meter.service?.billing_method || '—'}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Đơn giá hiện tại</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{formatMeterPrice(meter.service)}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Đơn vị</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{unit || "Chưa cập nhật"}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Mã đồng hồ</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{meter.meter_code || 'Chưa cập nhật'}</dd></div>
              </dl>

              <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-4">
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{useV2 ? 'Chỉ số gốc đồng hồ' : 'Chỉ số đầu'}</dt><dd className="mt-1 break-words text-sm font-semibold text-slate-800">{formatMeterValue(meter.initial_reading, unit)}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Chỉ số hiện tại</dt><dd className="mt-1 break-words text-sm font-semibold text-slate-900">{hasLatestReading ? formatMeterValue(currentReading.reading_value, unit) : 'Chưa ghi chỉ số'}</dd>{currentReading?.source === 'CONTRACT_HANDOVER' && <p className="mt-1 text-xs text-slate-500">Theo chỉ số bàn giao hợp đồng</p>}{currentReading?.source === 'BILLING_V2_CHECKPOINT' && <p className="mt-1 text-xs text-emerald-700">Mốc V2 đã xác nhận</p>}{useV2 && currentReading?.source === 'UTILITY_READING' && <p className="mt-1 text-xs text-amber-700">Số V1 cũ — cần đối chiếu khi quyết toán V2</p>}</div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{useV2 ? 'Chênh 2 mốc V2 gần nhất' : 'Tiêu thụ'}</dt><dd className="mt-1 break-words text-sm font-semibold text-slate-800">{consumption == null ? '—' : formatMeterValue(consumption, unit)}</dd>{useV2 && <p className="mt-1 text-xs text-slate-500">Tham khảo từ các mốc xác nhận; tiền hóa đơn tính riêng tại V2</p>}</div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Cập nhật</dt><dd className="mt-1 break-words text-sm font-medium text-slate-700">{hasLatestReading && currentReading?.reading_date ? formatDate(currentReading.reading_date) : 'Chưa ghi chỉ số'}</dd></div>
              </dl>

              <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                {useV2 ? <Link to={`/rooms/${room.id}/billing-v2`} className="inline-flex min-h-11 sm:min-h-0 items-center rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-700">Ghi / sửa chỉ số &amp; lịch sử V2</Link> : <>
                  <button type="button" disabled={!meter.is_active} onClick={() => setMeterForNewReading(meter)} className="min-h-11 sm:min-h-0 rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">+ Thêm chỉ số mới</button>
                  <Link to={`/utility-meters/${meter.id}`} className="inline-flex min-h-11 sm:min-h-0 items-center rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">Lịch sử V1</Link>
                </>}
                <button type="button" onClick={() => setEditingMeter(meter)} className="inline-flex min-h-11 sm:min-h-0 items-center rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">Sửa thông tin đồng hồ</button>
                {!useV2 && <button type="button" onClick={() => window.confirm('Bạn có chắc muốn xóa đồng hồ này?') && deleteMeter.mutate(meter.id)} className="min-h-11 sm:min-h-0 rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 transition hover:bg-red-50">Xóa</button>}
              </div>
            </article>
          })}</div>
          </section>)}
              </div>
            </div>}

    {editingMeter && <div role="dialog" aria-modal="true" aria-labelledby="meter-dialog-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4"><div className="max-h-[90dvh] w-full max-w-md min-w-0 overscroll-contain overflow-y-auto rounded-xl bg-white p-4 sm:p-6"><div className="mb-4 flex items-start justify-between gap-3"><h3 id="meter-dialog-title" className="font-semibold">{editingMeter.id ? 'Sửa đồng hồ' : 'Thêm đồng hồ'}</h3><button type="button" onClick={() => setEditingMeter(null)} aria-label="Đóng" className="flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100">✕</button></div><UtilityMeterForm key={editingMeter.id || `new-${room.id}`} roomId={room.id} initialValues={editingMeter} onSubmit={(payload) => saveMeter.mutateAsync(payload)} lockInitialReading={useV2 && Boolean(editingMeter.id)} label="Lưu đồng hồ" /></div></div>}
    {meterForNewReading && <UtilityReadingModal roomId={room.id} meter={meterForNewReading} onClose={() => setMeterForNewReading(null)} />}
  </section>
}
