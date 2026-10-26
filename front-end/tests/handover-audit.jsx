/* oxlint-disable react/immutability, react/only-export-components -- Audit-only render instrumentation and standalone entry point. */
import React, { Profiler } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import ContractDetailPage from '../src/pages/contracts/ContractDetailPage'
import ToastProvider from '../src/notifications/ToastProvider'
import { createAppQueryClient } from '../src/api/queryClient'
import { clearToasts, toast, getToasts } from '../src/notifications/toastStore'
import '../src/index.css'

window.__audit = { parentRenders: 0, detailRenders: 0, handoverRenders: 0, toastRenders: 0, commits: [], shifts: [], visible: [], starts: [] }
window.__audit.clear = clearToasts
window.__audit.toast = toast
window.__audit.snapshot = getToasts
const client = createAppQueryClient()
window.__audit.client = client
const record = (id, phase, actualDuration) => window.__audit.commits.push({ id, phase, actualDuration })
function AuditParent() {
  window.__audit.parentRenders++
  return <QueryClientProvider client={client}><MemoryRouter initialEntries={['/contracts/1']}><Routes><Route path="/contracts/:id" element={<ContractDetailPage />} /></Routes></MemoryRouter></QueryClientProvider>
}
document.addEventListener('click', (event) => {
  const button = event.target.closest('button')
  if (button && ['Lưu nháp', 'Xác nhận bàn giao'].includes(button.textContent)) window.__audit.starts.push(performance.now())
}, true)
let visibleText = ''
new MutationObserver(() => {
  const root = document.querySelector('[aria-label="Thông báo"]')
  const content = root?.innerText.trim()
  if (content && content !== visibleText) {
    visibleText = content
    requestAnimationFrame(() => requestAnimationFrame(() => window.__audit.visible.push({ time: performance.now(), text: content })))
  }
  if (!content) visibleText = ''
}).observe(document.body, { subtree: true, childList: true, characterData: true })
new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) window.__audit.shifts.push({ value: entry.value, recentInput: entry.hadRecentInput })
}).observe({ type: 'layout-shift', buffered: true })
createRoot(document.getElementById('root')).render(<Profiler id="app" onRender={record}><ToastProvider><AuditParent /></ToastProvider></Profiler>)
