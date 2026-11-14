import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import 'virtual:beehouse-dashboard-demo-guard'
import '../src/index.css'
import '../src/pages/dashboard/dashboard-premium.css'
import DashboardContent from '../src/components/dashboard/DashboardContent'
import { ContractExpiryChart, IncomeExpenseChart, InvoiceHistoryChart } from '../src/components/dashboard/DashboardSeriesCharts'
import { demoDashboard, demoSeries } from './dashboard-demo-fixture'

createRoot(document.getElementById('root')).render(
  <MemoryRouter>
    <main className="app-shell app-content bh-premium" onClick={event => {
      if (event.target.closest('a')) event.preventDefault()
    }}>
      <DashboardContent data={demoDashboard}
        headerLabel={<span className="bh-demo-banner">DỮ LIỆU MINH HỌA</span>}
        invoiceChart={<InvoiceHistoryChart rows={demoSeries.invoices} />}
        expenseChart={<IncomeExpenseChart rows={demoSeries.cash} />}
        contractChart={<ContractExpiryChart rows={demoSeries.contracts} />} />
    </main>
  </MemoryRouter>,
)
