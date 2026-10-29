export const dormHandoverItems = [
  ['Khung giường, vạt giường, rèm che riêng tư', 'bộ'],
  ['Nệm nằm, drap/ga bọc nệm (nếu có)', 'bộ'],
  ['Tủ quần áo/tủ đồ cá nhân + chìa khóa', 'tủ'],
  ['Đèn đọc sách tại giường, ổ cắm điện', 'bộ'],
  ['Khóa vân tay / Thẻ từ / Chìa khóa cửa chính KTX', 'cái'],
  ['Máy lạnh phòng + Remote điều hòa', 'Bộ'],
]

function removeTrailingPageBreaks(document) {
  for (const pageBreak of [...document.querySelectorAll('.page-break')]) {
    const laterContent = [...pageBreak.parentElement.children]
      .slice([...pageBreak.parentElement.children].indexOf(pageBreak) + 1)
      .some(node => node.textContent.trim() || node.querySelector('img, table, hr'))
    if (!laterContent) pageBreak.remove()
  }
}

function dormTable(document, table) {
  const headers = [...(table.rows[0]?.cells ?? [])].map(cell => cell.textContent.trim())
  const generic = headers.length === 3 && headers[0] === 'Tài sản'
  const oldDynamic = headers.length === 5 && headers.includes('Ghi chú')
  if (!generic && !oldDynamic) return table
  const recorded = new Map([...table.querySelectorAll('tbody tr')].map(row => {
    const cells = [...row.cells].map(cell => cell.textContent.trim())
    const offset = generic ? 0 : 1
    return [cells[offset], { quantity: cells[offset + 1], condition: cells[offset + 2], note: cells[offset + 3] }]
  }).filter(([name]) => name))
  const names = [...dormHandoverItems, ...[...recorded.keys()].filter(name => !dormHandoverItems.some(([label]) => label === name)).map(name => [name, ''])]
  const result = document.createElement('table')
  result.className = 'contract-handover-table'
  const head = result.createTHead().insertRow()
  for (const name of ['STT', 'Hạng mục / Trang thiết bị', 'SL', 'Tình trạng khi nhận']) {
    const cell = document.createElement('th'); cell.textContent = name; head.append(cell)
  }
  const body = result.createTBody()
  names.forEach(([name, unit], index) => {
    const item = recorded.get(name)
    const state = item?.condition
    const quantity = item?.quantity ? String(item.quantity).padStart(2, '0') : '……'
    const status = `${state === 'Tốt' ? '☑' : '☐'} Tốt   ${state === 'Hư hỏng' ? '☑' : '☐'} Hư hỏng   ${state === 'Thiếu' ? '☑' : '☐'} Thiếu`
    const note = [state && !['Tốt', 'Hư hỏng', 'Thiếu'].includes(state) ? state : '', item?.note].filter(Boolean).join(' — ')
    const row = body.insertRow()
    for (const value of [index + 1, name, `${quantity} ${unit}`, status + (note ? `\n${note}` : '')]) row.insertCell().textContent = String(value)
  })
  return result
}

// Presentation-only repair for legacy frozen HTML. Never write the result back
// to ContractDocument or change its verified hash/signature evidence.
export function normalizeHandoverPrint(html) {
  if (!html?.includes('BIÊN BẢN BÀN GIAO HIỆN TRẠNG') || typeof DOMParser === 'undefined') return html
  const document = new DOMParser().parseFromString(html, 'text/html')
  removeTrailingPageBreaks(document)
  const title = [...document.querySelectorAll('p,h1,h2,h3,h4')].find(node => /BIÊN BẢN BÀN GIAO HIỆN TRẠNG (PHÒNG|TRANG THIẾT BỊ)/.test(node.textContent))
  if (!title) return html
  const dorm = title.textContent.includes('TRANG THIẾT BỊ')
  const tables = [...document.querySelectorAll('table')].filter(table => title.compareDocumentPosition(table) & 4)
  if (!tables.length) return html
  let dynamic = tables.filter(table => table.classList.contains('contract-handover-table') || table.rows[0]?.cells[0]?.textContent.trim() === 'Tài sản').at(-1) ?? tables.at(-1)
  let section = title.closest('.contract-handover-section')
  if (!section) {
    section = document.createElement('div')
    title.before(section)
    let node = title
    while (node) { const next = node.nextSibling; section.append(node); node = next }
  }
  section.classList.add('contract-handover-section')
  if (dorm) section.classList.add('dorm-handover')
  const first = tables[0]
  if (first !== dynamic) first.before(dynamic)
  for (const table of tables) if (table !== dynamic) table.remove()
  if (dorm) {
    const normalized = dormTable(document, dynamic)
    if (normalized !== dynamic) { dynamic.replaceWith(normalized); dynamic = normalized }
  }
  dynamic.classList.add('contract-handover-table')
  if (!section.contains(dynamic) && dynamic.isConnected) section.append(dynamic)
  title.classList.add('handover-heading')
  const strong = document.createElement('strong'); strong.textContent = title.textContent
  title.replaceChildren(strong)
  for (const node of [section, title]) {
    if (node.previousElementSibling?.classList.contains('page-break')) node.previousElementSibling.remove()
  }
  return document.body.innerHTML
}
