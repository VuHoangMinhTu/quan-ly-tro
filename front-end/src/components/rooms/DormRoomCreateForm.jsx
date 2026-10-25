import { useState } from 'react'
import MoneyInput from '../ui/MoneyInput'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage'
import { dormRoomFormSchema } from '../../utils/formValidation'

const inputClass = 'min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm'
const newBed = (index) => ({ bed_code: `G${String(index).padStart(2, '0')}`, monthly_rent: '', status: 'AVAILABLE' })

export default function DormRoomCreateForm({ onSubmit }) {
  const [room, setRoom] = useState({ room_code: '', room_name: '', area: '', description: '' })
  const [beds, setBeds] = useState([newBed(1)])
  const [count, setCount] = useState('6')
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const errorFor = name => fieldErrors[name] && <span role="alert" className="mt-1 block text-sm text-red-600">{fieldErrors[name]}</span>

  const generate = () => {
    const total = Number(count)
    if (!Number.isInteger(total) || total < 1 || total > 100) { setError('Số giường phải từ 1 đến 100.'); return }
    setBeds(Array.from({ length: total }, (_, index) => newBed(index + 1)))
    setError('')
  }

  const submit = async (event) => {
    event.preventDefault()
    setFieldErrors({}); setError('')
    const result = dormRoomFormSchema.safeParse({ ...room, beds })
    if (!result.success) {
      setFieldErrors(Object.fromEntries(result.error.issues.map(issue => [issue.path.join('.'), issue.message])))
      return
    }
    setError(''); setSubmitting(true)
    try {
      await onSubmit({ ...result.data, monthly_rent: null, max_tenants: null, status: 'AVAILABLE', room_type: 'DORMITORY' })
    } catch (apiError) {
      const errors = getApiFieldErrors(apiError)
      if (Object.keys(errors).length) setFieldErrors(errors)
      else setError(getApiErrorMessage(apiError))
    }
    finally { setSubmitting(false) }
  }

  return <form noValidate onSubmit={submit} className="space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <h3 className="text-lg font-semibold">Tạo phòng KTX</h3>
    <div className="grid gap-4 sm:grid-cols-2">
      <label className="text-sm">Mã phòng *<input required aria-invalid={Boolean(fieldErrors.room_code)} value={room.room_code} onChange={(event) => setRoom({ ...room, room_code: event.target.value })} className={inputClass} />{errorFor('room_code')}</label>
      <label className="text-sm">Tên phòng<input value={room.room_name} onChange={(event) => setRoom({ ...room, room_name: event.target.value })} className={inputClass} />{errorFor('room_name')}</label>
      <label className="text-sm">Diện tích (m²)<input type="number" min="0" step="0.01" value={room.area} onChange={(event) => setRoom({ ...room, area: event.target.value })} className={inputClass} />{errorFor('area')}</label>
      <label className="text-sm">Số giường để tạo nhanh<div className="flex gap-2"><input type="number" min="1" max="100" value={count} onChange={(event) => setCount(event.target.value)} className={inputClass} /><button type="button" onClick={generate} className="shrink-0 rounded-lg border px-3 text-sm">Sinh giường</button></div></label>
    </div>
    <label className="block text-sm">Mô tả<textarea value={room.description} onChange={(event) => setRoom({ ...room, description: event.target.value })} className={inputClass} /></label>
    <div><h4 className="font-medium">Danh sách giường</h4><p className="text-sm text-slate-500">Có thể sửa giá từng giường hoặc để trống để cập nhật sau.</p></div>
    <div className="space-y-3">{beds.map((bed, index) => <div key={index} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
      <label className="text-sm">Mã giường<input required aria-invalid={Boolean(fieldErrors[`beds.${index}.bed_code`])} value={bed.bed_code} onChange={(event) => setBeds(beds.map((item, position) => position === index ? { ...item, bed_code: event.target.value } : item))} className={inputClass} />{errorFor(`beds.${index}.bed_code`)}</label>
      <label className="text-sm">Giá thuê/tháng<MoneyInput value={bed.monthly_rent} onChange={(value) => setBeds(beds.map((item, position) => position === index ? { ...item, monthly_rent: value } : item))} className={inputClass} />{errorFor(`beds.${index}.monthly_rent`)}</label>
      <label className="text-sm">Trạng thái<select value={bed.status} onChange={(event) => setBeds(beds.map((item, position) => position === index ? { ...item, status: event.target.value } : item))} className={inputClass}><option value="AVAILABLE">Trống</option><option value="MAINTENANCE">Bảo trì</option></select>{errorFor(`beds.${index}.status`)}</label>
      <button type="button" onClick={() => setBeds(beds.filter((_, position) => position !== index))} className="self-end rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Xóa</button>
    </div>)}</div>
    <button type="button" onClick={() => setBeds([...beds, newBed(beds.length + 1)])} className="rounded-lg border px-4 py-2 text-sm">+ Thêm giường</button>
    {errorFor('beds')}{errorFor('description')}
    {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
    <div><button disabled={submitting} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{submitting ? 'Đang tạo...' : `Tạo phòng KTX và ${beds.length} giường`}</button></div>
  </form>
}
