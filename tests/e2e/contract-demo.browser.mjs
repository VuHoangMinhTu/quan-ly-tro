import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const php = process.env.E2E_PHP_PATH || 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
// Real source templates + fixture renderer; no database, authentication or model setup.
const demos = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $demo = $app->make(App\\Services\\ContractTemplateDemo::class); echo json_encode(["room" => $demo->document("room"), "dorm" => $demo->document("dorm")]);'], { cwd: resolve(root, 'backend-api'), encoding: 'utf8' }))
const origin = 'http://127.0.0.1:18946'
const output = resolve(root, 'tmp/contract-demo')
const server = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'), define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') }, plugins: [{ name: 'contract-demo-audit', configureServer(vite) {
  vite.middlewares.use(async (request, response, next) => {
    if (!/^\/(?:contract-templates|contracts)(?:\/|\?|$)/.test(request.url) || request.url.startsWith('/api/')) return next()
    response.setHeader('Content-Type', 'text/html')
    response.end(await vite.transformIndexHtml(request.url, '<html lang="vi"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/tests/contract-demo-audit.jsx"></script></body></html>'))
  })
} }], server: { host: '127.0.0.1', port: 18946, strictPort: true } })
let browser
try {
  await mkdir(output, { recursive: true })
  await server.listen()
  browser = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
  const context = await browser.newContext({ viewport: { width: 1200, height: 1000 } })
  const errors = [], requests = []
  context.on('page', page => page.on('pageerror', error => errors.push(error.message)))
  await context.addInitScript(() => { window.demoPrintCount = 0; window.print = () => window.demoPrintCount++ })
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    assert.equal(url.origin, origin, 'No production or external requests')
    if (!url.pathname.startsWith('/api/')) return route.continue()
    assert.equal(request.method(), 'GET', 'DEMO must never mutate data')
    requests.push(url.pathname)
    let data
    if (url.pathname === '/api/contract-templates') data = { templates: [], can_manage: false }
    else if (url.pathname.startsWith('/api/contract-templates/demos/')) data = demos[url.pathname.split('/').at(-1)]
    else if (url.pathname === '/api/contracts/77/document') data = { rendered_html: '<div class="word-contract"><h1>HỢP ĐỒNG THẬT KIỂM THỬ</h1><p>Dữ liệu riêng từ API hợp đồng</p><div class="document-signatures"><div><p>Chưa ký</p></div></div></div>', template_name: 'Mẫu từ API', template_version: 1 }
    else throw new Error(`Unexpected API: ${url.pathname}`)
    return route.fulfill({ json: { success: true, data } })
  })
  const page = await context.newPage()
  for (const [type, articles, items] of [['room', 12, 13], ['dorm', 9, 6]]) {
    await page.goto(`${origin}/contract-templates`)
    const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: type === 'room' ? 'Hợp đồng phòng trọ' : 'Hợp đồng KTX', exact: true }) })
    await card.getByRole('link', { name: 'Xem mẫu DEMO', exact: true }).click()
    await page.locator('.word-contract').waitFor()
    assert.equal(await page.locator('.contract-demo-summary').count(), 0)
    await page.evaluate(() => document.fonts.ready)
    assert.equal(await page.locator('.word-contract h2').count(), articles)
    assert.equal(await page.locator('.contract-handover-table tbody tr').count(), items)
    assert.doesNotMatch(await page.locator('.contract-document-html').innerText(), /\{\{|Chưa cập nhật|Chưa ký/)
    const contractText = await page.locator('.contract-document-html').innerText()
    assert.match(contractText, /Bùi Quang Duy/)
    assert.match(contractText, /Minh Tú/)
    assert.match(contractText, /052098006132/)
    assert.match(contractText, /MB Bank/)
    assert.doesNotMatch(contractText, /DEMO-SDT|DEMO-TK|không giao dịch|\(không thanh toán\)/)
    assert.equal(await page.locator('.document-signatures img').count(), 0)
    assert.ok((await page.locator('.signature-handwritten-space').allTextContents()).every(text => text === ''))
    assert.equal(await page.locator('.word-contract').evaluate(node => getComputedStyle(node).fontFamily), '"Times New Roman", Times, serif')
    assert.equal(await page.title(), demos[type].filename.replace('.pdf', ''))
    await page.getByRole('button', { name: 'In / Xuất PDF', exact: true }).click()
    assert.equal(await page.evaluate(() => window.demoPrintCount), 1)
    await page.emulateMedia({ media: 'print' })
    const brokenLabels = await page.locator('.word-contract').evaluate(document => {
      const labels = [...document.querySelectorAll('.contract-info-label')]
      return labels.filter(label => {
        const range = label.ownerDocument.createRange()
        range.selectNodeContents(label)
        return new Set([...range.getClientRects()].map(rect => Math.round(rect.top))).size > 1
      }).map(label => label.textContent)
    })
    assert.deepEqual(brokenLabels, [], 'Information labels must not wrap to a second line')
    if (type === 'dorm') {
      const leftEdges = await page.locator('.word-contract').evaluate(document => {
        const prefixes = ['Đại diện :', 'Điện thoại hỗ trợ:', 'Ngân hàng:', 'Số tài khoản:', 'Chủ TK:']
        return prefixes.map(prefix => {
          const paragraph = [...document.querySelectorAll('p')].find(node => node.textContent.trim().startsWith(prefix))
          const range = paragraph.ownerDocument.createRange()
          range.setStart(paragraph.firstChild, 0); range.setEnd(paragraph.firstChild, 1)
          return Math.round(range.getBoundingClientRect().left)
        })
      })
      assert.equal(new Set(leftEdges).size, 1, 'All five lessor information lines must share the same text inset')
    }
    assert.equal(await page.locator('.contract-document-actions').isVisible(), false)
    assert.equal(await page.locator('.contract-demo-page').evaluate(node => getComputedStyle(node).page), 'contract-demo')
    await page.pdf({ path: resolve(output, demos[type].filename), preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false })
    await page.emulateMedia({ media: 'screen' })
    await page.screenshot({ path: resolve(output, `${type}-preview.png`) })
    await page.setViewportSize({ width: 390, height: 844 })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
    await page.setViewportSize({ width: 1200, height: 1000 })
    await page.goto(`${origin}/contract-templates`)
    const popupPromise = context.waitForEvent('page')
    await card.getByRole('link', { name: 'Xuất PDF', exact: true }).click()
    const popup = await popupPromise
    await popup.waitForFunction(() => window.demoPrintCount === 1)
    await popup.close()
  }
  await page.goto(`${origin}/contracts/77/document`)
  await page.getByText('Dữ liệu riêng từ API hợp đồng', { exact: true }).waitFor()
  assert.equal(await page.locator('.contract-demo-watermark').count(), 0)
  assert.doesNotMatch(await page.locator('.contract-document-sheet').innerText(), /Nguyễn Văn A|Lê Văn C|DEMO|Chưa ký/)
  assert.deepEqual(errors, [])
  const pdfChecks = JSON.parse(execFileSync(process.env.E2E_PYTHON_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe', ['-c', `
from pathlib import Path
from pypdf import PdfReader
import json, sys
results = []
for filename, expected_pages in [('BeeHouse_HopDong_PhongTro_DEMO.pdf', 8), ('BeeHouse_HopDong_KTX_DEMO.pdf', 6)]:
    reader = PdfReader(Path(sys.argv[1]) / filename)
    texts = [page.extract_text() or '' for page in reader.pages]
    assert len(texts) == expected_pages, (filename, len(texts))
    assert all(len(text) > 300 for text in texts), 'Unexpected blank page'
    assert all('BẢN MẪU' in text and 'DỮ LIỆU MINH HỌA' in text for text in texts), 'Missing DEMO watermark'
    assert all(abs(float(page.mediabox.width) - 595) < 2 and abs(float(page.mediabox.height) - 842) < 2 for page in reader.pages), 'Expected A4'
    assert 'STT' in texts[-1] and '13' in texts[-1] if expected_pages == 8 else 'STT' in texts[-1] and '6' in texts[-1]
    if expected_pages == 6:
        assert any('gồm có 06 trang' in text for text in texts), 'Demo page count must match generated PDF'
    results.append({'file': filename, 'pages': len(texts), 'a4': True, 'watermarkEveryPage': True, 'blankPages': False})
print(json.dumps(results))
`, output], { encoding: 'utf8' }))
  await writeFile(resolve(output, 'result.json'), JSON.stringify({ result: 'passed', requests, errors, pdfChecks }, null, 2))
  console.log(JSON.stringify({ result: 'passed', requests, errors, pdfChecks }))
} finally {
  await browser?.close()
  await server.close()
}
