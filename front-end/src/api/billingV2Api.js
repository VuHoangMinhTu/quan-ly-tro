import client from './axiosClient'
import axios from 'axios'
import { notifyApiError } from './notificationTransport'
import { toast } from '../notifications/toastStore'

async function write(url, payload) {
  try {
    const response = await client.post(url, payload, { toast: false })
    if (response.data?.success === false) throw Object.assign(new Error('Thao tác chưa được xác nhận.'), { response })
    const result = response.data?.data
    const needsAttention = Array.isArray(result) ? result.some(row => ['BLOCKED', 'PENDING_FINAL_SETTLEMENT'].includes(row.status)) : ['BLOCKED', 'PENDING_FINAL_SETTLEMENT'].includes(result?.status)
    if (needsAttention) toast.warning('Có phòng hoặc khoản tính toán đang chờ/bị chặn. Xem lý do chi tiết trước khi tiếp tục.')
    else toast.success(response.data?.message || 'Đã ghi nhận thành công.')
    return response
  } catch (error) {
    if (axios.isCancel(error)) throw error
    if (!error.response || error.response.status >= 500) {
      error.billingV2Message = 'Chưa xác định thao tác đã được ghi nhận hay chưa. Giữ nguyên dữ liệu và mã giao dịch khi thử lại; hệ thống sẽ kiểm tra để tránh ghi trùng.'
      toast.warning(error.billingV2Message)
    } else notifyApiError(error)
    throw error
  }
}

export const getBillingV2 = (room, period) => client.get(`/billing-v2/rooms/${room}`, { params: { billing_period: period } })
export const getBillingV2Status = () => client.get('/billing-v2/status')
export const getMonthlyBillingOptions = (house, period) => client.get(`/billing-v2/boarding-houses/${house}/monthly-options`, { params: { billing_period: period } })
export const generateRoomMonthlyInvoice = (room, payload) => write(`/billing-v2/rooms/${room}/monthly-invoices`, payload)
export const generateDormitoryBedInvoice = (contract, payload) => write(`/billing-v2/contracts/${contract}/bed-invoices`, payload)
export const generateMonthlyInvoices = (house, payload) => write(`/billing-v2/boarding-houses/${house}/monthly-invoices`, payload)
export const previewMonthlyDraft = (room, payload) => write(`/billing-v2/rooms/${room}/monthly-recalculations/preview`, payload)
export const confirmMonthlyDraft = (room, token, reason) => write(`/billing-v2/rooms/${room}/monthly-recalculations/${token}/confirm`, { confirmed: true, reason })
export const confirmBillingCheckpoint = (meter, payload) => write(`/billing-v2/meters/${meter}/checkpoints`, payload)
export const confirmBillingPeriodBoundaries = (meter, payload) => write(`/billing-v2/meters/${meter}/period-boundaries`, payload)
export const correctBillingPeriodBoundaries = (meter, payload) => write(`/billing-v2/meters/${meter}/period-boundaries/correct`, payload)
export const confirmBillingCheckout = (contract, payload) => write(`/billing-v2/contracts/${contract}/checkout`, payload)
export const recordBillingDeposit = (contract, payload) => write(`/billing-v2/contracts/${contract}/deposits`, payload)
export const generateBillingV2 = (room, billingPeriod, usageStartAt, usageEndAt, meterPeriods = []) => write(`/billing-v2/rooms/${room}/generate`, { billing_period: billingPeriod, usage_start_at: usageStartAt, usage_end_at: usageEndAt, meter_periods: meterPeriods })
export const issueBillingV2 = invoice => write(`/billing-v2/invoices/${invoice}/issue`, {})
export const confirmBillingSettlement = contract => write(`/billing-v2/contracts/${contract}/settlement-confirmation`, { confirm_no_held_deposit: true, reason: 'Chủ trọ xác nhận không có tiền cọc thực tế còn giữ; không tạo giao dịch mất cọc giả.' })
