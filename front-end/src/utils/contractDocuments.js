export function validateContractTemplateVariables(content, variables) {
  const allowed = new Set(variables.map(variable => variable.key))
  for (const match of content.matchAll(/\{\{([\s\S]*?)\}\}/g)) {
    const key = match[1].trim()
    if (!allowed.has(key)) return `Biến {{${key}}} không được hỗ trợ.`
  }
  const rest = content.replace(/\{\{[\s\S]*?\}\}/g, '')
  return rest.includes('{{') || rest.includes('}}') ? 'Cú pháp biến không hợp lệ. Vui lòng chọn biến trong danh sách hỗ trợ.' : ''
}

export function insertContractTemplateVariable(content, placeholder, start, end = start) {
  return { content: content.slice(0, start) + placeholder + content.slice(end), caret: start + placeholder.length }
}

// finalized_at is a true DATETIME, unlike contract business DATE fields.
export function formatDocumentFinalizedAt(value) {
  if (!value) return 'Chưa chốt'
  const instant = new Date(value)
  return Number.isNaN(instant.getTime()) ? 'Chưa cập nhật' : new Intl.DateTimeFormat('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh', dateStyle: 'short', timeStyle: 'short' }).format(instant)
}
