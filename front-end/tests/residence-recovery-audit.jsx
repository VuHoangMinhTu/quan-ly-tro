import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { createAppQueryClient } from '../src/api/queryClient'
import ToastProvider from '../src/notifications/ToastProvider'
import ResidenceDeclarationEditPage from '../src/pages/residence/ResidenceDeclarationEditPage'
import ResidenceDeclarationDetailPage from '../src/pages/residence/ResidenceDeclarationDetailPage'
import ResidenceCt01PrintPage from '../src/pages/residence/ResidenceCt01PrintPage'
import ResidenceStayConsentPrintPage from '../src/pages/residence/ResidenceStayConsentPrintPage'
import '../src/index.css'

createRoot(document.getElementById('root')).render(
  <ToastProvider><QueryClientProvider client={createAppQueryClient()}><MemoryRouter initialEntries={[new URLSearchParams(location.search).get('route') || '/residence-declarations/create']}>
    <Routes>
      <Route path="/residence-declarations/create" element={<ResidenceDeclarationEditPage />} />
      <Route path="/residence-declarations/:id/edit" element={<ResidenceDeclarationEditPage />} />
      <Route path="/residence-declarations/:id" element={<ResidenceDeclarationDetailPage />} />
      <Route path="/residence-declarations/:id/print" element={<ResidenceCt01PrintPage />} />
      <Route path="/residence-declarations/:id/stay-consent/print" element={<ResidenceStayConsentPrintPage />} />
    </Routes>
  </MemoryRouter></QueryClientProvider></ToastProvider>,
)
