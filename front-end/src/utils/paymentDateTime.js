export function paymentDateTime(value = new Date()) {
  if (typeof value === 'string' && !/(Z|[+-]\d{2}:\d{2})$/.test(value)) return value.replace(' ', 'T').slice(0, 16)
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).format(date).replace(' ', 'T')
}
