import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import SessionQueryProvider from './api/SessionQueryProvider'
import { z } from 'zod'
import './index.css'
import App from './App.jsx'
import { APP_TITLE } from './utils/brand'

z.config(z.locales.vi())
document.title = APP_TITLE

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <SessionQueryProvider><App /></SessionQueryProvider>
  </StrictMode>,
)
