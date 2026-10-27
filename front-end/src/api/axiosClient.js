import axios from 'axios'
import { installSessionInterceptors } from './sessionTransport'
import { installNotificationInterceptors } from './notificationTransport'
const client = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL, timeout: 30000, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
installSessionInterceptors(client)
installNotificationInterceptors(client)
export default client
