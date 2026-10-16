import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { createAppQueryClient } from './api/queryClient'
import { z } from 'zod'
import './index.css'
import App from './App.jsx'
import { APP_TITLE } from './utils/brand'

z.config(z.locales.vi())
document.title = APP_TITLE

const queryClient = createAppQueryClient()
createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}><App /></QueryClientProvider>
  </StrictMode>,
)
