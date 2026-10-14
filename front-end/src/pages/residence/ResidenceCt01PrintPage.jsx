import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { exportResidenceDeclaration } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import Ct01Document from '../../components/residence/Ct01Document'

export default function ResidenceCt01PrintPage() {
  const { id } = useParams()
  const query = useQuery({ queryKey: ['residence-export', String(id)], queryFn: () => exportResidenceDeclaration(id), retry: false, staleTime: 0 })
  if (query.isPending) return <p className="p-4">Đang kiểm tra dữ liệu CT01...</p>
  if (query.isError) return <div className="space-y-3 p-4"><p role="alert" className="text-red-700">{getApiErrorMessage(query.error)}</p><Link to={`/residence-declarations/${id}`} className="inline-flex min-h-11 items-center underline">Quay lại hồ sơ để bổ sung dữ liệu</Link></div>
  return <main className="ct01-print-page min-h-dvh bg-slate-100 p-3 sm:p-6"><div className="ct01-print-actions mx-auto mb-4 flex max-w-3xl flex-wrap gap-3"><Link to={`/residence-declarations/${id}`} className="inline-flex min-h-11 items-center rounded-lg border bg-white px-4">Quay lại hồ sơ</Link><button type="button" className="min-h-11 rounded-lg bg-slate-900 px-4 text-white" onClick={async () => { await document.fonts.ready; window.print() }}>In CT01 / Lưu PDF</button><p className="w-full text-sm text-slate-500">In A4 dọc. Đây là bản hỗ trợ điền; không tự tạo chữ ký hay ý kiến đồng ý.</p></div><Ct01Document data={query.data.data.data.ct01} /></main>
}
