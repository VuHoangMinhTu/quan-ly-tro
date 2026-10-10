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
  return SERVICE_TYPE_LABELS[meter.service?.type] || meter.service?.name || 'D\u1ecbch v\u1ee5'
}

function formatMeterValue(value, unit) {
  const formattedValue = formatUtilityValue(value)
  if (!formattedValue) return '\u2014'

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
        <h3 className="text-lg font-semibold text-slate-900">\u0110i\u1ec7n n\u01b0\u1edbc</h3>
        <p className="mt-1 text-sm text-slate-500">Theo d\u00f5i \u0111\u1ed3ng h\u1ed3 v\u00e0 ghi ch\u1ec9 s\u1ed1 ngay t\u1ea1i ph\u00f2ng.</p>
      </div>
      <button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Th\u00eam \u0111\u1ed3ng h\u1ed3</button>
    </div>

    {!servicesQuery.isPending && !servicesQuery.isError && meterServices.length === 0 && <p className="mt-4 text-sm text-slate-500">Mu\u1ed1n th\u00eam \u0111\u1ed3ng h\u1ed3, h\u00e3y ch\u1ecdn d\u1ecbch v\u1ee5 Theo \u0111\u01a1n v\u1ecb ho\u1eb7c B\u1eadc thang \u1edf ph\u1ea7n D\u1ecbch v\u1ee5 \u00e1p d\u1ee5ng. D\u1ecbch v\u1ee5 C\u1ed1 \u0111\u1ecbnh / Theo ng\u01b0\u1eddi kh\u00f4ng c\u1ea7n \u0111\u1ed3ng h\u1ed3.</p>}
    {servicesQuery.isError && <p className="mt-4 text-sm text-red-600">{getApiErrorMessage(servicesQuery.error)}</p>}
    {deleteMeter.isError && <p role="alert" className="mt-4 text-sm text-red-600">{getApiErrorMessage(deleteMeter.error)}</p>}

    {metersQuery.isPending ? <p className="mt-5 text-sm text-slate-500">\u0110ang t\u1ea3i \u0111\u1ed3ng h\u1ed3...</p>
      : metersQuery.isError ? <p className="mt-5 text-sm text-red-600">{getApiErrorMessage(metersQuery.error)}</p>
        : meters.length === 0 ? <div className="mt-5 rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center"><p className="text-sm text-slate-500">Ph\u00f2ng n\u00e0y ch\u01b0a c\u00f3 \u0111\u1ed3ng h\u1ed3 \u0111i\u1ec7n/n\u01b0\u1edbc.</p><button type="button" disabled={!canAddMeter} onClick={() => setEditingMeter({})} className="mt-3 text-sm font-medium text-slate-900 underline underline-offset-4 disabled:cursor-not-allowed disabled:opacity-50">Th\u00eam \u0111\u1ed3ng h\u1ed3</button></div>
          : <div className="mt-5 grid gap-4 md:grid-cols-2">{meters.map((meter) => {
            const unit = meter.service?.unit || ''
            const hasLatestReading = meter.latest_reading?.reading_value != null
            const consumption = getConsumptionSinceInitialReading(meter)

            return <article key={meter.id} className="rounded-xl border border-slate-200 p-5 shadow-sm">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0"><h4 className="text-base font-semibold text-slate-900">{getMeterTitle(meter)}</h4>{meter.service?.name && <p className="mt-1 truncate text-sm text-slate-500" title={meter.service.name}>{meter.service.name}</p>}</div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${meter.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{meter.is_active ? '\u0110ang ho\u1ea1t \u0111\u1ed9ng' : 'Ng\u1eebng s\u1eed d\u1ee5ng'}</span>
              </div>

              <dl className="mt-4 grid gap-3 border-y border-slate-100 py-4 text-sm">
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">C\u00e1ch t\u00ednh</dt><dd className="text-right font-medium text-slate-800">{BILLING_METHOD_LABELS[meter.service?.billing_method] || meter.service?.billing_method || '\u2014'}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">\u0110\u01a1n gi\u00e1 hi\u1ec7n t\u1ea1i</dt><dd className="text-right font-medium text-slate-800">{formatMeterPrice(meter.service)}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">\u0110\u01a1n v\u1ecb</dt><dd className="text-right font-medium text-slate-800">{unit || "\u0110\u00e3 c\u1eadp nh\u1eadt"}</dd></div>
                <div className="grid grid-cols-2 gap-3"><dt className="text-slate-500">M\u00e3 \u0111\u1ed3ng h\u1ed3</dt><dd className="text-right font-medium text-slate-800">{meter.meter_code || 'Ch\u01b0a c\u1eadp nh\u1eadt'}</dd></div>
              </dl>

              <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-4">
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Ch\u1ec9 s\u1ed1 \u0111\u1ea7u</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{formatMeterValue(meter.initial_reading, unit)}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Ch\u1ec9 s\u1ed1 m\u1edbi nh\u1ea5t</dt><dd className="mt-1 text-sm font-semibold text-slate-900">{hasLatestReading ? formatMeterValue(meter.latest_reading.reading_value, unit) : 'Ch\u01b0a ghi ch\u1ec9 s\u1ed1'}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Ti\u00eau th\u1ee5</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{consumption == null ? '\u2014' : formatMeterValue(consumption, unit)}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">C\u1eadp nh\u1eadt</dt><dd className="mt-1 text-sm font-medium text-slate-700">{meter.latest_reading?.reading_date ? formatDate(meter.latest_reading.reading_date) : 'Ch\u01b0a ghi ch\u1ec9 s\u1ed1'}</dd></div>
              </dl>

              <div className="mt-5 flex flex-wrap gap-2">
                <button type="button" disabled={!meter.is_active} onClick={() => setMeterForNewReading(meter)} className="rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50">+ Th\u00eam ch\u1ec9 s\u1ed1 m\u1edbi</button>
                <Link to={`/utility-meters/${meter.id}`} className="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">L\u1ecbch s\u1eed</Link>
                <button type="button" onClick={() => setEditingMeter(meter)} className="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50">S\u1eeda</button>
                <button type="button" onClick={() => window.confirm('B\u1ea1n c\u00f3 ch\u1eafc mu\u1ed1n x\u00f3a \u0111\u1ed3ng h\u1ed3 n\u00e0y?') && deleteMeter.mutate(meter.id)} className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 transition hover:bg-red-50">X\u00f3a</button>
              </div>
            </article>
          })}</div>}

    {editingMeter && <div role="dialog" aria-modal="true" aria-labelledby="meter-dialog-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-white p-6"><div className="mb-4 flex justify-between"><h3 id="meter-dialog-title" className="font-semibold">{editingMeter.id ? 'S\u1eeda \u0111\u1ed3ng h\u1ed3' : 'Th\u00eam \u0111\u1ed3ng h\u1ed3'}</h3><button type="button" onClick={() => setEditingMeter(null)} aria-label="\u0110\u00f3ng">\u2715</button></div><UtilityMeterForm key={editingMeter.id || `new-${room.id}`} roomId={room.id} initialValues={editingMeter} onSubmit={(payload) => saveMeter.mutateAsync(payload)} label="L\u01b0u \u0111\u1ed3ng h\u1ed3" /></div></div>}
    {meterForNewReading && <UtilityReadingModal roomId={room.id} meter={meterForNewReading} onClose={() => setMeterForNewReading(null)} />}
  </section>
}
