import { toast } from './toastStore.js'

const pendingForms = new WeakSet()

export function handleFormSubmit(form, onValid) {
  return async event => {
    event?.preventDefault()
    if (pendingForms.has(form)) return
    pendingForms.add(form)
    try {
      await form.handleSubmit(onValid, notifyInvalidSubmit)(event)
    } finally {
      pendingForms.delete(form)
    }
  }
}

export function notifyInvalidSubmit(errors) {
  const firstMessage = fields => {
    if (!fields || typeof fields !== 'object') return ''
    if (typeof fields.message === 'string' && fields.message.trim()) return fields.message
    for (const [key, field] of Object.entries(fields)) {
      if (key === 'ref' || key === 'types') continue
      const message = firstMessage(field)
      if (message) return message
    }
    return ''
  }
  toast.warning(firstMessage(errors) || 'Dữ liệu chưa hợp lệ. Vui lòng kiểm tra các trường được đánh dấu.')
}
