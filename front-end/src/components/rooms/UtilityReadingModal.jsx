import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { createUtilityReading, getUtilityReadings } from '../../api/utilityReadingApi'
import { roomKeys } from '../../api/roomKeys'
import { utilityKeys } from '../../api/utilityKeys'
import UtilityReadingForm from '../forms/UtilityReadingForm'
import { formatDate, formatUtilityValue } from '../../utils/formatters'
import { getReadingPreview, formatMeterPrice } from '../../utils/utilityReading'

function formatValue(value, unit) {
  const formatted = formatUtilityValue(value)
  return formatted ? `${formatted}${unit ? ` ${unit}` : ''}` : '\u2014'
}

export default function UtilityReadingModal({ roomId, meter, onClose }) {
  const queryClient = useQueryClient()
  const readingsQuery = useQuery({
    queryKey: utilityKeys.readings(meter.id),
    queryFn: () => getUtilityReadings(meter.id),
  })
  const readings = readingsQuery.data?.data?.data || []
  const latestReading = readings.at(-1) || meter.latest_reading
  const unit = meter.service?.unit || ''

  const createMutation = useMutation({
    mutationFn: (payload) => createUtilityReading(meter.id, payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: utilityKeys.readings(meter.id) }),
        queryClient.invalidateQueries({ queryKey: utilityKeys.meter(meter.id) }),
        queryClient.invalidateQueries({ queryKey: utilityKeys.meters(roomId) }),
        queryClient.invalidateQueries({ queryKey: roomKeys.detail(roomId) }),
      ])
      onClose()
    },
  })

  const title = meter.service?.type === 'ELECTRICITY' ? 'Th\u00eam ch\u1ec9 s\u1ed1 \u0111i\u1ec7n' : meter.service?.type === 'WATER' ? 'Th\u00eam ch\u1ec9 s\u1ed1 n\u01b0\u1edbc' : 'Th\u00eam ch\u1ec9 s\u1ed1'
  const context = {
    formatValue: (value) => formatValue(value, unit),
    getPreview: (date, value) => getReadingPreview(readings, meter.initial_reading, date, value),
  }

  return <div role="dialog" aria-modal="true" aria-labelledby="new-reading-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div className="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h3 id="new-reading-title" className="text-lg font-semibold">{title}</h3>
          <p className="mt-1 text-sm text-slate-600">{meter.service?.name || 'D\u1ecbch v\u1ee5 \u0111\u1ed3ng h\u1ed3'}</p>
        </div>
        <button type="button" disabled={createMutation.isPending} onClick={onClose} aria-label="\u0110\u00f3ng" className="text-lg text-slate-500 hover:text-slate-950 disabled:opacity-50">\u2715</button>
      </div>
      <div className="mt-4 grid gap-3 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-2">
        <div><p className="text-slate-500">Ch\u1ec9 s\u1ed1 g\u1ea7n nh\u1ea5t</p><p className="mt-1 font-semibold">{latestReading ? formatValue(latestReading.reading_value, unit) : formatValue(meter.initial_reading, unit)}</p></div>
        <div><p className="text-slate-500">Ng\u00e0y ghi g\u1ea7n nh\u1ea5t</p><p className="mt-1 font-semibold">{latestReading?.reading_date ? formatDate(latestReading.reading_date) : 'Ch\u01b0a c\u00f3 ch\u1ec9 s\u1ed1'}</p></div>
        <div className="sm:col-span-2"><p className="text-slate-500">\u0110\u01a1n gi\u00e1 hi\u1ec7n t\u1ea1i</p><p className="mt-1 font-semibold">{formatMeterPrice(meter.service)}</p></div>
      </div>
      {readingsQuery.isError ? <p className="mt-4 text-sm text-red-600">{getApiErrorMessage(readingsQuery.error)}</p> : <div className="mt-5"><UtilityReadingForm initialValues={{}} onSubmit={(payload) => createMutation.mutateAsync(payload)} label="L\u01b0u ch\u1ec9 s\u1ed1" readingContext={context} /></div>}
    </div>
  </div>
}
