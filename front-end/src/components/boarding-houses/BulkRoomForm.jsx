import { useState } from 'react'
import { Controller, useFieldArray, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { bulkRoomsSchema, emptyBulkRoom, generateRoomCodes, MAX_BULK_ROOMS } from '../../utils/roomOnboarding'
import MoneyInput from '../ui/MoneyInput'

const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900'
const buttonClass = 'rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50'

export default function BulkRoomForm({ initialRooms, onBack, onSubmit }) {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [generatorError, setGeneratorError] = useState('')
  const [submitError, setSubmitError] = useState('')
  const form = useForm({ resolver: zodResolver(bulkRoomsSchema), defaultValues: { rooms: initialRooms } })
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'rooms' })
  const { errors, isSubmitting } = form.formState

  const generate = () => {
    setGeneratorError('')
    try {
      const generated = generateRoomCodes(from, to)
      const current = form.getValues('rooms')
      if (current.length + generated.length > MAX_BULK_ROOMS) throw new Error('Mỗi lần chỉ được tạo tối đa 200 phòng.')
      const codes = new Set(current.map((room) => room.room_code.trim().toLocaleLowerCase('vi-VN')))
      if (generated.some((room) => codes.has(room.room_code))) throw new Error('Khoảng mã này trùng với phòng đã thêm. Vui lòng chọn khoảng khác.')
      append(generated)
    } catch (error) {
      setGeneratorError(error.message)
    }
  }

  const submit = async ({ rooms }) => {
    setSubmitError('')
    try {
      await onSubmit(rooms)
    } catch (error) {
      const applied = applyApiFieldErrors(error, form.setError, (field) => field.startsWith('rooms.') ? field : null)
      if (!applied) setSubmitError(getApiErrorMessage(error))
      const houseErrors = error.response?.data?.errors
      if (houseErrors && Object.keys(houseErrors).some((field) => field.startsWith('boarding_house.'))) {
        setSubmitError('Thông tin nhà trọ chưa hợp lệ. Vui lòng quay lại bước 1 để kiểm tra.')
      }
    }
  }

  return <form onSubmit={form.handleSubmit(submit)} className="space-y-5 rounded-xl bg-white p-4 shadow-sm sm:p-6">
    <div><h3 className="text-lg font-semibold">Tạo phòng</h3><p className="mt-1 text-sm text-slate-600">Chỉ cần mã phòng. Diện tích, giá thuê và số người tối đa có thể bổ sung sau. Để trống giá thuê là chưa thiết lập, không phải giá 0.</p></div>
    <fieldset disabled={isSubmitting} className="min-w-0 space-y-5">
      <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <h4 className="text-sm font-semibold">Tạo nhanh mã phòng</h4>
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <div className="min-w-0 flex-1"><label htmlFor="bulk-from" className="mb-1 block text-sm">Từ</label><input id="bulk-from" inputMode="numeric" value={from} onChange={(event) => setFrom(event.target.value)} placeholder="101" className={inputClass} /></div>
          <div className="min-w-0 flex-1"><label htmlFor="bulk-to" className="mb-1 block text-sm">Đến</label><input id="bulk-to" inputMode="numeric" value={to} onChange={(event) => setTo(event.target.value)} placeholder="110" className={inputClass} /></div>
          <button type="button" onClick={generate} className={buttonClass}>Sinh mã phòng</button>
        </div>
        {generatorError && <p role="alert" className="mt-2 text-sm text-red-600">{generatorError}</p>}
        <p className="mt-2 text-xs text-slate-500">Generator chỉ sinh mã phòng; tối đa {MAX_BULK_ROOMS} phòng mỗi lần.</p>
      </div>
      <div className="overflow-x-auto rounded-lg border border-slate-200">
        <table className="w-full min-w-[700px] text-sm">
          <thead className="bg-slate-50 text-left"><tr>{['Mã phòng *', 'Diện tích', 'Giá thuê/tháng', 'Số người tối đa', 'Thao tác'].map((label) => <th key={label} scope="col" className="px-3 py-3 font-medium">{label}</th>)}</tr></thead>
          <tbody>{fields.map((row, index) => {
            const rowErrors = errors.rooms?.[index]
            const prefix = 'rooms.' + index + '.'
            return <tr key={row.id} className="border-t border-slate-200 align-top">
              <td className="px-3 py-3"><input aria-label={'Mã phòng dòng ' + (index + 1)} aria-invalid={Boolean(rowErrors?.room_code)} className={inputClass} {...form.register(prefix + 'room_code')} />{rowErrors?.room_code && <p className="mt-1 text-xs text-red-600">{rowErrors.room_code.message}</p>}</td>
              <td className="px-3 py-3"><div className="flex items-center gap-2"><input type="number" min="0" step="0.01" placeholder="VD: 20" aria-label={'Diện tích dòng ' + (index + 1)} className={inputClass} {...form.register(prefix + 'area')} /><span className="shrink-0 text-slate-500">m²</span></div>{rowErrors?.area && <p className="mt-1 text-xs text-red-600">{rowErrors.area.message}</p>}</td>
              <td className="px-3 py-3"><Controller name={prefix + 'monthly_rent'} control={form.control} render={({ field }) => <MoneyInput {...field} aria-label={'Giá thuê dòng ' + (index + 1)} placeholder="Chưa thiết lập" className={inputClass} />} />{rowErrors?.monthly_rent && <p className="mt-1 text-xs text-red-600">{rowErrors.monthly_rent.message}</p>}</td>
              <td className="px-3 py-3"><input type="number" min="1" step="1" placeholder="Chưa thiết lập" aria-label={'Số người tối đa dòng ' + (index + 1)} className={inputClass} {...form.register(prefix + 'max_tenants')} />{rowErrors?.max_tenants && <p className="mt-1 text-xs text-red-600">{rowErrors.max_tenants.message}</p>}</td>
              <td className="px-3 py-3"><button type="button" aria-label={'Xóa dòng ' + (index + 1)} onClick={() => remove(index)} className="rounded-lg px-3 py-2 text-red-600 hover:bg-red-50">Xóa</button></td>
            </tr>
          })}</tbody>
        </table>
        {fields.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Chưa có phòng. Sinh mã hoặc thêm một dòng bên dưới.</p>}
      </div>
      <button type="button" disabled={fields.length >= MAX_BULK_ROOMS} onClick={() => append(emptyBulkRoom())} className={buttonClass}>+ Thêm phòng thủ công</button>
      {(errors.rooms?.message || errors.rooms?.root?.message) && <p role="alert" className="text-sm text-red-600">{errors.rooms.message || errors.rooms.root.message}</p>}
      {submitError && <p role="alert" className="text-sm text-red-600">{submitError}</p>}
      <div className="flex flex-wrap justify-between gap-3 border-t border-slate-100 pt-4">
        <button type="button" onClick={() => onBack(form.getValues('rooms'))} className={buttonClass}>← Thông tin nhà trọ</button>
        <button type="submit" className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{isSubmitting ? 'Đang tạo...' : 'Tạo nhà trọ và ' + fields.length + ' phòng'}</button>
      </div>
    </fieldset>
  </form>
}