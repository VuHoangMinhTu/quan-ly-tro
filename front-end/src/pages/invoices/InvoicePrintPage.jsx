import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Printer } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import '@fontsource/be-vietnam-pro/400.css'
import '@fontsource/be-vietnam-pro/500.css'
import '@fontsource/be-vietnam-pro/600.css'
import '@fontsource/be-vietnam-pro/700.css'
import '@fontsource/be-vietnam-pro/800.css'
import { getInvoice } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoicePrintLayout from '../../components/invoices/InvoicePrintLayout'
import { APP_TITLE } from '../../utils/brand'
import './InvoicePrintPage.css'

export default function InvoicePrintPage() {
  const { id } = useParams()
  const invoiceQuery = useQuery({ queryKey: invoiceKeys.detail(id), queryFn: () => getInvoice(id) })
  const invoice = invoiceQuery.data?.data?.data

  useEffect(() => {
    if (!invoice?.invoice_code) return undefined
    const previousTitle = document.title
    document.title = APP_TITLE
    return () => { document.title = previousTitle }
  }, [invoice?.invoice_code])

  if (invoiceQuery.isPending) return <main className="invoice-print-state">Đang tải hóa đơn...</main>
  if (invoiceQuery.isError || !invoice) return <main className="invoice-print-state"><p>{getApiErrorMessage(invoiceQuery.error)}</p><Link to={`/invoices/${id}`}>Quay lại chi tiết</Link></main>

  const printInvoice = async () => {
    await document.fonts?.ready
    window.print()
  }

  return <main className="invoice-print-page">
    <nav className="invoice-print-actions" aria-label="Thao tác in hóa đơn">
      <Link to={`/invoices/${id}`}><ArrowLeft size={17} /> Quay lại</Link>
      <button type="button" onClick={printInvoice}><Printer size={17} /> In hóa đơn</button>
    </nav>
    <InvoicePrintLayout invoice={invoice} />
  </main>
}
