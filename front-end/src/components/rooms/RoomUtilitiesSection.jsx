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
import UtilityMeterForm from '../forms/UtilityMeterForm'
import { formatDate, formatUtilityValue } from '../../utils/formatters'
import { SERVICE_TYPE_LABELS } from '../../utils/service'

function getMeterTitle(meter) {
  return SERVICE_TYPE_LABELS[meter.service?.type] || meter.service?.name || 'Dịch vụ'
}

function formatMeterValue(value, unit) {
  const formattedValue = formatUtilityValue(value)

  if (!formattedValue) return '—'

  return unit ? `${formattedValue} ${unit}` : formattedValue
}

function getConsumptionSinceInitialReading(meter) {
  if (meter.latest_reading?.reading_value === null || meter.latest_reading?.reading_value === undefined) {
    return null
  }

  const latestReading = Number(meter.latest_reading.reading_value)
  const initialReading = Number(meter.initial_reading)

  if (!Number.isFinite(latestReading) || !Number.isFinite(initialReading)) return null

  const consumption = latestReading - initialReading

  return consumption >= 0 ? consumption : null
}

export default function RoomUtilitiesSection({ room }) {
  const queryClient = useQueryClient()
  const [editingMeter, setEditingMeter] = useState(null)

  const metersQuery = useQuery({
    queryKey: utilityKeys.meters(room.id),
    queryFn: () => getUtilityMeters(room.id),
  })

  const saveMeter = useMutation({
    mutationFn: (payload) => (
      editingMeter?.id
        ? updateUtilityMeter(editingMeter.id, payload)
        : createUtilityMeter(room.id, payload)
    ),
    onSuccess: () => {
      setEditingMeter(null)
      queryClient.invalidateQueries({ queryKey: utilityKeys.meters(room.id) })
    },
  })

  const deleteMeter = useMutation({
    mutationFn: deleteUtilityMeter,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: utilityKeys.meters(room.id) }),
  })

  const meters = metersQuery.data?.data?.data || []

  return (
    <section className="mt-6 rounded-xl bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-lg font-semibold text-slate-900">Điện nước</h3>
          <p className="mt-1 text-sm text-slate-500">Theo dõi đồng hồ và chỉ số sử dụng của phòng.</p>
        </div>
        <button
          type="button"
          onClick={() => setEditingMeter({})}
          className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
        >
          Thêm đồng hồ
        </button>
      </div>

      {metersQuery.isPending ? (
        <p className="mt-5 text-sm text-slate-500">Đang tải đồng hồ...</p>
      ) : meters.length === 0 ? (
        <div className="mt-5 rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center">
          <p className="text-sm text-slate-500">Phòng này chưa có đồng hồ điện/nước.</p>
          <button
            type="button"
            onClick={() => setEditingMeter({})}
            className="mt-3 text-sm font-medium text-slate-900 underline underline-offset-4"
          >
            Thêm đồng hồ
          </button>
        </div>
      ) : (
        <div className="mt-5 grid gap-4 md:grid-cols-2">
          {meters.map((meter) => {
            const unit = meter.service?.unit || ''
            const hasLatestReading = meter.latest_reading?.reading_value !== null
              && meter.latest_reading?.reading_value !== undefined
            const consumption = getConsumptionSinceInitialReading(meter)

            return (
              <article key={meter.id} className="rounded-xl border border-slate-200 p-5 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h4 className="text-base font-semibold text-slate-900">{getMeterTitle(meter)}</h4>
                    {meter.service?.name && (
                      <p className="mt-1 truncate text-sm text-slate-500" title={meter.service.name}>
                        {meter.service.name}
                      </p>
                    )}
                  </div>
                  <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                      meter.is_active
                        ? 'bg-emerald-50 text-emerald-700'
                        : 'bg-slate-100 text-slate-600'
                    }`}
                  >
                    {meter.is_active ? 'Đang hoạt động' : 'Ngừng sử dụng'}
                  </span>
                </div>

                <p className="mt-4 text-sm text-slate-500">
                  Mã đồng hồ: <span className="font-medium text-slate-700">{meter.meter_code || 'Chưa cập nhật'}</span>
                </p>

                <dl className="mt-4 grid grid-cols-2 gap-3 border-y border-slate-100 py-4">
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Chỉ số đầu</dt>
                    <dd className="mt-1 text-sm font-semibold text-slate-800">
                      {formatMeterValue(meter.initial_reading, unit)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Chỉ số mới nhất</dt>
                    <dd className="mt-1 text-sm font-semibold text-slate-900">
                      {hasLatestReading
                        ? formatMeterValue(meter.latest_reading.reading_value, unit)
                        : 'Chưa ghi chỉ số'}
                    </dd>
                  </div>
                  {consumption !== null && (
                    <div>
                      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Tiêu thụ từ chỉ số đầu</dt>
                      <dd className="mt-1 text-sm font-semibold text-slate-800">
                        {formatMeterValue(consumption, unit)}
                      </dd>
                    </div>
                  )}
                  {meter.latest_reading?.reading_date && (
                    <div>
                      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Cập nhật</dt>
                      <dd className="mt-1 text-sm font-medium text-slate-700">
                        {formatDate(meter.latest_reading.reading_date)}
                      </dd>
                    </div>
                  )}
                </dl>

                <div className="mt-4 flex flex-wrap gap-2">
                  <Link
                    to={`/utility-meters/${meter.id}`}
                    className="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white transition hover:bg-slate-700"
                  >
                    Xem chỉ số
                  </Link>
                  <button
                    type="button"
                    onClick={() => setEditingMeter(meter)}
                    className="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                  >
                    Sửa
                  </button>
                  <button
                    type="button"
                    onClick={() => window.confirm('Bạn có chắc muốn xóa đồng hồ này?') && deleteMeter.mutate(meter.id)}
                    className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 transition hover:bg-red-50"
                  >
                    Xóa
                  </button>
                </div>
              </article>
            )
          })}
        </div>
      )}

      {editingMeter && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-6">
            <div className="mb-4 flex justify-between">
              <h3 className="font-semibold">{editingMeter.id ? 'Sửa đồng hồ' : 'Thêm đồng hồ'}</h3>
              <button type="button" onClick={() => setEditingMeter(null)} aria-label="Đóng">✕</button>
            </div>
            <UtilityMeterForm
              boardingHouseId={room.boarding_house_id}
              initialValues={editingMeter}
              onSubmit={(payload) => saveMeter.mutateAsync(payload)}
              label="Lưu đồng hồ"
            />
          </div>
        </div>
      )}
    </section>
  )
}
