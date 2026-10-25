import axios from 'axios'
import { getMe } from './authApi.js'
import { endSession, isCurrentSession, replaceSession } from './session.js'

export async function completeLogin(token, previousSession) {
  const session = replaceSession(token, previousSession)
  if (!session) throw new axios.CanceledError('Phiên đăng nhập đã thay đổi.')
  try {
    await session.queryClient.fetchQuery({ queryKey: ['me'], queryFn: getMe })
    if (!isCurrentSession(session)) throw new axios.CanceledError('Phiên đăng nhập đã thay đổi.')
    return session
  } catch (error) {
    endSession(session)
    throw error
  }
}
