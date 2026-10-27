import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom'
import { createAppQueryClient } from '../src/api/queryClient'
import InvoiceCreatePage from '../src/pages/invoices/InvoiceCreatePage'
import ToastProvider from '../src/notifications/ToastProvider'
import '../src/index.css'

createRoot(document.getElementById('root')).render(
  <StrictMode><ToastProvider><QueryClientProvider client={createAppQueryClient()}><MemoryRouter initialEntries={['/rooms/1/invoices/create']}>
    <nav><Link to="/rooms/1/invoices/create">Phòng riêng</Link> · <Link to="/rooms/2/invoices/create">Phòng trống</Link> · <Link to="/rooms/3/invoices/create">Phòng KTX</Link></nav>
    <Routes><Route path="/rooms/:roomId/invoices/create" element={<InvoiceCreatePage />} /><Route path="/rooms/:roomId/invoices" element={<p>Đã về danh sách hóa đơn</p>} /></Routes>
  </MemoryRouter></QueryClientProvider></ToastProvider></StrictMode>,
)
