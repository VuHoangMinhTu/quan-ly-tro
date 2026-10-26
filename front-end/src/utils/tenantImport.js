export const IMPORT_PAGE_SIZE = 20
export const importStatusLabels = { valid: 'Hợp lệ', warning: 'Nghi trùng SĐT', duplicate: 'Đã tồn tại', invalid: 'Dòng lỗi' }

export function validateTenantImportFile(file) {
  if (!file) return 'Vui lòng chọn file Excel.'
  if (!/\.xlsx$/i.test(file.name)) return 'Chỉ chấp nhận file Excel .xlsx.'
  if (file.size > 5 * 1024 * 1024) return 'File Excel vượt quá dung lượng cho phép (5 MB).'
  if (file.size === 0) return 'File Excel rỗng.'
  return ''
}

export const selectableImportRow = (row) => ['valid', 'warning'].includes(row.status)
export const defaultImportSelection = (rows) => rows.filter((row) => row.status === 'valid').map((row) => row.row)
export const maskedIdentity = (value) => value ? '••••' + value.slice(-4) : '—'

export function exportImportErrors(rows) {
  const escape = (value) => '"' + String(value ?? '').replace(/"/g, '""') + '"'
  // Only static status/error text is exported; identifying data stays in the preview.
  return '\uFEFF' + [['Dòng Excel', 'Trạng thái', 'Nội dung'], ...rows.filter((row) => row.status !== 'valid').map((row) => [row.row, importStatusLabels[row.status], [...row.errors, ...row.warnings].join(' ')])].map((row) => row.map(escape).join(',')).join('\r\n')
}
