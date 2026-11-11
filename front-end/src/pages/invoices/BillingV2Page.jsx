import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { confirmBillingPeriodBoundaries, correctBillingPeriodBoundaries, confirmBillingCheckout, confirmBillingSettlement, generateBillingV2, generateRoomMonthlyInvoice, getBillingV2, getBillingV2Status, recordBillingDeposit } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { formatCurrency } from '../../utils/formatters'
import { createUtilityMeter } from '../../api/utilityMeterApi'
import { utilityKeys } from '../../api/utilityKeys'
import UtilityMeterForm from '../../components/forms/UtilityMeterForm'
import { resolveMeterCycleDefault, suggestedMeterUsageWindow } from './meterCycleDefaults.mjs'
import { buildMeterPeriodPayload } from './meterPeriodPayload.mjs'

// Presentation-only classification. All billing mutations still use the full meters array.
const billingMeterKind = meter => {
  if (meter.service?.type === 'ELECTRICITY' || /điện/i.test(meter.service?.name || '')) return 'electric'
  if (meter.service?.type === 'WATER' || /nước/i.test(meter.service?.name || '')) return 'water'
  return 'other'
}

const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 block max-w-full box-border'
const buttonClass = 'min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-white disabled:opacity-50'
const at = value => value.replace('T', ' ') + (value.length === 16 ? ':00' : '')
const suggestedUsageWindow = month => {
  const [year, monthNumber] = month.split('-').map(Number)
  const end = new Date(year, monthNumber, 1)
  const format = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T00:00`
  return { start: `${month}-01T00:00`, end: format(end) }
}

export default function BillingV2Page() {
  const { roomId } = useParams()
  const [period, setPeriod] = useState(new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).slice(0, 7))
  const [usageWindow, setUsageWindow] = useState(() => suggestedUsageWindow(new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).slice(0, 7)))
  const [meterDrafts, setMeterDrafts] = useState({})
  const [meterEdits, setMeterEdits] = useState({})
  const [meterSetup, setMeterSetup] = useState(false)
  const [selectedBillingMeterKind, setSelectedBillingMeterKind] = useState('electric')
  const [showCorrectionHelp, setShowCorrectionHelp] = useState({})
  const [checkout, setCheckout] = useState({ contract: '', reason: 'MUTUAL_EARLY_END', observed: '', readings: {}, confirmNoHeld: false })
  const [deposit, setDeposit] = useState({ contract: '', event: 'RECEIVED', amount: '', observed: '', key: crypto.randomUUID() })
  const [notice, setNotice] = useState('')
  const [invoiceNotice, setInvoiceNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const client = useQueryClient()
  const statusQuery = useQuery({ queryKey: ['billing-v2-status'], queryFn: getBillingV2Status, staleTime: 30_000 })
  const enabled = statusQuery.data?.data?.data?.enabled === true
  const query = useQuery({ queryKey: ['billing-v2', roomId, period], queryFn: () => getBillingV2(roomId, period + '-01') })
  const data = query.data?.data?.data
  const mutation = useMutation({ mutationFn: action => action() })
  const meterMutation = useMutation({
    mutationFn: payload => createUtilityMeter(roomId, payload),
    onSuccess: async () => {
      setMeterSetup(false)
      await Promise.all([
        client.invalidateQueries({ queryKey: ['billing-v2', roomId] }),
        client.invalidateQueries({ queryKey: utilityKeys.meters(roomId) }),
      ])
    },
  })
  const run = async action => {
    if (pending.current) return
    pending.current = true; setBusy(true); setNotice('')
    try {
      await mutation.mutateAsync(action)
      setNotice('Đã ghi nhận thành công.')
      await client.invalidateQueries({ queryKey: ['billing-v2', roomId] })
      await client.invalidateQueries({ queryKey: utilityKeys.meters(roomId) })
      await Promise.all(['invoices', 'invoice', 'room', 'rooms', 'contract', 'contracts', 'contract-context', 'dashboard'].map(key => client.invalidateQueries({ queryKey: [key] })))
      return true
    } catch { /* API interceptor and inline error retain all form values for retry. */ return false }
    finally { pending.current = false; setBusy(false) }
  }
  const contracts = data?.contracts || []
  const meters = data?.meters || []
  const selectedBillingKey = meters.some(
    meter => billingMeterKind(meter) === selectedBillingMeterKind
  )
    ? selectedBillingMeterKind
    : (meters[0] ? billingMeterKind(meters[0]) : 'electric')
  const hasElectricMeter = meters.some(m => m.service?.type === 'ELECTRICITY')
  const hasWaterMeter = meters.some(m => m.service?.type === 'WATER')
  const missingMeterTypes = ['ELECTRICITY', 'WATER'].filter(type =>
    type === 'ELECTRICITY' ? !hasElectricMeter : !hasWaterMeter)
  const showInitialMeterSetup = missingMeterTypes.length > 0
  const checkpoints = data?.checkpoints || []
  const legacyReadings = data?.legacy_readings || []
  const historyConflict = legacyReadings.find(reading => checkpoints.some(point => {
    if (Number(reading.utility_meter_id) !== Number(point.utility_meter_id)) return false
    const date = point.observed_at.slice(0, 10)
    const diff = Number(point.reading_value) - Number(reading.reading_value)
    return (date > reading.reading_date && diff < 0)
      || (date < reading.reading_date && diff > 0)
      || (date === reading.reading_date && diff !== 0)
  }))
  const suggestStart = (meter, time) => {
    const targetTime = at(time)
    // A V1 reading is dated only (no time of day). Same-day V1 readings are
    // deliberately NOT treated as known before a V2 timestamp.
    const legacy = legacyReadings.filter(row => Number(row.utility_meter_id) === Number(meter.id)
      && row.reading_date < targetTime.slice(0, 10))
      .sort((a, b) => a.reading_date.localeCompare(b.reading_date) || a.id - b.id)
      .at(-1)
    const v2 = checkpoints.filter(row => Number(row.utility_meter_id) === Number(meter.id)
      && row.observed_at < targetTime)
      .sort((a, b) => a.observed_at.localeCompare(b.observed_at) || a.id - b.id)
      .at(-1)
    if (v2 && (!legacy || v2.observed_at.slice(0, 10) >= legacy.reading_date)) {
      return { value: String(v2.reading_value), source: `Mốc V2 đã xác nhận lúc ${v2.observed_at}` }
    }
    if (legacy) return { value: String(legacy.reading_value), source: `Lịch sử V1 ngày ${legacy.reading_date} — cần đối chiếu trước khi xác nhận` }
    if (meter.initial_reading != null) return { value: String(meter.initial_reading), source: 'Chỉ số gốc khi đăng ký công tơ — chưa phải mốc V2 được xác nhận theo ngày' }
    return { value: '', source: 'Chưa có số tham chiếu trước thời điểm bắt đầu' }
  }
  const periodHistory = data?.checkpoint_history || []
  const suggestedWindow = suggestedMeterUsageWindow(period, contracts)
  const meterDefault = meter => resolveMeterCycleDefault({ meter, period, usageWindow: suggestedWindow, data })
  const meterCycle = meter => ({ ...meterDefault(meter), ...meterDrafts[meter.id] })
  const setMeterCycle = (meter, changes) => setMeterDrafts(previous => ({
    ...previous, [meter.id]: { ...meterDefault(meter), ...previous[meter.id], ...changes },
  }))
  const savedPoint = (meter, time) => checkpoints.find(point => Number(point.utility_meter_id) === Number(meter.id) && point.kind === 'PERIOD_BOUNDARY' && point.observed_at === at(time))
  const missingData = meters.some(meter => {
    const cycle = meterCycle(meter)
    return !savedPoint(meter, cycle.start) || !savedPoint(meter, cycle.end)
  })
  const calculateCycle = async () => {
    if (meters.length === 0 || missingData || historyConflict || Object.keys(meterEdits).length > 0 || suggestedWindow.ambiguous) return
    setInvoiceNotice('')
    try {
      const { meterPeriods, start, end } = buildMeterPeriodPayload(meters, meterCycle)
      const settled = await run(() => generateBillingV2(roomId, period + '-01', start, end, meterPeriods))
      if (!settled) return
      // The meter endpoint creates utility obligations only. Compose the FULL
      // monthly DRAFT for this room without issuing or touching other rooms.
      try {
        const response = await generateRoomMonthlyInvoice(roomId, {
          billing_period: period + '-01', usage_start_at: start, usage_end_at: end, confirmations: [],
        })
        const result = response.data?.data?.[0]
        if (result?.status === 'CREATED') {
          setInvoiceNotice('Đã tổng hợp tiền phòng, dịch vụ và điện nước vào hóa đơn nháp của phòng này.')
        } else if (result?.status === 'EXISTING') {
          setInvoiceNotice('Hóa đơn tháng đã tồn tại. Hệ thống giữ nguyên, không ghi đè; nếu cần hãy cập nhật bản nháp từ trang hóa đơn.')
        } else {
          const reason = Object.values(result?.errors || {}).flat().join(' ')
          setInvoiceNotice(`Đã quyết toán điện nước nhưng chưa thể tổng hợp hóa đơn tháng. ${reason || 'Hãy kiểm tra hợp đồng và khoản thu trong mục Hóa đơn.'}`)
        }
        await client.invalidateQueries({ queryKey: ['billing-v2', roomId] })
        await client.invalidateQueries({ queryKey: ['invoices'] })
      } catch (error) {
        setInvoiceNotice(`Đã quyết toán điện nước nhưng chưa tổng hợp hóa đơn tháng: ${getApiErrorMessage(error)}`)
      }
    } catch (error) {
      setNotice(error.message)
    }
  }
  const contractOptions = <><option value="">Chọn hợp đồng</option>{contracts.map(contract => <option key={contract.id} value={contract.id}>{contract.contract_code} · {contract.status}</option>)}</>
  if (query.isPending) return <p role="status">Đang tải quyết toán điện nước…</p>
  if (query.isError) return <p role="alert">{getApiErrorMessage(query.error)}</p>
  return <section className="min-w-0 space-y-6">
    <Link to={`/rooms/${roomId}`} className="inline-flex min-h-11 items-center text-sm underline">← Quay lại phòng</Link>
    <header className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <p className="text-xs font-semibold uppercase tracking-wide text-teal-700">Phòng {data.room.room_code} · Billing V2</p>
      <h2 className="mt-1 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Quyết toán điện và nước</h2>
      <p className="mt-2 text-sm leading-relaxed text-slate-600">Điện và nước được ghi theo công tơ, ngày giờ riêng. Các khoản khác trong hóa đơn không được thay đổi tại bước này.</p>
      <nav aria-label="Chuyển nhanh đến các thao tác Billing V2" className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <a href="#billing-v2-meters" className="inline-flex min-h-11 items-center justify-center rounded-xl border border-teal-100 bg-teal-50 px-2 text-center text-xs font-semibold text-teal-800 active:bg-teal-100">Ghi chỉ số</a>
        <a href="#billing-v2-calculate" className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-2 text-center text-xs font-semibold text-slate-700 active:bg-slate-100">Tính khoản thu</a>
        <a href="#billing-v2-checkout" className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-2 text-center text-xs font-semibold text-slate-700 active:bg-slate-100">Trả phòng</a>
        <a href="#billing-v2-invoices" className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-2 text-center text-xs font-semibold text-slate-700 active:bg-slate-100">Hóa đơn</a>
      </nav>
    </header>
    <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Chỉ dùng sau khi đối soát mốc bàn giao và kỳ công tơ. Kỳ có hóa đơn cũ hoặc xung đột bảng giá sẽ bị chặn. Giá bán phân bổ cho phần chủ trọ chịu không phải giá vốn thực tế.</p>
    {statusQuery.isError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-red-800">Không xác minh được quyền thao tác V2. Chế độ ghi dữ liệu đang bị khóa an toàn.</p>}
    {statusQuery.isSuccess && !enabled && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-950">Billing V2 đang tạm khóa. Bằng chứng, phân bổ và hóa đơn hiện có vẫn được xem; các thao tác ghi và phát hành đang tắt.</p>}
    {notice && <p role="status" className="rounded-lg bg-green-50 p-3 text-green-800">{notice}</p>}
    {invoiceNotice && <p role="status" className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950">{invoiceNotice} <Link className="underline" to={`/rooms/${roomId}/invoices`}>Xem hóa đơn của phòng</Link></p>}
    {mutation.isError && <p role="alert" className={`rounded-lg p-3 ${mutation.error?.billingV2Message ? 'bg-amber-50 text-amber-900' : 'bg-red-50 text-red-700'}`}>{mutation.error?.billingV2Message || getApiErrorMessage(mutation.error)}</p>}
    <fieldset disabled={busy || !enabled} className="min-w-0 space-y-6 disabled:opacity-70">
      <section id="billing-v2-meters" className="min-w-0 scroll-mt-24 space-y-4 rounded-2xl border border-slate-200 bg-white p-3.5 sm:p-5" aria-label="Chốt điện nước theo từng công tơ">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold">Ghi chỉ số đầu kỳ và cuối kỳ</h3>
            <p className="mt-1 text-sm text-slate-600">Điện và nước có thể đo vào các ngày giờ khác nhau. Kết quả bên dưới cập nhật ngay, nhưng chưa phải số tiền đã quyết toán.</p>
          </div>
          <label className="block w-full text-sm sm:w-auto sm:min-w-48">Tháng hóa đơn
            <input className={inputClass} type="month" required value={period} onChange={event => {
              setPeriod(event.target.value); setUsageWindow(suggestedUsageWindow(event.target.value)); setMeterDrafts({}); setMeterEdits({}); setInvoiceNotice('')
            }} />
          </label>
        </div>
        <div className="flex min-w-0 w-full flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-start sm:justify-between">
          <p className="min-w-0 w-full text-sm leading-6 text-slate-600 sm:flex-1">Mỗi đồng hồ vật lý chỉ đăng ký một lần. Khi đã có điện và nước, những tháng sau chỉ cần ghi mốc mới; không tạo lại đồng hồ.</p>
          {showInitialMeterSetup && <button type="button" className="inline-flex min-h-11 w-full min-w-0 items-center justify-center whitespace-normal break-words rounded-lg border border-slate-300 bg-white px-3 py-2 text-center text-sm font-semibold leading-5 text-slate-800 active:bg-slate-100 sm:w-auto sm:shrink-0" disabled={busy || meterMutation.isPending} onClick={() => setMeterSetup(true)}>
            {meters.length === 0 ? '+ Thiết lập đồng hồ điện/nước' : `+ Bổ sung đồng hồ ${missingMeterTypes.map(type => type === 'ELECTRICITY' ? 'điện' : 'nước').join(', ')}`}
          </button>}
          {!showInitialMeterSetup && <p className="min-w-0 w-full break-words text-sm font-semibold leading-6 text-emerald-700 sm:w-auto sm:max-w-48">Đã có đồng hồ điện và nước.</p>}
        </div>
        {meterSetup && <div role="dialog" aria-modal="true" aria-labelledby="v2-meter-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
            <div className="mb-4 flex items-start justify-between gap-3"><div><h4 id="v2-meter-title" className="text-lg font-semibold">Đăng ký đồng hồ vật lý</h4><p className="text-sm text-slate-600">Nhập chỉ số gốc của đồng hồ một lần; các lần ghi/chốt kỳ thực hiện ngay tại V2.</p></div><button type="button" aria-label="Đóng" className="rounded border px-3 py-2" onClick={() => setMeterSetup(false)}>Đóng</button></div>
            <UtilityMeterForm roomId={roomId} allowedServiceTypes={missingMeterTypes} onSubmit={payload => meterMutation.mutateAsync(payload)} label="Thêm đồng hồ" />
          </div>
        </div>}
        {historyConflict && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">Lịch sử V1 và mốc V2 đang mâu thuẫn/không rõ giờ đo trên cùng công tơ (ví dụ V1 ngày {historyConflict.reading_date} có số {historyConflict.reading_value}). Tạm khóa tính tiền: hãy đối chiếu lịch sử chỉ số V1 và V2 trước khi tạo hóa đơn. Không sửa số chỉ để qua lỗi.</p>}
        <div className="grid grid-cols-2 gap-2" aria-label="Các nhóm đồng hồ">
          {[
            { kind: 'electric', label: 'Điện', emoji: '⚡', count: meters.filter(meter => billingMeterKind(meter) === 'electric').length },
            { kind: 'water', label: 'Nước', emoji: '💧', count: meters.filter(meter => billingMeterKind(meter) === 'water').length },
          ].map(group => group.count > 0 ? (
            <button
              key={group.kind}
              type="button"
              aria-pressed={selectedBillingKey === group.kind}
              onClick={() => setSelectedBillingMeterKind(group.kind)}
              className={selectedBillingKey === group.kind
                ? 'flex min-h-16 min-w-0 flex-col justify-center rounded-xl border border-teal-500 bg-teal-50 px-3 text-left text-sm font-semibold text-teal-900 ring-1 ring-teal-200 active:bg-teal-100'
                : 'flex min-h-16 min-w-0 flex-col justify-center rounded-xl border border-slate-200 bg-white px-3 text-left text-sm font-semibold text-slate-900 active:bg-slate-100'}
            >
              <span>{group.emoji} {group.label}</span><span className="mt-0.5 text-xs font-normal text-slate-600">{group.count} đồng hồ · Xem chỉ số</span>
            </button>
          ) : (
            <div key={group.kind} className="flex min-h-16 flex-col justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-600">
              <span>{group.emoji} {group.label}</span><span className="mt-0.5 text-xs font-normal">Chưa có đồng hồ</span>
            </div>
          ))}
        </div>
        {meters.length === 0 && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">Chưa có công tơ hoạt động. Hãy đăng ký đồng hồ ở đây nếu dịch vụ điện/nước tính theo chỉ số. Dịch vụ đã bao gồm hoặc theo người không cần công tơ riêng.</p>}
        <div className="grid min-w-0 gap-3 2xl:grid-cols-2">
        {[
          { key: 'electric', name: 'Điện', emoji: '⚡', description: 'Kỳ ghi điện và chỉ số kWh', meters: meters.filter(meter => billingMeterKind(meter) === 'electric') },
          { key: 'water', name: 'Nước', emoji: '💧', description: 'Kỳ ghi nước và chỉ số m³', meters: meters.filter(meter => billingMeterKind(meter) === 'water') },
          { key: 'other', name: 'Đồng hồ khác', emoji: '▦', description: 'Công tơ áp dụng cho phòng', meters: meters.filter(meter => billingMeterKind(meter) === 'other') },
        ].filter(group => group.meters.length > 0).map(group => (
          <section
              key={group.key}
              id={'billing-v2-' + group.key}
              className={
                selectedBillingKey === group.key ||
                group.key === 'other' ||
                !enabled
                  ? 'min-w-0 scroll-mt-24 space-y-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 sm:p-4'
                  : 'hidden min-w-0 scroll-mt-24 space-y-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 sm:p-4 2xl:block'
              }
            >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="min-w-0"><h4 className="font-bold text-slate-900">{group.emoji} {group.name}</h4><p className="mt-1 text-xs text-slate-600">{group.description}</p></div>
              <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{group.meters.length} đồng hồ</span>
            </div>
            {group.meters.map(meter => {
          const cycle = meterCycle(meter)
          const edit = meterEdits[meter.id]
          const startPoint = savedPoint(meter, cycle.start)
          const endPoint = savedPoint(meter, cycle.end)
          const suggestion = suggestStart(meter, cycle.start)
          const matchingLegacyEnd = legacyReadings.find(row => Number(row.utility_meter_id) === Number(meter.id)
            && row.reading_date === cycle.end.slice(0, 10))
          const startReading = cycle.startReading !== undefined ? cycle.startReading : (startPoint?.reading_value ?? suggestion.value)
          const endReading = cycle.endReading !== undefined ? cycle.endReading : (endPoint?.reading_value ?? '')
          const valuesValid = startReading !== '' && endReading !== '' && Number.isFinite(Number(startReading)) && Number.isFinite(Number(endReading)) && Number(startReading) >= 0 && Number(endReading) >= Number(startReading)
          const datesValid = Boolean(cycle.start && cycle.end && at(cycle.start) < at(cycle.end))
          const savedMatches = startPoint && endPoint && Number(startPoint.reading_value) === Number(startReading) && Number(endPoint.reading_value) === Number(endReading)
          const editingSaved = Boolean(edit)
          const usage = valuesValid && datesValid ? Number(endReading) - Number(startReading) : null
          const confirmedCycle = (data?.periods || []).find(item => Number(item.utility_meter_id) === Number(meter.id))
          const relevantHistory = periodHistory.filter(item => Number(item.utility_meter_id) === Number(meter.id) && item.kind === 'PERIOD_BOUNDARY').slice(0, 15)
          const correctionHistory = (data?.checkpoint_corrections || []).filter(item => Number(item.utility_meter_id) === Number(meter.id)).slice(0, 10)
          // Only offer a history-based correction when the source pair is unambiguous.
          // The server still validates checkpoint IDs, financial locks and chronological consistency.
          const uniqueBoundaries = checkpoints.filter(item => Number(item.utility_meter_id) === Number(meter.id) && item.kind === 'PERIOD_BOUNDARY')
            .slice().sort((a, b) => a.observed_at.localeCompare(b.observed_at) || a.id - b.id)
          const canRecoverPair = uniqueBoundaries.length === 2 && !editingSaved && !savedMatches
          const kind = billingMeterKind(meter)
          const isElectric = kind === 'electric'
          const unit = meter.service?.unit || (isElectric ? 'kWh' : kind === 'water' ? 'm³' : 'đơn vị')
          return <article key={meter.id} className="min-w-0 space-y-4 rounded-xl border border-slate-200 bg-white p-3.5 sm:p-4" aria-label={`Công tơ ${meter.meter_code || meter.id}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h4 className="break-words font-semibold">{isElectric ? '⚡' : kind === 'water' ? '💧' : '▦'} {meter.service?.name || 'Công tơ'} · {meter.meter_code || '#' + meter.id}</h4>
              <span className={`rounded-full px-3 py-1 text-xs font-medium ${savedMatches ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'}`}>{savedMatches ? 'Đã lưu đủ hai mốc' : 'Chưa lưu đủ mốc đã nhập'}</span>
            </div>
            <div className="grid min-w-0 w-full grid-cols-1 gap-3 md:grid-cols-2">
              <div className="min-w-0 w-full space-y-3 rounded-xl border border-slate-200 bg-white p-2.5 sm:p-3">
                <h5 className="font-medium">Mốc đầu kỳ</h5>
                <label className="block text-sm">Ngày giờ thực tế đọc công tơ
                  <input aria-label={`Đầu kỳ ${meter.meter_code || meter.id}`} type="datetime-local" step="1" required className={inputClass} value={cycle.start} disabled={Boolean(savedMatches) && !editingSaved} onChange={event => setMeterCycle(meter, { start: event.target.value, startReading: editingSaved ? startReading : undefined })} />
                </label>
                <label className="block text-sm">Chỉ số đầu ({unit})
                  <input aria-label={`Chỉ số đầu ${meter.meter_code || meter.id}`} type="number" min="0" step="0.01" className={inputClass} value={startReading} disabled={Boolean(savedMatches) && !editingSaved} onChange={event => setMeterCycle(meter, { startReading: event.target.value })}/>
                </label>
                <p className="text-xs text-slate-600">{startPoint ? `Đã xác nhận: ${startPoint.reading_value} ${unit} · ${startPoint.observed_at}` : `Đề xuất: ${suggestion.source}. Kiểm tra thực tế trước khi bấm lưu.`}</p>
              </div>
              <div className="min-w-0 w-full space-y-3 rounded-xl border border-slate-200 bg-white p-2.5 sm:p-3">
                <h5 className="font-medium">Mốc cuối kỳ</h5>
                <label className="block text-sm">Ngày giờ thực tế đọc công tơ
                  <input aria-label={`Cuối kỳ ${meter.meter_code || meter.id}`} type="datetime-local" step="1" required className={inputClass} value={cycle.end} disabled={Boolean(savedMatches) && !editingSaved} onChange={event => setMeterCycle(meter, { end: event.target.value, endReading: editingSaved ? endReading : undefined })}/>
                </label>
                <label className="block text-sm">Chỉ số cuối ({unit})
                  <input aria-label={`Chỉ số cuối ${meter.meter_code || meter.id}`} type="number" min="0" step="0.01" className={inputClass} value={endReading} disabled={Boolean(savedMatches) && !editingSaved} onChange={event => setMeterCycle(meter, { endReading: event.target.value })}/>
                </label>
                <p className="text-xs text-slate-600">{endPoint ? `Đã xác nhận: ${endPoint.reading_value} ${unit} · ${endPoint.observed_at}` : 'Chưa có mốc V2 được xác nhận tại ngày giờ này.'}</p>
                {!endPoint && matchingLegacyEnd && <div className="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900"><p>V1 đã ghi {matchingLegacyEnd.reading_value} {unit} ngày {matchingLegacyEnd.reading_date}, nhưng không có giờ đo. Chỉ dùng lại nếu đúng thời điểm chốt thực tế.</p><button type="button" className="mt-2 rounded border border-amber-400 px-2 py-1 font-medium" onClick={() => setMeterCycle(meter, { endReading: String(matchingLegacyEnd.reading_value) })}>Dùng giá trị V1 này để kiểm tra</button></div>}
              </div>
            </div>
            <div className="flex flex-col gap-3 rounded-lg border border-teal-100 bg-teal-50/70 p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
              <div><p className="text-sm text-slate-600">Sản lượng tạm tính</p><strong className="text-xl">{usage === null ? 'Chưa hợp lệ' : `${usage.toLocaleString('vi-VN')} ${unit}`}</strong></div>
              {!editingSaved && <button type="button" className={buttonClass} disabled={!enabled || busy || !datesValid || !valuesValid || Boolean(savedMatches)} onClick={() => run(() => confirmBillingPeriodBoundaries(meter.id, {
                start_observed_at: at(cycle.start), end_observed_at: at(cycle.end), start_reading_value: startReading, end_reading_value: endReading,
              }))}>{savedMatches ? 'Đã xác nhận' : 'Lưu hai mốc công tơ'}</button>}
            </div>
            {savedMatches && !editingSaved && <button type="button" disabled={!enabled || busy} className="min-h-11 rounded-lg border border-slate-400 bg-white px-4 py-2 font-medium text-slate-900" onClick={() => {
              setMeterDrafts(previous => ({ ...previous, [meter.id]: { ...cycle, startReading: String(startPoint.reading_value), endReading: String(endPoint.reading_value) } }))
              setMeterEdits(previous => ({ ...previous, [meter.id]: { startId: startPoint.id, endId: endPoint.id, reason: '', key: crypto.randomUUID(), original: { start: cycle.start, end: cycle.end, startReading: String(startPoint.reading_value), endReading: String(endPoint.reading_value) } } }))
            }}>✏️ Sửa ngày giờ / chỉ số đã xác nhận</button>}
            {editingSaved && <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
              <p className="text-sm font-semibold text-amber-950">Đính chính mốc công tơ — dữ liệu cũ được lưu trong lịch sử.</p>
              <label className="block text-sm font-medium">Lý do chỉnh sửa (bắt buộc)
                <textarea rows={2} maxLength={1000} className={inputClass} value={edit.reason} onChange={event => setMeterEdits(previous => ({ ...previous, [meter.id]: { ...edit, reason: event.target.value } }))} placeholder="Ví dụ: Nhập nhầm chỉ số cuối khi ghi công tơ" />
              </label>
              <p className="text-xs text-amber-900">Nếu kỳ điện nước đã tính vào hóa đơn nháp, hãy vào mục cập nhật tính toán nháp để xem trước và xác nhận tính lại. Hóa đơn đã phát hành/thanh toán không thể sửa trực tiếp.</p>
              <div className="flex flex-wrap gap-2">
                <button type="button" disabled={!enabled || busy || !datesValid || !valuesValid || edit.reason.trim().length < 5} className={buttonClass} onClick={async () => {
                  const ok = await run(() => correctBillingPeriodBoundaries(meter.id, {
                    start_checkpoint_id: edit.startId, end_checkpoint_id: edit.endId,
                    start_observed_at: at(cycle.start), end_observed_at: at(cycle.end),
                    start_reading_value: startReading, end_reading_value: endReading,
                    reason: edit.reason.trim(), request_key: edit.key,
                  }))
                  if (ok) setMeterEdits(previous => { const next = { ...previous }; delete next[meter.id]; return next })
                }}>Lưu chỉnh sửa có lịch sử</button>
                <button type="button" disabled={busy} className="min-h-11 rounded-lg border border-slate-400 bg-white px-4 py-2" onClick={() => {
                  setMeterDrafts(previous => ({ ...previous, [meter.id]: edit.original }))
                  setMeterEdits(previous => { const next = { ...previous }; delete next[meter.id]; return next })
                }}>Hủy chỉnh sửa</button>
              </div>
            </div>}
            {canRecoverPair && <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 space-y-2 text-sm">
              <p className="font-medium">Đã tìm thấy đúng 2 mốc V2 của đồng hồ này, nhưng không khớp khoảng thời gian đang chọn.</p>
              <p>Mốc đã lưu: {uniqueBoundaries[0].observed_at} ({uniqueBoundaries[0].reading_value} {unit}) → {uniqueBoundaries[1].observed_at} ({uniqueBoundaries[1].reading_value} {unit}).</p>
              <button type="button" disabled={!enabled || busy} className="rounded-lg border border-amber-500 bg-white px-3 py-2 font-medium" onClick={() => {
                const first = uniqueBoundaries[0], last = uniqueBoundaries[1]
                setMeterDrafts(previous => ({ ...previous, [meter.id]: {
                  start: first.observed_at.replace(' ', 'T'), end: last.observed_at.replace(' ', 'T'),
                  startReading: String(first.reading_value), endReading: String(last.reading_value),
                } }))
                setMeterEdits(previous => ({ ...previous, [meter.id]: {
                  startId: first.id, endId: last.id, reason: '', key: crypto.randomUUID(),
                  original: { start: first.observed_at.replace(' ', 'T'), end: last.observed_at.replace(' ', 'T'),
                    startReading: String(first.reading_value), endReading: String(last.reading_value) },
                } }))
              }}>Sửa hai mốc đã ghi nhầm kỳ</button>
              <p className="text-xs text-amber-900">Sửa ngày giờ/số đo thực tế và nhập lý do. Không tự đưa chỉ số về 0: số 0 chỉ đúng nếu công tơ thực tế đọc 0. Dữ liệu cũ được giữ làm lịch sử.</p>
            </div>}
            {datesValid && !valuesValid && <p role="alert" className="text-sm text-red-700">Chỉ số cuối phải lớn hơn hoặc bằng chỉ số đầu và cả hai không được âm.</p>}
            {confirmedCycle && <p className="rounded-lg bg-green-50 p-3 text-sm text-green-900">Đã có kỳ tính trong hệ thống: {confirmedCycle.usage_start_at} → {confirmedCycle.usage_end_at}. Sản lượng {confirmedCycle.quantity} {unit}, tiền phân bổ {formatCurrency(confirmedCycle.tariff_total)}. Muốn đổi kỳ đã tính cần xem trước và xác nhận tính lại hóa đơn nháp.</p>}
            <details className="text-sm"><summary className="cursor-pointer text-slate-700">Lịch sử mốc đã ghi nhận ({relevantHistory.length} gần nhất)</summary>
              <div className="mt-2 max-h-52 space-y-1 overflow-auto">{correctionHistory.map(item => <div key={`correction-${item.id}`} className="rounded bg-amber-50 p-2">Đính chính lúc {item.created_at} · người dùng #{item.corrected_by} · lý do: {item.reason}
                  {checkpoints.some(point => Number(point.id) === Number(item.new_start_checkpoint_id)) && checkpoints.some(point => Number(point.id) === Number(item.new_end_checkpoint_id)) && <button type="button" className="ml-2 underline" disabled={busy || editingSaved} onClick={() => {
                    const s = checkpoints.find(point => Number(point.id) === Number(item.new_start_checkpoint_id))
                    const e = checkpoints.find(point => Number(point.id) === Number(item.new_end_checkpoint_id))
                    setMeterCycle(meter, { start: s.observed_at.replace(' ', 'T'), end: e.observed_at.replace(' ', 'T'), startReading: String(s.reading_value), endReading: String(e.reading_value) })
                  }}>Xem cặp mốc này</button>}
                </div>)}{relevantHistory.length === 0 ? <p>Chưa có lịch sử mốc.</p> : relevantHistory.map(item => <p key={item.id} className="rounded bg-white p-2">{item.observed_at} · {item.reading_value} {unit} · phiên bản {item.revision}</p>)}</div>
            </details>
          </article>
            })}
          </section>
        ))}
        </div>
        {meters.length > 1 && new Set(meters.map(meter => `${at(meterCycle(meter).start)}|${at(meterCycle(meter).end)}`)).size > 1 && <p role="status" className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">Điện và nước có ngày đọc khác nhau. Hệ thống tính riêng theo hai mốc đã xác nhận của từng công tơ, rồi gộp khoản thu vào tháng {period}. Không cộng gộp chỉ số tuyệt đối giữa các đồng hồ.</p>}
        {suggestedWindow.ambiguous && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">Có nhiều hợp đồng bắt đầu vào các ngày khác nhau trong kỳ. Hãy đối chiếu kỳ công tơ và khoản thu; không tự chọn ngày đầu tháng để tính tiền.</p>}
        {missingData && <p role="status" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Chưa đủ mốc đầu/cuối khớp thời gian cho tất cả công tơ. Hãy lưu từng công tơ rồi kiểm tra trạng thái “Đã lưu đủ hai mốc”.</p>}
        <div id="billing-v2-calculate" className="scroll-mt-24 space-y-2 rounded-xl border border-teal-200 bg-teal-50/70 p-3 sm:p-4">
        <button type="button" className={`${buttonClass} w-full sm:w-auto`} disabled={meters.length === 0 || missingData || suggestedWindow.ambiguous || Boolean(historyConflict) || busy || Object.keys(meterEdits).length > 0} onClick={calculateCycle}>{busy ? 'Đang xử lý…' : 'Tính khoản thu điện nước đã xác nhận'}</button>
        <p className="text-xs text-slate-600">Nút trên quyết toán điện nước, sau đó thử tổng hợp hóa đơn nháp đầy đủ cho đúng phòng này. Không tự phát hành hoặc thu tiền.</p>
        </div>
      </section>
      <section className="rounded-xl border bg-blue-50 p-4 text-sm text-blue-900">
        <h3 className="font-semibold">Bàn giao điện nước khi nhận phòng</h3>
        <p className="mt-1">CHECK_IN chỉ làm một lần cho mỗi hợp đồng và từng công tơ, không phải thao tác ghi chỉ số hàng tháng. Hãy xác nhận ở trang chi tiết hợp đồng.</p>
        <div className="mt-2 flex flex-wrap gap-3">{contracts.filter(contract => contract.status === 'ACTIVE').map(contract => <Link key={contract.id} className="underline" to={`/contracts/${contract.id}#contract-meter-checkin`}>Bàn giao · {contract.contract_code}</Link>)}</div>
      </section>
      <form id="billing-v2-checkout" aria-label="Xác nhận trả phòng" onSubmit={event => { event.preventDefault(); run(() => confirmBillingCheckout(checkout.contract, { reason: checkout.reason, actual_ended_at: at(checkout.observed), confirm_no_held_deposit: checkout.confirmNoHeld, readings: meters.map(meter => ({ utility_meter_id: meter.id, reading_value: checkout.readings[meter.id] })) })) }} className="space-y-4 rounded-xl border bg-white p-5">
        <h3 className="text-lg font-semibold">Trả phòng và chốt công tơ</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <label>Hợp đồng trả phòng<select aria-label="Hợp đồng trả phòng" required className={inputClass} value={checkout.contract} onChange={event => setCheckout({ ...checkout, contract: event.target.value })}>{contractOptions}</select></label>
          <label>Lý do kết thúc<select aria-label="Lý do kết thúc" className={inputClass} value={checkout.reason} onChange={event => setCheckout({ ...checkout, reason: event.target.value })}><option value="MUTUAL_EARLY_END">Kết thúc theo thỏa thuận</option><option value="NORMAL_EXPIRY">Hết hạn bình thường</option><option value="TENANT_ABANDONMENT">Bỏ ngang — mất cọc thực giữ, chủ trọ chịu điện nước</option></select></label>
          <label>Thời điểm trả phòng<input type="datetime-local" required className={inputClass} value={checkout.observed} onChange={event => setCheckout({ ...checkout, observed: event.target.value })} /></label>
          {meters.map(meter => <label key={meter.id}>Chỉ số trả phòng: {meter.service?.name}<input type="number" min="0" step="0.01" required className={inputClass} value={checkout.readings[meter.id] ?? ''} onChange={event => setCheckout({ ...checkout, readings: { ...checkout.readings, [meter.id]: event.target.value } })} /></label>)}
        </div>{checkout.reason === 'TENANT_ABANDONMENT' && <label className="flex items-start gap-3 text-sm"><input type="checkbox" checked={checkout.confirmNoHeld} onChange={event => setCheckout({ ...checkout, confirmNoHeld: event.target.checked })} />Tôi xác nhận không có tiền cọc thực tế còn đang giữ (chỉ chọn khi đã đối soát; không tạo giao dịch mất cọc từ tiền cọc danh nghĩa).</label>}<button className={buttonClass}>Xác nhận trả phòng</button>
      </form>
      <form aria-label="Ghi sổ cọc" onSubmit={event => { event.preventDefault(); run(() => recordBillingDeposit(deposit.contract, { event: deposit.event, amount: deposit.amount, occurred_at: at(deposit.observed), request_key: deposit.key })) }} className="space-y-4 rounded-xl border bg-white p-5">
        <h3 className="text-lg font-semibold">Sổ cọc thực nhận</h3><p className="text-sm text-slate-600">Không suy ra tiền đã nhận từ tiền cọc trên hợp đồng. Khi thử lại một giao dịch, giữ nguyên mã giao dịch; giao dịch khác cần mã mới.</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <label>Hợp đồng ghi cọc<select aria-label="Hợp đồng ghi cọc" required className={inputClass} value={deposit.contract} onChange={event => setDeposit({ ...deposit, contract: event.target.value })}>{contractOptions}</select></label>
          <label>Giao dịch cọc<select aria-label="Giao dịch cọc" className={inputClass} value={deposit.event} onChange={event => setDeposit({ ...deposit, event: event.target.value })}><option value="RECEIVED">Đã nhận cọc</option><option value="REFUNDED">Đã hoàn cọc</option></select></label>
          <label>Số tiền cọc (đ)<input type="number" min="1" step="1" required className={inputClass} value={deposit.amount} onChange={event => setDeposit({ ...deposit, amount: event.target.value })} /></label>
          <label>Thời điểm giao dịch cọc<input type="datetime-local" required className={inputClass} value={deposit.observed} onChange={event => setDeposit({ ...deposit, observed: event.target.value })} /></label>
          <label className="sm:col-span-2">Mã giao dịch cọc<input required maxLength={80} className={inputClass} value={deposit.key} onChange={event => setDeposit({ ...deposit, key: event.target.value })} /></label>
        </div><div className="flex flex-wrap gap-3"><button className={buttonClass}>Ghi giao dịch cọc</button><button type="button" className="min-h-11 rounded-lg border px-4" onClick={() => setDeposit({ ...deposit, key: crypto.randomUUID() })}>Mã cho giao dịch mới</button></div>
      </form>
    </fieldset>
    <section className="space-y-3" aria-label="Quyết toán trả phòng"><h3 className="text-lg font-semibold">Quyết toán trả phòng</h3>
      {(data.settlements || []).map(settlement => <article key={settlement.id} data-settlement-status={settlement.status} className="space-y-2 rounded-lg border bg-white p-4">
        <h4 className="font-semibold">Hợp đồng #{settlement.contract_id} · kỳ {settlement.billing_period?.slice(0, 7)}</h4>
        <p>CHECK_OUT đã hoàn tất. Việc chờ tiền điện nước không ngăn bàn giao hợp đồng tiếp theo khi các điều kiện khác đã hợp lệ.</p>
        <p>{settlement.status === 'PENDING_FINAL_SETTLEMENT' ? 'PENDING_FINAL_SETTLEMENT — Chờ quyết toán cuối kỳ; chưa xác định tiền cuối cùng, không phải 0đ hoặc đã thanh toán.' : 'FINAL_RECONCILED — Đã đối soát sản lượng và bảng giá cuối kỳ; trạng thái thu tiền theo từng hóa đơn.'}</p>
        {settlement.collection_policy === 'NO_NEW_AUTO_RECEIVABLES' && <p className="text-sm text-amber-900">TENANT_ABANDONMENT: không phát sinh thêm tiền phòng hoặc dịch vụ tự động trong kỳ bỏ ngang. Không chuyển sang người mới, không xóa nợ cũ, không dùng cọc thanh toán nợ.</p>}
        {settlement.deposit_status === 'FORFEITED_ACTUAL_BALANCE' && <p>Đã ghi nhận mất số cọc thực tế còn giữ.</p>}
        {settlement.confirmation_state === 'NEEDS_OWNER_CONFIRMATION' && <div className="rounded bg-amber-50 p-3"><p>Cần chủ trọ xác nhận lịch sử cọc/bằng chứng. Hệ thống chưa suy diễn có tiền cọc để mất.</p><button disabled={busy} type="button" className="mt-2 min-h-11 rounded border p-2" onClick={() => run(() => confirmBillingSettlement(settlement.contract_id))}>Tôi xác nhận không có cọc thực giữ — hợp đồng #{settlement.contract_id}</button></div>}
        {settlement.invoice_review_status === 'NEEDS_INVOICE_REVIEW' && <p role="alert" className="text-amber-900">Đã có khoản thu/hóa đơn cần kiểm tra nguồn gốc; hệ thống giữ nguyên, không tự xóa hoặc ghi đè.</p>}
        {(data.settlement_usages || []).filter(usage => usage.contract_settlement_id === settlement.id).map(usage => <p key={usage.id} className="text-sm">Công tơ #{usage.utility_meter_id}: {usage.checkout_period_quantity === null ? `chưa đủ mốc đầu kỳ; kể từ nhận phòng ${usage.quantity_since_check_in} đơn vị` : `${usage.checkout_period_quantity} đơn vị trong kỳ trả phòng`}. {usage.responsible_party === 'LANDLORD' ? 'Chủ trọ chịu' : 'Theo nghĩa vụ hợp đồng'}. {usage.tariff_amount === null ? 'Số tiền cuối kỳ: đang chờ, không ghi là 0đ.' : `Giá bán phân bổ: ${formatCurrency(usage.tariff_amount)}; không phải giá vốn.`}</p>)}
      </article>)}
    </section>
    <section className="space-y-3"><h3 className="text-lg font-semibold">Phân bổ kỳ {period}</h3>{data.allocations.length === 0 && <p>Chưa có phân bổ được tính cho kỳ này.</p>}{data.allocations.map(row => <article key={row.id} className="rounded-lg border bg-white p-4"><p>{row.responsible_party === 'TENANT' ? `Hợp đồng #${row.contract_id}` : 'Chủ trọ chịu'} · {row.reason === 'VACANT' ? 'Phòng trống' : row.reason === 'ABANDONMENT' ? 'Bỏ ngang' : 'Đang cư trú'}</p><p>{row.quantity} đơn vị · Giá bán phân bổ {formatCurrency(row.tariff_amount)}</p>{row.responsible_party === 'LANDLORD' && <p className="text-sm text-slate-600">Giá vốn thực tế: {row.supplier_cost === null ? 'Chưa xác nhận' : formatCurrency(row.supplier_cost)}</p>}</article>)}</section>
    <section id="billing-v2-invoices" className="scroll-mt-24 space-y-2"><h3 className="text-lg font-semibold">Hóa đơn từng hợp đồng</h3><p className="text-sm text-slate-600">Kiểm tra đủ tiền phòng, dịch vụ và điện nước trong hóa đơn tháng trước khi phát hành.</p>{data.invoices.map(row => <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 rounded border bg-white p-3"><Link className="inline-flex min-h-11 items-center underline" to={`/invoices/${row.invoice_id}`}>Hợp đồng #{row.contract_id} — hóa đơn #{row.invoice_id}</Link><span>{row.status === 'DRAFT' ? 'Nháp' : row.status === 'UNPAID' ? 'Chưa thanh toán' : row.status}</span></div>)}</section>
    <section className="space-y-2"><h3 className="text-lg font-semibold">Lịch sử cọc đã ghi nhận</h3>{data.deposits.length === 0 && <p>Chưa ghi nhận cọc thực tế.</p>}{data.deposits.map(row => <p className="break-words rounded border bg-white p-3" key={row.id}>Hợp đồng #{row.contract_id} · {({ RECEIVED: 'Nhận cọc', REFUNDED: 'Hoàn cọc', FORFEITED: 'Mất cọc' })[row.event]} · {formatCurrency(row.amount)} · {row.request_key}</p>)}</section>
  </section>
}
