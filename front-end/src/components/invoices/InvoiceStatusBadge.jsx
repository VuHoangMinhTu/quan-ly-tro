import { invoiceStatusLabels } from '../../utils/invoiceStatus'
export default function InvoiceStatusBadge({status}){return <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{invoiceStatusLabels[status]||status}</span>}
