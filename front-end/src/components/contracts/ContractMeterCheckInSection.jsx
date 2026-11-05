import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getBillingV2, confirmBillingCheckpoint } from '../../api/billingV2Api'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { inspectCheckInReading } from '../../pages/invoices/checkInReading.mjs'

const fieldClass = 'mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2'
const toApiDate = value => value.replace('T', ' ') + (value.length === 16 ? ':00' : '')
const monthFor = date => (date || '').slice(0, 7) || new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' }).slice(0, 7)

/** Draft openings are required before activation; V2 CHECK_IN is confirmed after activation. */
export default function ContractMeterCheckInSection({ contract }) {
  const client = useQueryClient()
  const [readings, setReadings] = useState({})
  const [acknowledged, setAcknowledged] = useState({})
  const [saving, setSaving] = useState(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [time, setTime] = useState('')
  const roomId = contract.room_id
  const billingPeriod = `${monthFor(contract.start_date)}-01`
  const query = useQuery({
    queryKey: ['billing-v2', roomId, billingPeriod],
    queryFn: () => getBillingV2(roomId, billingPeriod),
    enabled: ['ACTIVE', 'DRAFT'].includes(contract.status) && !contract.is_demo,
  })
  if (!['ACTIVE', 'DRAFT'].includes(contract.status)) return null
  const draft = contract.status === 'DRAFT'
  const data = query.data?.data?.data
  const meters = data?.meters || []
  const checkpoints = data?.checkpoints || []
  const confirmed = meter => checkpoints.find(point => point.kind === 'CHECK_IN' && Number(point.contract_id) === Number(contract.id) && Number(point.utility_meter_id) === Number(meter.id))
  const missing = meters.filter(meter => !confirmed(meter))
  const save = async meter => {
    if (saving || confirmed(meter)) return
    setError(''); setMessage(''); setSaving(meter.id)
    try {
      const value = readings[meter.id] ?? ''
      const review = inspectCheckInReading(checkpoints, meter.id, time, value)
      if (!review.allowed || !acknowledged[meter.id]) throw new Error(review.message || 'Bạn cần đối chiếu công tơ trước khi xác nhận.')
      await confirmBillingCheckpoint(meter.id, {
        kind: 'CHECK_IN', contract_id: Number(contract.id), observed_at: toApiDate(time), reading_value: String(value),
      })
      await client.invalidateQueries({ queryKey: ['billing-v2', roomId] })
      setAcknowledged(previous => ({ ...previous, [meter.id]: false }))
      setMessage(`Đã xác nhận CHECK_IN công tơ #${meter.id}. Vui lòng kiểm tra các công tơ còn lại.`)
    } catch (failure) {
      setError(failure.response ? getApiErrorMessage(failure) : failure.message)
    } finally { setSaving(null) }
  }
  return <section id="contract-meter-checkin" className="scroll-mt-4 space-y-4 rounded-xl border border-blue-200 bg-white p-4 shadow-sm sm:p-6">
    <div>
      <h3 className="text-lg font-semibold">Bàn giao chỉ số điện nước (Billing V2)</h3>
      <p className="mt-1 text-sm text-slate-600">{draft ? 'Bước chuẩn bị khi hợp đồng còn nháp: xem công tơ và đối chiếu chỉ số thực tế. Chưa ghi CHECK_IN cho tới khi hợp đồng đã kích hoạt.' : 'Thực hiện một lần khi nhận phòng. Đây là mốc trách nhiệm của người thuê, khác với biên bản bàn giao thiết bị và kỳ đọc công tơ hằng tháng.'}</p>
    </div>
    {draft && <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"><p className="font-semibold">Chưa thể xác nhận CHECK_IN: hợp đồng đang nháp.</p><p className="mt-1">Trước khi kích hoạt: bấm <strong>Sửa hợp đồng</strong> → bước <strong>2. Người ở & dịch vụ</strong> → phần <strong>Chỉ số đầu hợp đồng</strong>; ghi số đọc thực tế và ngày bàn giao nằm trong thời hạn thuê, rồi lưu nháp. Sau đó xác nhận biên bản bàn giao và kích hoạt. CHECK_IN V2 được xác nhận một lần sau khi kích hoạt. Không cần chỉ số cuối kỳ.</p><div className="mt-2 flex flex-wrap gap-4"><Link to={`/contracts/${contract.id}/edit`} className="font-semibold underline">Sửa chỉ số đầu hợp đồng</Link><a href="#contract-handover" className="underline">Đến biên bản bàn giao</a></div></div>}
    {contract.is_demo && <p className="text-sm text-amber-900">Hợp đồng DEMO không thể kích hoạt hoặc ghi CHECK_IN thực tế.</p>}
    {!contract.is_demo && query.isPending && <p role="status">Đang kiểm tra dữ liệu công tơ…</p>}
    {!contract.is_demo && query.isError && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Không đọc được trạng thái CHECK_IN: {getApiErrorMessage(query.error)}. Không xác nhận khi chưa đối chiếu được dữ liệu.</p>}
    {!contract.is_demo && data && <>
      {meters.length === 0 ? <p className="rounded-lg bg-amber-50 p-3 text-sm">Phòng chưa có đồng hồ được áp dụng. <Link className="underline" to={`/rooms/${roomId}/billing-v2`}>Kiểm tra quản lý công tơ</Link>.</p> : <>
        {!draft && (missing.length === 0 ? <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-900">Đã xác nhận CHECK_IN cho toàn bộ {meters.length} công tơ hiện tại. Các tháng sau chỉ cần ghi mốc cuối kỳ.</p> : <p role="status" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Còn {missing.length}/{meters.length} công tơ chưa có CHECK_IN cho hợp đồng này. Hãy hoàn tất trước khi tính điện nước.</p>)}
        {!draft && missing.length > 0 && <label className="block text-sm font-medium">Ngày giờ bàn giao thực tế
          <input type="datetime-local" className={fieldClass} value={time} onChange={event => { setTime(event.target.value); setAcknowledged({}) }} />
          <span className="mt-1 block font-normal text-slate-500">Không tự điền giờ 00:00. Chọn thời điểm đã đọc công tơ thực tế; không được trước ngày bắt đầu hợp đồng {contract.start_date?.slice(0, 10)}.</span>
        </label>}
        <div className="grid gap-3 sm:grid-cols-2">{meters.map(meter => {
          const saved = confirmed(meter)
          const value = readings[meter.id] ?? ''
          const review = inspectCheckInReading(checkpoints, meter.id, time, value)
          const unit = meter.service?.unit || (meter.service?.type === 'WATER' ? 'm³' : 'kWh')
          return <div key={meter.id} className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="font-semibold">{meter.service?.type === 'ELECTRICITY' ? '⚡ Điện' : meter.service?.type === 'WATER' ? '💧 Nước' : meter.service?.name} · Đồng hồ #{meter.id}</div>
            {draft ? <div className="space-y-2"><p className="text-slate-600">Đồng hồ này sẽ được đối chiếu khi nhận phòng. Hãy đọc trực tiếp chỉ số thực tế hoặc chụp ảnh công tơ.</p>{(() => { const points = checkpoints.filter(point => Number(point.utility_meter_id) === Number(meter.id) && point.kind === 'PERIOD_BOUNDARY' && String(point.observed_at || '').slice(0, 10) <= String(contract.start_date || '').slice(0, 10)).sort((a, b) => String(b.observed_at).localeCompare(String(a.observed_at))); const point = points[0]; return point ? <p className="rounded-lg bg-slate-50 p-3">Mốc tham khảo gần ngày nhận phòng: <strong>{point.reading_value} {unit}</strong> · {point.observed_at}. Đây không phải chỉ số bàn giao đã xác nhận.</p> : <p className="rounded-lg bg-slate-50 p-3">Chưa có mốc tham khảo trước ngày nhận phòng. Bạn sẽ nhập chỉ số thực tế sau khi kích hoạt.</p> })()}</div> : saved ? <p className="rounded-lg bg-green-50 p-3 text-green-900">Đã xác nhận {saved.observed_at}: <strong>{saved.reading_value} {unit}</strong>. Không tạo thêm CHECK_IN.</p> : <>
              <p className="text-slate-600">Chỉ số tham chiếu sẽ hiện sau khi chọn ngày giờ. Hãy đọc trực tiếp công tơ vật lý trước khi nhập.</p>
              <label className="block">Chỉ số thực tế ({unit})<input type="number" min="0" step="0.01" className={fieldClass} value={value} onChange={event => { setReadings(current => ({ ...current, [meter.id]: event.target.value })); setAcknowledged(current => ({ ...current, [meter.id]: false })) }} placeholder="Nhập số trên công tơ" /></label>
              {time && <div className="rounded-lg bg-slate-50 p-3">
                <p>{review.reference ? `Mốc tham chiếu: ${review.reference.reading_value} ${unit} lúc ${review.reference.observed_at}` : 'Chưa có mốc xác nhận trước thời điểm bàn giao.'}</p>
                <p className={review.allowed ? 'mt-1 text-emerald-800' : 'mt-1 text-amber-900'}>{review.message}</p>
                {review.reason === 'SAME_TIME_CONFLICT' && <Link className="mt-2 inline-block underline" to={`/rooms/${roomId}/billing-v2`}>Đối soát, đính chính mốc tại trang công tơ</Link>}
              </div>}
              <label className="flex items-start gap-2"><input type="checkbox" checked={Boolean(acknowledged[meter.id])} onChange={event => setAcknowledged(current => ({ ...current, [meter.id]: event.target.checked }))} />Tôi đã đọc và đối chiếu số trên công tơ vật lý khi bàn giao.</label>
              <button type="button" disabled={Boolean(saving) || !time || time.slice(0, 10) < contract.start_date?.slice(0, 10) || !review.allowed || !acknowledged[meter.id]} onClick={() => save(meter)} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50">{saving === meter.id ? 'Đang lưu…' : 'Xác nhận CHECK_IN'}</button>
            </>}
          </div>
        })}</div>
      </>}
      <Link to={`/rooms/${roomId}/billing-v2`} className="inline-flex min-h-11 items-center underline">Đi tới quyết toán điện nước V2</Link>
    </>}
    {message && <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-900">{message}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-800">{error}</p>}
  </section>
}
