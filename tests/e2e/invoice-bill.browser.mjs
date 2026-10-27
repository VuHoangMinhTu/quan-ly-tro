import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const { default: React } = await import(pathToFileURL(require.resolve('react')))
const { renderToStaticMarkup } = await import(pathToFileURL(require.resolve('react-dom/server')))
const { QRCodeSVG } = require('qrcode.react')
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const output = resolve(root, 'tmp/invoice-bill')
await mkdir(output, { recursive: true })
const origin = 'http://127.0.0.1:18939'
const server = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'),
  define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') },
  plugins: [{ name: 'invoice-bill-audit', configureServer(vite) {
    vite.middlewares.use('/bill-audit', async (_request, response) => {
      response.setHeader('Content-Type', 'text/html')
      response.end(await vite.transformIndexHtml('/bill-audit', '<html lang="vi"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/tests/invoice-bill-audit.jsx"></script></body></html>'))
    })
  } }], server: { host: '127.0.0.1', port: 18939, strictPort: true } })
let browser
try {
  await server.listen()
  browser = await chromium.launch({ executablePath: process.env.HANDOVER_AUDIT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
  const fixturePage = await browser.newPage()
  const svg = renderToStaticMarkup(React.createElement(QRCodeSVG, { value: 'BEEHOUSE-SYNTHETIC-BANK-QR', size: 300, marginSize: 4 }))
  await fixturePage.setContent(`<html><body style="margin:0;background:white">${svg}</body></html>`)
  const qr = await fixturePage.locator('svg').screenshot()
  await fixturePage.close()
  const context = await browser.newContext({ viewport: { width: 1365, height: 1000 }, serviceWorkers: 'block' })
  const page = await context.newPage()
  const errors = [], requests = []
  page.on('pageerror', error => errors.push(error.message))
  const bank = { bank_name: 'Vietcombank', bank_account_name: 'NGUYỄN VĂN A (DỮ LIỆU TEST)', bank_account_number: '00123456789',
    has_payment_qr: true, payment_qr_url: '/invoices/1/payment-qr', payment_qr_version: 'synthetic-a' }
  const items = [
    { id: 1, type: 'RENT', description: 'Tiền phòng tháng 10/2026', quantity: 1, unit_price: 3000000, amount: 3000000, source: 'AUTO' },
    { id: 2, type: 'ELECTRICITY', description: 'Điện 71', quantity: 71, unit_price: 4000, amount: 284000, source: 'AUTO' },
    { id: 3, type: 'WATER', description: 'Nước 5', quantity: 5, unit_price: 20000, amount: 100000, source: 'AUTO' },
    { id: 4, type: 'INTERNET', description: 'Phí dịch vụ', quantity: 1, unit_price: 100000, amount: 100000, source: 'AUTO' },
    { id: 5, type: 'OTHER', description: 'Khoản thu thêm', quantity: 1, unit_price: 20000, amount: 20000, source: 'MANUAL' },
  ]
  let invoice = { id: 1, invoice_code: 'TEST-BILL-001', billing_period: '2026-10-01', issued_at: '2026-10-26', due_date: '2026-11-05',
    status: 'UNPAID', subtotal: 3504000, total_amount: 3494000, discount_amount: 10000, paid_amount: 0, items, payment_profile: bank,
    room: { room_code: '101', boarding_house: { name: 'BeeHouse Test', address: 'Địa chỉ tổng hợp cho kiểm thử' } },
    contract: { contract_code: 'TEST-HD-001', tenant: { full_name: 'Người thuê kiểm thử' } } }
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    assert.equal(url.origin, origin, 'Only the isolated local UI may be accessed')
    if (!url.pathname.startsWith('/api/')) return route.continue()
    requests.push({ method: request.method(), path: url.pathname })
    assert.equal(request.method(), 'GET', 'Rendering a bill must never create a payment request')
    if (url.pathname === '/api/invoices/1') return route.fulfill({ json: { success: true, data: invoice } })
    if (url.pathname === '/api/invoices/1/payment-qr') return route.fulfill({ contentType: 'image/png', body: qr })
    throw new Error(`Unexpected API access: ${url.pathname}`)
  })
  const load = async () => {
    await page.goto(`${origin}/bill-audit`)
    await page.getByRole('heading', { name: 'HÓA ĐƠN TIỀN PHÒNG' }).waitFor()
    await page.waitForLoadState('networkidle')
    await page.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].map(image => image.decode())) })
  }
  const metrics = {}
  for (const width of [1365, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 })
    await load()
    assert.doesNotMatch(await page.locator('.invoice-sheet').innerText(), /Tự động|Thủ công|VAT/)
    assert.doesNotMatch(await page.locator('.invoice-totals').innerText(), /Chưa thanh toán/)
    assert.equal(await page.locator('.invoice-brand-name').innerText(), 'NHÀ TRỌ 115M')
    assert.ok(await page.locator('.invoice-brand-mark').evaluate(image => image.complete && image.naturalWidth > 0))
    const metaAlignment = await page.locator('.invoice-document-meta').evaluate(element => {
      const values = [...element.querySelectorAll('dd')].map(value => value.getBoundingClientRect())
      return { topDifference: Math.abs(values[0].top - values[1].top), dateDifference: Math.abs(values[1].left - values[2].left), divider: getComputedStyle(element, '::after').width }
    })
    assert.ok(metaAlignment.topDifference < 1)
    assert.ok(metaAlignment.dateDifference < 1)
    assert.equal(metaAlignment.divider, '1px')
    const image = page.locator('.invoice-bank-qr img')
    assert.equal(await image.count(), 1)
    assert.equal(await image.evaluate(img => img.naturalWidth), 300)
    const bounds = await image.boundingBox()
    assert.equal(bounds.width, bounds.height)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
    metrics[width] = { qrWidth: bounds.width, qrHeight: bounds.height, overflow: false }
    if (width < 760) {
      assert.ok(Math.abs(bounds.x + bounds.width / 2 - width / 2) < 1)
      assert.equal(await page.locator('.invoice-items-scroll').evaluate(element => element.scrollWidth > element.clientWidth), true)
    } else {
      assert.ok(await page.locator('.invoice-footer-houses').evaluate(image => image.complete && image.naturalWidth > 0))
      const left = await page.locator('.invoice-payment-details').boundingBox()
      const right = await page.locator('.invoice-totals').boundingBox()
      assert.ok(Math.abs(left.y - right.y) < 1, 'Transfer and cost cards must align at the top')
      assert.ok(Math.abs(left.height - right.height) < 1, 'Transfer and cost cards must have balanced heights')
    }
    await page.screenshot({ path: resolve(output, `bill-${width}.png`), fullPage: true })
  }
  await page.setViewportSize({ width: 1365, height: 1000 })
  await page.emulateMedia({ media: 'print' })
  const pdf = await page.pdf({ path: resolve(output, 'bill-a4.pdf'), preferCSSPageSize: true, printBackground: true })
  metrics.pdfPages = [...pdf.toString('latin1').matchAll(/\/Type\s*\/Page\b/g)].length
  assert.equal(metrics.pdfPages, 1, 'A typical five-item bill and static bank QR should fit one A4 page')
  await page.emulateMedia({ media: 'screen' })

  invoice = { ...invoice, payos_payment_request: { qr_code: 'SYNTHETIC-PAYOS', amount: 3494000, status: 'PENDING', order_code: 100001,
    payment_link_id: 'synthetic', checkout_url: 'https://pay.example/synthetic' } }
  await load()
  assert.equal(await page.locator('.invoice-top-qr svg').count(), 1)
  assert.equal(await page.locator('.invoice-bank-qr img').count(), 1)
  await page.getByText('Vietcombank', { exact: true }).waitFor()

  invoice = { ...invoice, payos_payment_request: null, payment_profile: { ...bank, has_payment_qr: false, payment_qr_url: null } }
  await load()
  assert.equal(await page.locator('.invoice-bank-qr img').count(), 0)
  await page.getByText('Vietcombank', { exact: true }).waitFor()
  invoice = { ...invoice, payment_profile: null }
  await load()
  await page.getByText('Vui lòng liên hệ chủ trọ để biết thông tin thanh toán.', { exact: true }).waitFor()
  assert.equal(await page.getByText('Thiết lập ngay', { exact: true }).count(), 0)

  invoice = { ...invoice, payment_profile: { source: 'missing_snapshot', current_profile_configured: true } }
  await load()
  await page.getByText('Hóa đơn này chưa lưu thông tin nhận thanh toán tại thời điểm phát hành.', { exact: true }).waitFor()
  assert.equal(await page.getByText('Chưa thiết lập thông tin nhận thanh toán.', { exact: true }).count(), 0)
  assert.equal(await page.locator('.invoice-bank-qr img').count(), 0)
  await page.emulateMedia({ media: 'print' })
  assert.ok(await page.getByText('Chủ trọ đã có cấu hình thanh toán hiện tại; cấu hình này chưa được ghi nhận trên hóa đơn này.', { exact: true }).isVisible())
  assert.equal(await page.locator('.invoice-sheet a, .invoice-sheet button').count(), 0)
  await page.emulateMedia({ media: 'screen' })

  invoice = { ...invoice, payment_profile: { ...bank, payment_qr_url: null, payment_qr_missing: true } }
  await load()
  await page.getByText('Vietcombank', { exact: true }).waitFor()
  await page.getByText('Ảnh QR đã lưu không còn khả dụng. Vui lòng liên hệ chủ trọ để đối chiếu thông tin thanh toán.', { exact: true }).waitFor()

  assert.deepEqual(errors, [])
  assert.ok(requests.every(request => request.method === 'GET'))
  await writeFile(resolve(output, 'results.json'), JSON.stringify({ ...metrics, requests, errors }, null, 2))
  console.log(JSON.stringify({ ...metrics, apiRequests: requests.length, mutations: 0, errors }))
  await context.close()
} finally {
  await browser?.close()
  await server.close()
}
