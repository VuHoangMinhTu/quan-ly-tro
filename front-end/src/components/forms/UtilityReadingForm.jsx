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
      setError('Ng\u00e0y ghi ph\u1ea3i theo \u0111\u1ecbnh d\u1ea1ng dd/mm/yyyy v\u00e0 h\u1ee3p l\u1ec7.')
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
      <label className="block text-sm font-medium">Ng\u00e0y ghi *</label>
      <Controller name="reading_date" control={form.control} rules={{ required: 'Vui l\u00f2ng nh\u1eadp ng\u00e0y ghi.' }} render={({ field }) => <DateInput {...field} placeholder="dd/mm/yyyy" invalid={Boolean(form.formState.errors.reading_date)} />} />
      {form.formState.errors.reading_date && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reading_date.message}</p>}
    </div>
    <div>
      <label className="block text-sm font-medium" htmlFor="reading-value">Ch\u1ec9 s\u1ed1 c\u00f4ng t\u01a1 hi\u1ec7n t\u1ea1i *</label>
      <input id="reading-value" type="number" min="0" step="0.01" placeholder="VD: 150" className="mt-1 w-full rounded border p-2" {...form.register('reading_value', { required: 'Vui l\u00f2ng nh\u1eadp ch\u1ec9 s\u1ed1 c\u00f4ng t\u01a1.' })} />
      {form.formState.errors.reading_value && <p className="mt-1 text-sm text-red-600">{form.formState.errors.reading_value.message}</p>}
      {preview && <div className={`mt-2 rounded-lg p-3 text-sm ${preview.consumption >= 0 ? 'bg-slate-50 text-slate-700' : 'bg-amber-50 text-amber-800'}`}>
        <p className="font-medium">M\u1ee9c ti\u00eau th\u1ee5 k\u1ef3 n\u00e0y</p>
        <p className="mt-1 text-base font-semibold">{readingContext.formatValue(preview.consumption)}</p>
        {preview.consumption < 0 && <p className="mt-1 text-xs">Ch\u1ec9 s\u1ed1 m\u1edbi kh\u00f4ng \u0111\u01b0\u1ee3c nh\u1ecf h\u01a1n ch\u1ec9 s\u1ed1 g\u1ea7n nh\u1ea5t.</p>}
      </div>}
    </div>
    <div>
      <label className="block text-sm font-medium" htmlFor="reading-note">Ghi ch\u00fa</label>
      <textarea id="reading-note" placeholder="Ghi ch\u00fa (kh\u00f4ng b\u1eaft bu\u1ed9c)" className="mt-1 w-full rounded border p-2" {...form.register('note')} />
      {form.formState.errors.note && <p className="mt-1 text-sm text-red-600">{form.formState.errors.note.message}</p>}
    </div>
    {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
    <button className="rounded bg-slate-900 px-4 py-2 text-sm text-white">{label}</button>
  </form>
}
