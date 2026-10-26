import { createAppQueryClient } from './queryClient.js'
import { clearToasts } from '../notifications/toastStore.js'

let nextId = 0
const listeners = new Set()
const makeSession = (token, errorMessage = '') => ({ id: nextId++, token, errorMessage, controller: new AbortController(), queryClient: createAppQueryClient() })
let session = makeSession(globalThis.localStorage?.getItem('access_token') ?? null)

export const getSession = () => session
export const isCurrentSession = expected => expected === session
export const subscribeSession = listener => { listeners.add(listener); return () => listeners.delete(listener) }

// A fresh client also isolates callbacks of old mutations that cannot be undone.
export function replaceSession(token, expected = session, errorMessage = '') {
  if (!isCurrentSession(expected)) return null
  const previous = session
  if (token) localStorage.setItem('access_token', token)
  else localStorage.removeItem('access_token')
  session = makeSession(token || null, errorMessage)
  previous.controller.abort()
  void previous.queryClient.cancelQueries()
  previous.queryClient.clear()
  clearToasts()
  for (const listener of listeners) listener()
  return session
}

export const endSession = (expected, errorMessage = '') => replaceSession(null, expected, errorMessage)
