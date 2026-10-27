import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { createAppQueryClient } from '../src/api/queryClient'
import InvoicePrintPage from '../src/pages/invoices/InvoicePrintPage'
import '../src/index.css'

createRoot(document.getElementById('root')).render(
  <QueryClientProvider client={createAppQueryClient()}>
    <MemoryRouter initialEntries={['/invoices/1/print']}>
      <Routes><Route path="/invoices/:id/print" element={<InvoicePrintPage />} /></Routes>
    </MemoryRouter>
  </QueryClientProvider>,
)
