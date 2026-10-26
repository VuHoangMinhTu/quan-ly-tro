import axios from 'axios'
import { getApiErrorMessage } from './getApiErrorMessage.js'
import { toast } from '../notifications/toastStore.js'

const reportedErrors = new WeakSet()
const isWrite = (config) => ['post', 'put', 'patch', 'delete'].includes(config?.method?.toLowerCase())

export function notifyApiError(error, fallback) {
  if (axios.isCancel(error) || (error && typeof error === 'object' && reportedErrors.has(error))) return
  if (error && typeof error === 'object') reportedErrors.add(error)
  const message = error?.response || error?.isAxiosError ? getApiErrorMessage(error) : fallback || 'Không thể thực hiện thao tác. Vui lòng thử lại.'
  if (error?.response?.status === 409) toast.warning(message)
  else toast.error(message)
}

// One notification owner for all API writes, including direct calls outside useMutation.
// config.toast=false opts out entirely; {success:false} silences intermediate successes only.
export function installNotificationInterceptors(client) {
  client.interceptors.response.use((response) => {
    const config = response.config
    if (!isWrite(config) || config.toast === false) return response
    if (response.data?.success === false) {
      const error = new axios.AxiosError('Thao tác không thành công.', 'ERR_BAD_RESPONSE', config, null, response)
      notifyApiError(error)
      throw error
    } else if (config.toast?.success !== false) {
      const message = config.toast?.success || response.data?.message
      toast.success(typeof message === 'string' && message.trim() ? message : 'Đã thực hiện thao tác thành công.')
    }
    return response
  }, (error) => {
    if (isWrite(error.config) && error.config.toast !== false) notifyApiError(error)
    return Promise.reject(error)
  })
}
