import axios from 'axios'
import { endSession, getSession, isCurrentSession } from './session.js'
import { getApiErrorMessage } from './getApiErrorMessage.js'

const staleRequest = config => new axios.CanceledError('Yêu cầu thuộc phiên đăng nhập trước đã bị hủy.', config)

export function installSessionInterceptors(client) {
  client.interceptors.request.use(config => {
    const session = getSession()
    config.session = session
    const controller = new AbortController()
    const signals = [session.controller.signal, config.signal].filter(Boolean)
    const abort = () => controller.abort()
    for (const signal of signals) {
      if (signal.aborted) abort()
      else signal.addEventListener('abort', abort, { once: true })
    }
    config.releaseSession = () => { for (const signal of signals) signal.removeEventListener('abort', abort) }
    config.signal = controller.signal
    if (session.token) config.headers.Authorization = `Bearer ${session.token}`
    else delete config.headers.Authorization
    return config
  }, error => { throw error }, { synchronous: true })

  client.interceptors.response.use(response => {
    response.config.releaseSession?.()
    if (!isCurrentSession(response.config.session)) throw staleRequest(response.config)
    return response
  }, error => {
    const config = error.config
    config?.releaseSession?.()
    if (config?.session && !isCurrentSession(config.session)) return Promise.reject(staleRequest(config))
    // A failed credential exchange is not an expired authenticated API request.
    if (error.response?.status === 401 && config?.session?.token && !['/login', '/auth/google/exchange'].includes(config.url)) {
      endSession(config.session, getApiErrorMessage(error))
    }
    return Promise.reject(error)
  })
}
