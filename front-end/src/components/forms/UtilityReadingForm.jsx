import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import DateInput from '../ui/DateInput'
import { formatDateForApi, formatDateForDisplay, getTodayForDisplay, isValidDisplayDate } from '../../utils/date'

export default function UtilityReadingForm({ initialValues = {}, onSubmit, label, readingContext }) {
  const form = useForm({ defaultValues: { reading_date: getTodayForDisplay(), reading_value: '', note: '' } })
  const [error, setError] = useState('')

  useEffect(() => form.reset({
    reading_date: formatDateForDisplay(initialValues.reading_date || getTodayForDisplay()),
    reading_value: initialValues.reading_value ?? '',
    note: initialValues.note || '',
  }), [form, initialValues])

  const readingDate = useWatch({ control: form.control, name: 'reading_date' })
  const readingValue = useWatch({ control: form.control, name: 'reading_value' })
  const preview = readingContext?.getPreview?.(readingDate, readingValue)

  const submit = async (values) => {
    setError('')
    if (!isValidDisplayDate(values.reading_date)) {
      setError('Ngày ghi phải theo định dạng dd/mm/yyyy và hợp lệ.')
      return
    }

    try {
      await onSubmit({
        reading_date: formatDateForApi(values.reading_date),
        reading_value: Number(values.reading_value),
        note: values.note || null,
      })
    } catch (requestError) {
      applyApiFieldErrors(requestError, form.setError)
      setError(getApiErrorMessage(requestError))
    }
  }

  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
    <div>
      <label className="block text-sm font-medium">Ngày ghi *</label>
      <Controller name="reading_date" control={form.control} rules={{ required: 'Vui lòng nhập ngày ghi.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.reading_date)} />} />
      {form.formState.errors.reading_date && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reading_date.message}</p>}
    </div>
    <div>
      <label className="block text-sm font-medium" htmlFor="reading-value">Chỉ số công tơ hiện tại *</label>
      <input id="reading-value" type="number" min="0" step="0.01" placeholder="VD: 150" className="mt-1 w-full rounded border p-2" {...form.register('reading_value', { required: 'Vui lòng nhập chỉ số công tơ.' })} />
      {form.formState.errors.reading_value && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reading_value.message}</p>}
      {preview && <div className={`mt-2 rounded-lg p-3 text-sm ${preview.consumption >= 0 ? 'bg-slate-50 text-slate-700' : 'bg-amber-50 text-amber-800'}`}>
        <p className="font-medium">Mức tiêu thụ kỳ này</p>
        <p className="mt-1 text-base font-semibold">{readingContext.formatValue(preview.consumption)}</p>
        {preview.consumption < 0 && <p className="mt-1 text-xs">Chỉ số mới không được nhỏ hơn chỉ số gần nhất.</p>}
      </div>}
    </div>
    <div>
      <label className="block text-sm font-medium" htmlFor="reading-note">Ghi chú</label>
      <textarea id="reading-note" placeholder="Ghi chú (không bắt buộc)" className="mt-1 w-full rounded border p-2" {...form.register('note')} />
      {form.formState.errors.note && <p className="mt-1 text-sm text-red-600">{form.formState.errors.note.message}</p>}
    </div>
    {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
    <button className="rounded bg-slate-900 px-4 py-2 text-sm text-white">{label}</button>
  </form>
}
