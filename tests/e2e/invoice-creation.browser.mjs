import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const origin = 'http://127.0.0.1:18941'
const server = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'),
  define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') },
  plugins: [{ name: 'invoice-creation-audit', configureServer(vite) {
    vite.middlewares.use('/invoice-audit', async (_request, response) => {
      response.setHeader('Content-Type', 'text/html')
      response.end(await vite.transformIndexHtml('/invoice-audit', '<html lang="vi"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/tests/invoice-creation-audit.jsx"></script></body></html>'))
    })
  } }], server: { host: '127.0.0.1', port: 18941, strictPort: true } })
let browser
try {
  await server.listen()
  browser = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
  const requests = [], errors = []
  let fail = true
  page.on('pageerror', error => errors.push(error.message))
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    assert.equal(url.origin, origin, 'Only isolated local requests are allowed')
    if (!url.pathname.startsWith('/api/')) return route.continue()
    requests.push({ method: request.method(), path: url.pathname, period: url.searchParams.get('billing_period') })
    if (request.method() === 'GET' && url.pathname.endsWith('/invoice-contracts')) {
      const room = url.pathname.split('/')[3]
      const contracts = room === '2' ? [] : room === '3' ? [1, 2].map(index => ({ id: 30 + index, room_code: '201', bed: { id: index, bed_code: `G0${index}` }, contract_code: `BED-${index}`, status: 'ACTIVE', tenant: { full_name: `Người giường ${index}` }, start_date: '2026-09-01', end_date: '2027-09-30' })) : [{ id: 10, room_code: '101', contract_code: 'CURRENT-10', status: 'ACTIVE', tenant: { full_name: 'Người thuê hiện tại' }, start_date: '2026-09-01', end_date: '2027-09-30' }]
      return route.fulfill({ json: { success: true, data: { contracts, warnings: [] } } })
    }
    if (request.method() === 'POST' && url.pathname.endsWith('/invoices')) {
      if (fail) return route.fulfill({ status: 422, json: { success: false, message: 'Hợp đồng này đã chấm dứt. Không thể tạo hóa đơn định kỳ mới.', errors: { contract_id: ['Hợp đồng này đã chấm dứt. Không thể tạo hóa đơn định kỳ mới.'] } } })
      await new Promise(resolve => setTimeout(resolve, 150))
      return route.fulfill({ status: 201, json: { success: true, message: 'Tạo hóa đơn thành công.', data: null } })
    }
    throw new Error(`Unexpected API request: ${request.method()} ${url.pathname}`)
  })
  await page.goto(`${origin}/invoice-audit`)
  const period = page.getByPlaceholder('mm/yyyy', { exact: true })
  const select = page.locator('select[name="contract_id"]')
  const save = page.getByRole('button', { name: 'Tạo hóa đơn', exact: true })
  await period.fill('11/2026'); await period.blur()
  await page.waitForLoadState('networkidle')
  assert.equal(requests.filter(request => request.method === 'GET').length, 1, 'StrictMode must not duplicate eligibility requests')
  await select.selectOption('10')
  await page.getByText('Người thuê hiện tại:', { exact: false }).waitFor()
  await save.click()
  await page.locator('[aria-label="Thông báo"] [role="alert"]').getByText('Hợp đồng này đã chấm dứt. Không thể tạo hóa đơn định kỳ mới.', { exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Đóng thông báo' }).count(), 1, '422 must produce exactly one toast')
  assert.equal(requests.filter(request => request.method === 'POST').length, 1)
  await page.getByRole('button', { name: 'Đóng thông báo' }).click()
  fail = false
  await save.evaluate(button => { button.click(); button.click(); button.click() })
  await page.getByText('Đã về danh sách hóa đơn', { exact: true }).waitFor()
  assert.equal(requests.filter(request => request.method === 'POST').length, 2, 'Rapid clicks must add one successful mutation')
  await page.getByRole('link', { name: 'Phòng trống', exact: true }).click()
  await period.fill('11/2026'); await period.blur()
  await page.getByText('Phòng hiện chưa có người thuê/hợp đồng đang hoạt động.', { exact: true }).waitFor()
  assert.equal(await save.isDisabled(), true)
  await page.getByRole('link', { name: 'Phòng KTX', exact: true }).click()
  await period.fill('11/2026'); await period.blur()
  await page.waitForLoadState('networkidle')
  assert.match(await select.innerText(), /Giường G01/)
  assert.match(await select.innerText(), /Giường G02/)
  assert.doesNotMatch(await select.innerText(), /G03/)
  await select.selectOption('31')
  await period.fill('12/2026'); await period.blur()
  await page.waitForLoadState('networkidle')
  assert.equal(await select.inputValue(), '')
  assert.equal(requests.filter(request => request.method === 'GET' && request.path === '/api/rooms/3/invoice-contracts' && request.period === '2026-12-01').length, 1)
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ result: 'passed', mobileOverflow: false, duplicateMutations: false, errors, requests }))
} finally {
  await browser?.close()
  await server.close()
}
