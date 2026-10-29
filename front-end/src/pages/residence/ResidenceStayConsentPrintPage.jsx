import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { getResidenceDeclaration } from '../../api/residenceApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import StayConsentDocument from '../../components/residence/StayConsentDocument'
import { stayConsentPrintTitle } from '../../utils/printDocument'

export default function ResidenceStayConsentPrintPage() {
  const { id } = useParams()
  const query = useQuery({ queryKey: ['residence-stay-consent-preview', String(id)], queryFn: () => getResidenceDeclaration(id), retry: false, staleTime: 0 })
  const consent = query.data?.data?.data?.stay_consent
  const printTitle = consent ? stayConsentPrintTitle(consent) : ''
  useEffect(() => {
    if (!printTitle) return undefined
    const previous = document.title
    document.title = printTitle
    return () => { document.title = previous }
  }, [printTitle])
  if (query.isPending) return <p className="p-4">Đang kiểm tra văn bản ở nhờ...</p>
  if (query.isError) return <div className="space-y-3 p-4"><p role="alert" className="text-red-700">{getApiErrorMessage(query.error)}</p><Link to={`/residence-declarations/${id}`} className="inline-flex min-h-11 items-center underline">Quay lại hồ sơ để bổ sung dữ liệu</Link></div>
  if (!consent) return <div className="space-y-3 p-4"><p role="alert">Hồ sơ này chưa có bản văn bản ở nhờ được lưu tại thời điểm chốt.</p><Link to={`/residence-declarations/${id}`} className="underline">Quay lại hồ sơ</Link></div>
  return <main className="stay-consent-print-page min-h-dvh overflow-x-auto bg-slate-100 p-3 sm:p-6"><div className="stay-consent-print-actions mx-auto mb-4 flex max-w-3xl flex-wrap gap-3"><Link to={`/residence-declarations/${id}`} className="inline-flex min-h-11 items-center rounded-lg border bg-white px-4">Quay lại hồ sơ</Link><button type="button" className="min-h-11 rounded-lg bg-slate-900 px-4 text-white" onClick={async () => { await document.fonts.ready; window.print() }}>In / Lưu PDF</button><p className="w-full text-sm text-slate-500">Bố cục theo mẫu Word (khổ A4). Ô chưa có dữ liệu để trống; văn bản chưa được ký điện tử.</p></div><StayConsentDocument data={consent} /></main>
}
