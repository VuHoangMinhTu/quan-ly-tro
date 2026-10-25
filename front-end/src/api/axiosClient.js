import axios from 'axios'
import { installSessionInterceptors } from './sessionTransport'
const client = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
installSessionInterceptors(client)
export default client
