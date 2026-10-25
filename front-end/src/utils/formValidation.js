import { z } from 'zod'
import { formatDateForApi, isValidBillingPeriod, isValidDisplayDate } from './date.js'

export const MAX_MONEY = 9999999999999.99
export const isBlank = value => value == null || (typeof value === 'string' && value.trim() === '')

// Empty optional numbers stay null; malformed values must never silently become 0.
export function numberInput(label, { optional = false, emptyValue = null, min = 0, max, integer = false } = {}) {
  let number = z.number({ error: `${label} phải là số hợp lệ.` })
  if (integer) number = number.int(`${label} phải là số nguyên.`)
  number = number.min(min, `${label} phải từ ${min} trở lên.`)
  if (max != null) number = number.max(max, `${label} vượt giới hạn cho phép (${max}).`)
  return z.preprocess(value => {
    if (isBlank(value)) return optional ? emptyValue : NaN
    if (typeof value !== 'number' && typeof value !== 'string') return NaN
    return Number(value)
  }, optional && emptyValue === null ? number.nullable() : number)
}

export function numberFieldError(value, label, options) {
  const result = numberInput(label, options).safeParse(value)
  return result.success ? true : result.error.issues[0].message
}

export const selectedId = label => {
  const message = `Vui lòng chọn ${label.toLocaleLowerCase('vi-VN')} hợp lệ.`
  return z.preprocess(value => isBlank(value) ? NaN : Number(value), z.number({ error: message }).int(message).min(1, message).refine(Number.isSafeInteger, message))
}

export const formDate = (label, required = false) => z.string({ error: `${label} không hợp lệ.` }).trim()
  .refine(value => !required || Boolean(value), `Vui lòng nhập ${label.toLocaleLowerCase('vi-VN')}.`)
  .refine(value => !value || isValidDisplayDate(value), `${label} phải theo dd/mm/yyyy và hợp lệ.`)

export const contractFormSchema = z.object({
  tenant_id: selectedId('Người thuê'),
  auto_code: z.boolean().default(false),
  contract_code: z.string().trim().max(100, 'Mã hợp đồng tối đa 100 ký tự.').nullable().optional(),
  start_date: formDate('Ngày bắt đầu'), end_date: formDate('Ngày kết thúc'), signed_date: formDate('Ngày ký'),
  monthly_rent: numberInput('Giá thuê', { optional: true, max: MAX_MONEY }),
  deposit_amount: numberInput('Tiền cọc', { optional: true, emptyValue: 0, max: MAX_MONEY }),
  status: z.enum(['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'], { error: 'Vui lòng chọn trạng thái hợp đồng hợp lệ.' }),
  note: z.string({ error: 'Ghi chú phải là nội dung văn bản.' }).optional(),
}).superRefine((values, ctx) => {
  const issue = (field, message) => ctx.addIssue({ code: 'custom', path: [field], message })
  if (!values.auto_code && !values.contract_code?.trim()) issue('contract_code', 'Vui lòng nhập mã hợp đồng.')
  if (values.status === 'ACTIVE') {
    if (!values.start_date) issue('start_date', 'Vui lòng nhập ngày bắt đầu trước khi kích hoạt.')
    if (values.monthly_rent == null) issue('monthly_rent', 'Vui lòng nhập giá thuê trước khi kích hoạt.')
  }
}).refine(values => formatDateForApi(values.end_date) >= formatDateForApi(values.start_date), {
  path: ['end_date'], message: 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
  // Date order is independent of unrelated invalid fields such as tenant_id.
  when: ({ value }) => isValidDisplayDate(value?.start_date) && isValidDisplayDate(value?.end_date),
})

export function invoiceFormSchema(canEditFinancials = true) {
  return z.object({
    ...(canEditFinancials ? {
      contract_id: z.union([z.literal(''), selectedId('Hợp đồng')], { error: 'Vui lòng chọn hợp đồng hợp lệ.' }),
      auto_code: z.boolean(),
      invoice_code: z.string().trim().max(100, 'Mã hóa đơn tối đa 100 ký tự.').nullable().optional(),
      billing_period: z.string().refine(isValidBillingPeriod, 'Vui lòng nhập kỳ hóa đơn theo định dạng mm/yyyy.'),
      discount_amount: numberInput('Giảm giá', { optional: true, emptyValue: 0 }),
      status: z.enum(['DRAFT', 'UNPAID', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'], { error: 'Vui lòng chọn trạng thái hóa đơn hợp lệ.' }),
    } : {}),
    issued_at: formDate('Ngày phát hành'), due_date: formDate('Ngày đến hạn'),
    note: z.string({ error: 'Ghi chú phải là nội dung văn bản.' }),
  }).passthrough().superRefine((values, ctx) => {
    if (canEditFinancials && !values.auto_code && !values.invoice_code?.trim()) ctx.addIssue({ code: 'custom', path: ['invoice_code'], message: 'Vui lòng nhập mã hóa đơn.' })
  })
}

export function paymentFormSchema(remaining) {
  return z.object({
    amount: numberInput('Số tiền thanh toán').refine(value => value > 0, 'Số tiền phải lớn hơn 0.')
      .refine(value => Number.isFinite(Number(remaining)) && value <= Number(remaining), 'Số tiền thanh toán không được vượt quá số tiền còn lại.'),
    payment_method: z.enum(['CASH', 'BANK_TRANSFER', 'CARD', 'OTHER'], { error: 'Vui lòng chọn phương thức thanh toán hợp lệ.' }),
    paid_at: z.string().refine(value => {
      const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value)
      return Boolean(match && isValidDisplayDate(`${match[3]}/${match[2]}/${match[1]}`) && Number(match[4]) < 24 && Number(match[5]) < 60 && Number(match[6] ?? 0) < 60)
    }, 'Vui lòng nhập ngày và giờ thanh toán hợp lệ.'),
    reference_code: z.string().max(255, 'Mã tham chiếu tối đa 255 ký tự.'),
    note: z.string().max(500, 'Ghi chú tối đa 500 ký tự.'),
  })
}

export const dormRoomFormSchema = z.object({
  room_code: z.string().trim().min(1, 'Vui lòng nhập mã phòng.').max(50, 'Mã phòng tối đa 50 ký tự.'),
  room_name: z.string().max(255, 'Tên phòng tối đa 255 ký tự.'),
  area: numberInput('Diện tích', { optional: true, max: 99999999.99 }),
  description: z.string(),
  beds: z.array(z.object({
    bed_code: z.string().trim().min(1, 'Vui lòng nhập mã giường.').max(50, 'Mã giường tối đa 50 ký tự.'),
    monthly_rent: numberInput('Giá thuê giường', { optional: true, max: MAX_MONEY }),
    status: z.enum(['AVAILABLE', 'MAINTENANCE'], { error: 'Vui lòng chọn trạng thái giường hợp lệ.' }),
  })).min(1, 'Vui lòng thêm ít nhất một giường.').max(100, 'Chỉ được tạo tối đa 100 giường.'),
}).superRefine(({ beds }, ctx) => {
  const seen = new Map()
  beds.forEach((bed, index) => {
    const code = bed.bed_code.toLocaleLowerCase('vi-VN')
    if (seen.has(code)) for (const position of [seen.get(code), index]) ctx.addIssue({ code: 'custom', path: ['beds', position, 'bed_code'], message: 'Mã giường bị trùng trong danh sách.' })
    else seen.set(code, index)
  })
})
