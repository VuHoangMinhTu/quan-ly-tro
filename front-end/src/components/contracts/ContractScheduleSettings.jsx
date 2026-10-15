import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { updateContractSchedule } from '../../api/contractApi'
import { boardingHouseKeys } from '../../api/boardingHouseKeys'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'

export default function ContractScheduleSettings({ house }) {
  const client = useQueryClient()
  const [values, setValues] = useState(() => Object.fromEntries(['default_invoice_issue_day_from', 'default_invoice_issue_day_to', 'default_payment_due_day'].map(key => [key, house[key] ?? ''])))
  const [message, setMessage] = useState('')
  const save = useMutation({ mutationFn: payload => updateContractSchedule(house.id, payload), onSuccess: async () => { setMessage('Đã lưu lịch mặc định. Hợp đồng đã tạo không bị thay đổi.'); await Promise.all([client.invalidateQueries({ queryKey: ['contract-context'] }), client.invalidateQueries({ queryKey: boardingHouseKeys.detail(String(house.id)) })]) } })
  return <form className="mt-6 space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-6" onSubmit={event => { event.preventDefault(); setMessage(''); save.mutate(Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === '' ? null : Number(value)]))) }}><h3 className="text-lg font-semibold">Lịch thanh toán mặc định cho hợp đồng mới</h3><p className="text-sm text-slate-500">Chỉ dùng để gợi ý khi tạo hợp đồng. Không tự đổi hợp đồng cũ hoặc lịch hóa đơn hiện có.</p><div className="grid gap-4 sm:grid-cols-3">{[['default_invoice_issue_day_from', 'Ngày bắt đầu gửi hóa đơn'], ['default_invoice_issue_day_to', 'Ngày cuối gửi hóa đơn'], ['default_payment_due_day', 'Ngày hạn thanh toán']].map(([key, label]) => <label key={key} className="text-sm">{label}<input type="number" min="1" max="31" value={values[key]} onChange={event => setValues({ ...values, [key]: event.target.value })} className="mt-1 min-h-11 w-full min-w-0 rounded-lg border p-2" placeholder="Chưa thiết lập" /></label>)}</div>{save.isError && <p role="alert" className="text-sm text-red-700">{getApiErrorMessage(save.error)}</p>}{message && <p role="status" className="text-sm text-green-700">{message}</p>}<button disabled={save.isPending} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">Lưu lịch mặc định</button></form>
}
