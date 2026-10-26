const listeners = new Set()
let messages = []
let nextId = 1
const timers = new Map()
const recent = new Map()
const durations = { success: 4000, error: 7000, warning: 6000 }

export const getToasts = () => messages
export const subscribeToasts = (listener) => { listeners.add(listener); return () => listeners.delete(listener) }
const emit = () => listeners.forEach((listener) => listener())

export function dismissToast(id) {
  clearTimeout(timers.get(id))
  timers.delete(id)
  messages = messages.filter((message) => message.id !== id)
  emit()
}

export function clearToasts() {
  timers.forEach(clearTimeout)
  timers.clear(); recent.clear(); messages = []
  emit()
}

function show(type, message) {
  if (typeof message !== 'string' || !message.trim()) return
  const key = `${type}:${message.trim()}`
  const now = Date.now()
  for (const [entry, timestamp] of recent) if (now - timestamp > 2000) recent.delete(entry)
  if (messages.some((toast) => toast.key === key) || (recent.has(key) && now - recent.get(key) < 2000)) return
  recent.set(key, now)
  const id = nextId++
  if (messages.length >= 3) dismissToast(messages[0].id)
  messages = [...messages, { id, key, type, message: message.trim() }]
  const timer = setTimeout(() => dismissToast(id), durations[type])
  timer.unref?.()
  timers.set(id, timer)
  emit()
  return id
}

export const toast = {
  success: (message) => show('success', message),
  error: (message) => show('error', message),
  warning: (message) => show('warning', message),
}
