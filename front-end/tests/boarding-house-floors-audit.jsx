import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { createAppQueryClient } from '../src/api/queryClient'
import BoardingHouseCreatePage from '../src/pages/boarding-houses/BoardingHouseCreatePage'
import ToastProvider from '../src/notifications/ToastProvider'
import '../src/index.css'

createRoot(document.getElementById('root')).render(
  <ToastProvider><QueryClientProvider client={createAppQueryClient()}><MemoryRouter initialEntries={['/boarding-houses/new']}>
    <Routes><Route path="/boarding-houses/new" element={<BoardingHouseCreatePage />} /><Route path="/boarding-houses/:id" element={<p>Đã tạo nhà trọ kiểm thử</p>} /></Routes>
  </MemoryRouter></QueryClientProvider></ToastProvider>,
)
