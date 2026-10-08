const labels = { DRAFT: 'Nháp', ACTIVE: 'Đang hiệu lực', EXPIRED: 'Hết hạn', TERMINATED: 'Đã chấm dứt' }
const styles = { DRAFT: 'bg-slate-100 text-slate-700', ACTIVE: 'bg-green-100 text-green-800', EXPIRED: 'bg-amber-100 text-amber-800', TERMINATED: 'bg-red-100 text-red-800' }
export default function ContractStatusBadge({ status }) { return <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${styles[status] || styles.DRAFT}`}>{labels[status] || status}</span> }
