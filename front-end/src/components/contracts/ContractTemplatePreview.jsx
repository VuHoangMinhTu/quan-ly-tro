export default function ContractTemplatePreview({ html }) {
  const source = `<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><style>body{margin:0;padding:20px;font:15px/1.7 Arial,sans-serif;color:#0f172a;overflow-wrap:anywhere}table{width:100%;table-layout:fixed;border-collapse:collapse}td,th{border:1px solid #cbd5e1;padding:8px;vertical-align:top}h1{font-size:24px}h2{font-size:19px}.text-center{text-align:center}.text-right{text-align:right}.text-justify{text-align:justify}</style></head><body>${html}</body></html>`
  return <iframe title="Xem trước mẫu hợp đồng bằng dữ liệu minh họa" sandbox="" referrerPolicy="no-referrer" srcDoc={source} className="h-[65dvh] min-h-80 w-full min-w-0 rounded-lg border bg-white" />
}
