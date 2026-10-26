import { useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { dismissToast, getToasts, subscribeToasts } from './toastStore'

const styles = {
  success: 'border-green-300 bg-green-50 text-green-950',
  error: 'border-red-300 bg-red-50 text-red-950',
  warning: 'border-amber-300 bg-amber-50 text-amber-950',
}
const labels = { success: 'Thành công', error: 'Không thành công', warning: 'Lưu ý' }

export default function ToastProvider({ children }) {
  const messages = useSyncExternalStore(subscribeToasts, getToasts, getToasts)
  return <>{children}{createPortal(
    <div aria-label="Thông báo" className="pointer-events-none fixed inset-x-0 top-0 z-[2147483647] mx-auto flex w-full max-w-md flex-col gap-2 px-3 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))]">
      <div role="status" aria-live="polite" aria-atomic="false" className="contents">{messages.filter((message) => message.type !== 'error').map((message) => <Toast key={message.id} notification={message} />)}</div>
      <div role="alert" aria-live="assertive" aria-atomic="false" className="contents">{messages.filter((message) => message.type === 'error').map((message) => <Toast key={message.id} notification={message} />)}</div>
    </div>, document.body,
  )}</>
}

function Toast({ notification }) {
  return <div className={`pointer-events-auto flex min-w-0 items-start gap-2 rounded-xl border p-3 shadow-lg ${styles[notification.type]}`}>
    <div className="min-w-0 flex-1"><p className="text-sm font-semibold">{labels[notification.type]}</p><p className="mt-1 break-words text-sm">{notification.message}</p></div>
    <button type="button" onClick={() => dismissToast(notification.id)} aria-label="Đóng thông báo" className="flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-black/5 focus-visible:outline focus-visible:outline-2">✕</button>
  </div>
}
