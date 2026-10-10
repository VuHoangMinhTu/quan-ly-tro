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

function getMeterTitle(meter) {
  return SERVICE_TYPE_LABELS[meter.service?.type] || meter.service?.name || 'Dịch vụ'
}

function formatMeterValue(value, unit) {
  const formattedValue = formatUtilityValue(value)
  if (!formattedValue) return '—'

  return unit ? `${formattedValue} ${unit}` : formattedValue
}

function getConsumptionSinceInitialReading(meter) {
  if (meter.latest_reading?.reading_value == null) return null

  const consumption = Number(meter.latest_reading.reading_value) - Number(meter.initial_reading)
  return Number.isFinite(consumption) && consumption >= 0 ? consumption : null
}

export default function RoomUtilitiesSection({ room }) {
  const queryClient = useQueryClient()
  const [editingMeter, setEditingMeter] = useState(null)
  const [meterForNewReading, setMeterForNewReading] = useState(null)

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
  const meterServices = getMeterServices(servicesQuery.data?.data?.data || [])
  const canAddMeter = !servicesQuery.isPending && !servicesQuery.isError && meterServices.length > 0

  return <section className="mt-6 rounded-xl bg-white p-6 shadow-sm">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 className="text-lg font-semibold text-slate-900">Điện nước</h3>
        <p className="mt-1 text-sm text-slate-500">Theo dõi đồng hồ và ghi chỉ số ngay tại phòng.</p>
      </div>
      <button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Thêm đồng hồ</button>
    </div>

    {!servicesQuery.isPending && !servicesQuery.isError && meterServices.length === 0 && <p className="mt-4 text-sm text-slate-500">Muốn thêm đồng hồ, hãy chọn dịch vụ Theo đơn vị hoặc Bậc thang ở phần Dịch vụ áp dụng. Dịch vụ Cố định / Theo người không cần đồng hồ.</p>}
    {servicesQuery.isError && <p className="mt-4 text-sm text-red-600">{getApiErrorMessage(servicesQuery.error)}</p>}
    {deleteMeter.isError && <p role="alert" className="mt-4 text-sm text-red-600">{getApiErrorMessage(deleteMeter.error)}</p>}

    {metersQuery.isPending ? <p className="mt-5 text-sm text-slate-500">Đang tải đồng hồ...</p>
      : metersQuery.isError ? <p className="mt-5 text-sm text-red-600">{getApiErrorMessage(metersQuery.error)}</p>
        : meters.length === 0 ? <div className="mt-5 rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center"><p className="text-sm text-slate-500">Phòng này chưa có đồng hồ điện/nước.</p><button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="mt-3 text-sm font-medium text-slate-900 underline underline-offset-4 disabled:cursor-not-allowed disabled:opacity-50">Thêm đồng hồ</button></div>
          : <div className="mt-5 grid gap-4 md:grid-cols-2">{meters.map((meter) => {
            const unit = meter.service?.unit || ''
            const hasLatestReading = meter.latest_reading?.reading_value != null
            const consumption = getConsumptionSinceInitialReading(meter)

            return <article key={meter.id} className="rounded-xl border border-slate-200 p-5 shadow-sm">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0"><h4 className="text-base font-semibold text-slate-900">{getMeterTitle(meter)}</h4>{meter.service?.name && <p className="mt-1 truncate text-sm text-slate-500" title={meter.service.name}>{meter.service.name}</p>}</div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${meter.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{meter.is_active ? 'Đang hoạt động' : 'Ngừng sử dụng'}</span>
              </div>

              <dl className="mt-4 grid gap-3 border-y border-slate-100 py-4 text-sm">
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Cách tính</dt><dd className="text-right font-medium text-slate-800">{BILLING_METHOD_LABELS[meter.service?.billing_method] || meter.service?.billing_method || '—'}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Đơn giá hiện tại</dt><dd className="text-right font-medium text-slate-800">{formatMeterPrice(meter.service)}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Đơn vị</dt><dd className="text-right font-medium text-slate-800">{unit || "Chưa cập nhật"}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">Mã đồng hồ</dt><dd className="text-right font-medium text-slate-800">{meter.meter_code || 'Chưa cập nhật'}</dd></div>
              </dl>

              <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-4">
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Chỉ số đầu</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{formatMeterValue(meter.initial_reading, unit)}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Chỉ số mới nhất</dt><dd className="mt-1 text-sm font-semibold text-slate-900">{hasLatestReading ? formatMeterValue(meter.latest_reading.reading_value, unit) : 'Chưa ghi chỉ số'}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Tiêu thụ</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{consumption == null ? '—' : formatMeterValue(consumption, unit)}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Cập nhật</dt><dd className="mt-1 text-sm font-medium text-slate-700">{meter.latest_reading?.reading_date ? formatDate(meter.latest_reading.reading_date) : 'Chưa ghi chỉ số'}</dd></div>
              </dl>

              <div className="mt-5 flex flex-wrap gap-2">
                <button type="button" disabled={!meter.is_active} onClick={() => setMeterForNewReading(meter)} className="rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">+ Thêm chỉ số mới</button>
                <Link to={`/utility-meters/${meter.id}`} className="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">Lịch sử</Link>
                <button type="button" onClick={() => setEditingMeter(meter)} className="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">Sửa</button>
                <button type="button" onClick={() => window.confirm('Bạn có chắc muốn xóa đồng hồ này?') && deleteMeter.mutate(meter.id)} className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 transition hover:bg-red-50">Xóa</button>
              </div>
            </article>
          })}</div>}

    {editingMeter && <div role="dialog" aria-modal="true" aria-labelledby="meter-dialog-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-white p-6"><div className="mb-4 flex justify-between"><h3 id="meter-dialog-title" className="font-semibold">{editingMeter.id ? 'Sửa đồng hồ' : 'Thêm đồng hồ'}</h3><button type="button" onClick={() => setEditingMeter(null)} aria-label="Đóng">✕</button></div><UtilityMeterForm key={editingMeter.id || `new-${room.id}`} roomId={room.id} initialValues={editingMeter} onSubmit={(payload) => saveMeter.mutateAsync(payload)} label="Lưu đồng hồ" /></div></div>}
    {meterForNewReading && <UtilityReadingModal roomId={room.id} meter={meterForNewReading} onClose={() => setMeterForNewReading(null)} />}
  </section>
}
