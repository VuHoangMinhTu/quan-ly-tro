import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { getUtilityMeter } from '../../api/utilityMeterApi'
import {
  createUtilityReading,
  deleteUtilityReading,
  getUtilityReadings,
  updateUtilityReading,
} from '../../api/utilityReadingApi'
import { getServicePriceTiers } from '../../api/servicePriceTierApi'
import { serviceKeys } from '../../api/serviceKeys'
import { utilityKeys } from '../../api/utilityKeys'
import UtilityReadingForm from '../../components/forms/UtilityReadingForm'
import { formatCurrency, formatDate, formatUtilityValue } from '../../utils/formatters'
import { BILLING_METHOD_LABELS, SERVICE_TYPE_LABELS } from '../../utils/service'

function formatQuantity(value, unit) {
  const formattedValue = formatUtilityValue(value)

  return formattedValue ? `${formattedValue}${unit ? ` ${unit}` : ''}` : '—'
}

function TierRange({ tier, unit }) {
  if (tier.to_quantity === null || tier.to_quantity === undefined) {
    return `Trên ${formatUtilityValue(tier.from_quantity)}${unit ? ` ${unit}` : ''}`
  }

  return `${formatUtilityValue(tier.from_quantity)} - ${formatUtilityValue(tier.to_quantity)}${unit ? ` ${unit}` : ''}`
}

function ServicePricing({ service, tiersQuery }) {
  const unit = service?.unit || ''
  const isTiered = service?.billing_method === 'TIERED'
  const tiers = tiersQuery.data?.data?.data || []

  return (
    <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Loại</p>
          <p className="mt-1 font-semibold text-slate-900">{SERVICE_TYPE_LABELS[service?.type] || service?.type || '—'}</p>
        </div>
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Cách tính</p>
          <p className="mt-1 font-semibold text-slate-900">{BILLING_METHOD_LABELS[service?.billing_method] || service?.billing_method || '—'}</p>
        </div>
        {!isTiered && (
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Đơn giá hiện tại</p>
            <p className="mt-1 font-semibold text-slate-900">
              {service?.base_price === null || service?.base_price === undefined
                ? 'Chưa cập nhật'
                : `${formatCurrency(service.base_price)}${unit ? ` / ${unit}` : ''}`}
            </p>
          </div>
        )}
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Đơn vị</p>
          <p className="mt-1 font-semibold text-slate-900">{unit || 'Chưa cập nhật'}</p>
        </div>
      </div>

      {isTiered && (
        <div className="mt-4 border-t border-slate-200 pt-4">
          <p className="text-sm font-semibold text-slate-900">Bảng giá theo bậc</p>
          {tiersQuery.isPending ? (
            <p className="mt-2 text-sm text-slate-500">Đang tải bảng giá...</p>
          ) : tiers.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500">Chưa có bậc giá được cấu hình.</p>
          ) : (
            <div className="mt-3 grid gap-2 sm:grid-cols-2">
              {tiers.map((tier) => (
                <div key={tier.id} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                  <p className="font-medium text-slate-800">{TierRange({ tier, unit })}</p>
                  <p className="mt-1 text-slate-600">{formatCurrency(tier.unit_price)}{unit ? ` / ${unit}` : ''}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default function UtilityMeterDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const [editingReading, setEditingReading] = useState(null)

  const meterQuery = useQuery({
    queryKey: utilityKeys.meter(id),
    queryFn: () => getUtilityMeter(id),
  })
  const meter = meterQuery.data?.data?.data
  const serviceId = meter?.service?.id
  const tiersQuery = useQuery({
    queryKey: serviceKeys.tiers(serviceId || 'unavailable'),
    queryFn: () => getServicePriceTiers(serviceId),
    enabled: Boolean(serviceId) && meter?.service?.billing_method === 'TIERED',
  })
  const readingsQuery = useQuery({
    queryKey: utilityKeys.readings(id),
    queryFn: () => getUtilityReadings(id),
  })

  const saveReading = useMutation({
    mutationFn: (payload) => (
      editingReading?.id
        ? updateUtilityReading(editingReading.id, payload)
        : createUtilityReading(id, payload)
    ),
    onSuccess: () => {
      setEditingReading(null)
      queryClient.invalidateQueries({ queryKey: utilityKeys.readings(id) })
      queryClient.invalidateQueries({ queryKey: utilityKeys.meter(id) })
    },
  })
  const deleteReading = useMutation({
    mutationFn: deleteUtilityReading,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: utilityKeys.readings(id) }),
  })

  if (meterQuery.isPending) return <p>Đang tải đồng hồ...</p>
  if (meterQuery.isError || !meter) return <p className="text-red-600">Không tìm thấy đồng hồ.</p>

  const readings = readingsQuery.data?.data?.data || []
  const unit = meter.service?.unit || ''

  return (
    <section>
      <Link to={`/rooms/${meter.room_id}`} className="text-sm text-slate-600">← Quay lại phòng</Link>

      <div className="my-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-slate-900">{meter.service?.name || 'Đồng hồ dịch vụ'}</h2>
          <p className="mt-1 text-sm text-slate-600">
            Mã đồng hồ: {meter.meter_code || 'Chưa cập nhật'} · Chỉ số đầu: {formatQuantity(meter.initial_reading, unit)}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setEditingReading({})}
          className="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white"
        >
          Thêm chỉ số
        </button>
      </div>

      <ServicePricing service={meter.service} tiersQuery={tiersQuery} />

      <div className="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50">
            <tr>
              {['Ngày ghi', 'Chỉ số công tơ hiện tại', 'Mức tiêu thụ', 'Ghi chú', ''].map((label) => (
                <th key={label} className="px-4 py-3 text-left font-medium text-slate-600">{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {readings.map((reading, index) => {
              const previousReading = index ? Number(readings[index - 1].reading_value) : Number(meter.initial_reading)
              const consumption = Number(reading.reading_value) - previousReading

              return (
                <tr key={reading.id} className="border-t border-slate-100">
                  <td className="px-4 py-3">{formatDate(reading.reading_date)}</td>
                  <td className="px-4 py-3 font-medium">{formatQuantity(reading.reading_value, unit)}</td>
                  <td className="px-4 py-3">{formatQuantity(consumption, unit)}</td>
                  <td className="px-4 py-3 text-slate-600">{reading.note || '—'}</td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <button type="button" onClick={() => setEditingReading(reading)} className="mr-3 text-slate-700 underline underline-offset-2">Sửa</button>
                    <button
                      type="button"
                      onClick={() => window.confirm('Bạn có chắc muốn xóa chỉ số này?') && deleteReading.mutate(reading.id)}
                      className="text-red-700 underline underline-offset-2"
                    >
                      Xóa
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        {readings.length === 0 && <p className="p-6 text-center text-slate-500">Chưa có chỉ số được ghi.</p>}
      </div>

      {editingReading && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-6">
            <div className="mb-4 flex justify-between">
              <h3 className="font-semibold">{editingReading.id ? 'Sửa chỉ số' : 'Thêm chỉ số'}</h3>
              <button type="button" onClick={() => setEditingReading(null)} aria-label="Đóng">✕</button>
            </div>
            <UtilityReadingForm
              initialValues={editingReading}
              onSubmit={(payload) => saveReading.mutateAsync(payload)}
              label="Lưu chỉ số"
            />
          </div>
        </div>
      )}
    </section>
  )
}
