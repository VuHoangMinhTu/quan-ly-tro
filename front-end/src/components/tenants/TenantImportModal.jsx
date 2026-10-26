import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { cancelTenantImport, confirmTenantImport, downloadTenantTemplate, previewTenantImport } from '../../api/tenantImportApi'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { tenantKeys } from '../../api/tenantKeys'
import { defaultImportSelection, exportImportErrors, IMPORT_PAGE_SIZE, importStatusLabels, maskedIdentity, selectableImportRow, validateTenantImportFile } from '../../utils/tenantImport'
import { toast } from '../../notifications/toastStore'
import { notifyApiError } from '../../api/notificationTransport'

const buttonClass = 'inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50'

function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url; link.download = name; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export default function TenantImportModal({ onClose }) {
  const client = useQueryClient()
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [selected, setSelected] = useState([])
  const [page, setPage] = useState(1)
  const [acceptWarnings, setAcceptWarnings] = useState(false)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const [result, setResult] = useState(null)
  const pending = useRef(false)
  const token = useRef(null)
  const dialog = useRef(null)
  const abort = useRef(null)
  useEffect(() => {
    const focused = document.activeElement
    dialog.current?.focus()
    return () => { abort.current?.abort(); focused?.focus(); if (token.current) cancelTenantImport(token.current).catch(() => {}) }
  }, [])

  const run = async (name, work) => {
    if (pending.current) return
    pending.current = true; setBusy(name); setError('')
    try { await work() } catch (failure) { setError(getApiErrorMessage(failure)); notifyApiError(failure) }
    finally { pending.current = false; setBusy('') }
  }
  const download = () => run('download', async () => {
    const response = await downloadTenantTemplate()
    saveBlob(response.data, 'BeeHouse-mau-nguoi-thue.xlsx')
    toast.success('Đã tải file Excel mẫu.')
  })
  const upload = () => {
    const fileError = validateTenantImportFile(file)
    if (fileError) { setError(fileError); toast.warning(fileError); return }
    run('upload', async () => {
      if (token.current) { await cancelTenantImport(token.current); token.current = null }
      abort.current = new AbortController()
      const response = await previewTenantImport(file, abort.current.signal)
      const data = response.data.data
      if (data.rows.length > 1000 || data.summary.total > 1000) {
        await cancelTenantImport(data.token)
        setError('File chỉ được chứa tối đa 1.000 người thuê.')
        toast.warning('File chỉ được chứa tối đa 1.000 người thuê.')
        return
      }
      token.current = data.token; setPreview(data); setSelected(defaultImportSelection(data.rows)); setPage(1); setAcceptWarnings(false)
    })
  }
  const confirm = () => run('confirm', async () => {
    const response = await confirmTenantImport({ token: token.current, selected_rows: selected, accept_phone_warnings: acceptWarnings })
    token.current = null
    setResult(response.data.message)
    setPreview(null); setFile(null); setSelected([])
    await client.invalidateQueries({ queryKey: tenantKeys.all })
  })
  const toggle = (number) => setSelected((current) => current.includes(number) ? current.filter((value) => value !== number) : [...current, number])
  const hasSelectedWarnings = preview?.rows.some((row) => row.status === 'warning' && selected.includes(row.row))
  const close = () => { if (!pending.current) onClose() }
  const trapFocus = (event) => {
    if (event.key === 'Escape') { event.preventDefault(); close() }
    if (event.key !== 'Tab') return
    const controls = [...dialog.current.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex="0"]')]
    const first = controls[0], last = controls.at(-1)
    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.current)) { event.preventDefault(); last?.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
  }

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3 sm:p-4">
    <section ref={dialog} tabIndex={-1} onKeyDown={trapFocus} role="dialog" aria-modal="true" aria-labelledby="tenant-import-title" className="max-h-[90dvh] w-full min-w-0 max-w-5xl space-y-5 overflow-y-auto rounded-xl bg-white p-4 shadow-xl sm:p-6">
      <div className="flex items-start justify-between gap-3"><div><h2 id="tenant-import-title" className="text-xl font-bold">Import người thuê từ Excel</h2><p className="mt-1 text-sm text-slate-600">Chỉ tạo hồ sơ người thuê. Sau đó bạn có thể chọn khi tạo hợp đồng hoặc thêm người ở.</p></div><button type="button" aria-label="Đóng import" disabled={Boolean(busy)} onClick={close} className={buttonClass}>✕</button></div>
      {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {result ? <><p role="status" className="rounded-lg bg-green-50 p-4 text-green-800">{result}</p><button type="button" className={buttonClass} onClick={close}>Hoàn tất</button></> : <>
        <div><h3 className="font-semibold">1. Tải file mẫu</h3><p className="my-2 text-sm text-slate-600">Điền sheet Nguoi thue; ví dụ nằm riêng trong Huong dan. Chỉ họ tên bắt buộc. SĐT/CCCD lưu dạng Text; ngày theo DD/MM/YYYY.</p><button type="button" onClick={download} disabled={Boolean(busy)} className={buttonClass}>{busy === 'download' ? 'Đang tải...' : 'Tải file Excel mẫu'}</button></div>
        <div><h3 className="font-semibold">2. Chọn file Excel</h3><p className="my-2 text-sm text-slate-600">Chỉ .xlsx, tối đa 5 MB và 1.000 người thuê. Phiên xem trước hết hạn sau 1 giờ.</p><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-label="File danh sách người thuê" disabled={Boolean(busy)} className="block w-full min-w-0 text-sm file:mr-3 file:rounded-lg file:border file:bg-slate-50 file:px-3 file:py-3" onChange={(event) => {
          const next = event.target.files?.[0] || null
          setFile(next); setError(next ? validateTenantImportFile(next) : ''); setPreview(null); setSelected([])
          if (token.current) { cancelTenantImport(token.current).catch(() => {}); token.current = null }
        }} />{file && <p className="mt-2 break-all text-sm text-slate-500">{file.name}</p>}<button type="button" disabled={Boolean(busy) || !file || Boolean(validateTenantImportFile(file))} onClick={upload} className={buttonClass + ' mt-3'}>{busy === 'upload' ? 'Đang kiểm tra...' : 'Upload và xem trước'}</button></div>
        {preview && <>
          <div><h3 className="font-semibold">3. Xem trước và kiểm tra</h3><p role="status" className="my-3 text-sm">Tổng {preview.summary.total} dòng · Hợp lệ {preview.summary.valid} · Nghi trùng SĐT {preview.summary.warning} · Trùng {preview.summary.duplicate} · Lỗi {preview.summary.invalid}</p>
            <div className="mb-3 flex flex-wrap gap-2"><button type="button" disabled={Boolean(busy)} className={buttonClass} onClick={() => setSelected(defaultImportSelection(preview.rows))}>Chọn các dòng hợp lệ</button><button type="button" disabled={Boolean(busy)} className={buttonClass} onClick={() => setSelected([])}>Bỏ chọn tất cả</button><button type="button" className={buttonClass} onClick={() => saveBlob(new Blob([exportImportErrors(preview.rows)], { type: 'text/csv;charset=utf-8' }), 'BeeHouse-ket-qua-kiem-tra.csv')}>Tải danh sách lỗi/cảnh báo</button></div>
            <div className="space-y-2">{preview.rows.slice((page - 1) * IMPORT_PAGE_SIZE, page * IMPORT_PAGE_SIZE).map((row) => <div key={row.row} className={'grid min-w-0 gap-2 rounded-lg border p-3 text-sm sm:grid-cols-[auto_1fr_1fr_1fr] ' + (row.status === 'invalid' ? 'border-red-200 bg-red-50' : row.status === 'valid' ? 'border-slate-200' : 'border-amber-200 bg-amber-50')}>
              <label className="flex min-h-11 items-center gap-2"><input type="checkbox" disabled={Boolean(busy) || !selectableImportRow(row)} checked={selected.includes(row.row)} onChange={() => toggle(row.row)} />Dòng {row.row}</label><div className="min-w-0 break-words"><p className="font-medium">{row.data.full_name || 'Chưa có họ tên'}</p><p>{row.data.phone || 'Chưa có SĐT'}</p></div><div><p>CCCD: {maskedIdentity(row.data.identity_number)}</p><p className="font-medium">{importStatusLabels[row.status]}</p></div><p className="break-words">{[...row.errors, ...row.warnings].join(' ')}</p>
            </div>)}</div>
            {preview.rows.length > IMPORT_PAGE_SIZE && <div className="mt-3 flex items-center justify-between gap-2"><button type="button" disabled={page === 1} className={buttonClass} onClick={() => setPage(page - 1)}>Trước</button><span className="text-sm">Trang {page}/{Math.ceil(preview.rows.length / IMPORT_PAGE_SIZE)}</span><button type="button" disabled={page * IMPORT_PAGE_SIZE >= preview.rows.length} className={buttonClass} onClick={() => setPage(page + 1)}>Sau</button></div>}
          </div>
          <div className="space-y-3 border-t pt-4"><h3 className="font-semibold">4. Xác nhận nhập</h3><p className="text-sm text-slate-600">CCCD trùng và dòng lỗi sẽ được bỏ qua. Các dòng trùng số điện thoại mặc định không được chọn.</p>{hasSelectedWarnings && <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={acceptWarnings} disabled={Boolean(busy)} onChange={(event) => setAcceptWarnings(event.target.checked)} className="mt-1" />Tôi đã kiểm tra và muốn nhập những người có số điện thoại nghi trùng được chọn.</label>}<button type="button" disabled={Boolean(busy) || selected.length === 0 || (hasSelectedWarnings && !acceptWarnings)} onClick={confirm} className={buttonClass + ' w-full bg-slate-900 text-white sm:w-auto'}>{busy === 'confirm' ? 'Đang nhập...' : `Xác nhận nhập ${selected.length} người thuê`}</button></div>
        </>}
      </>}
    </section>
  </div>
}
