import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { createAppQueryClient } from '../src/api/queryClient'
import ContractTemplateListPage from '../src/pages/contracts/ContractTemplateListPage'
import ContractDemoPage from '../src/pages/contracts/ContractDemoPage'
import ContractDocumentPage from '../src/pages/contracts/ContractDocumentPage'
import '../src/index.css'

createRoot(document.getElementById('root')).render(
  <QueryClientProvider client={createAppQueryClient()}><BrowserRouter><Routes>
    <Route path="/contract-templates" element={<ContractTemplateListPage />} />
    <Route path="/contract-templates/demos/:type" element={<ContractDemoPage />} />
    <Route path="/contracts/:id/document" element={<ContractDocumentPage />} />
  </Routes></BrowserRouter></QueryClientProvider>,
)
