import test from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { spawn, spawnSync } from 'node:child_process'
import { createServer, request } from 'node:http'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../../', import.meta.url))
const backend = path.join(root, 'backend-api')
const combined = process.env.BILLING_V2_COMBINED_BROWSER === '1'
const monthly = process.env.BILLING_V2_MONTHLY_BROWSER === '1'
const recalculate = process.env.BILLING_V2_RECALCULATE_BROWSER === '1'
const flagOff = process.env.BILLING_V2_TEST_ENABLED === 'false'
const output = path.join(root, flagOff ? 'tmp/billing-v2-browser-flag-off' : recalculate ? 'tmp/billing-v2-browser-draft-update' : monthly ? 'tmp/billing-v2-browser-monthly' : combined ? 'tmp/billing-v2-browser-combined' : 'tmp/billing-v2-browser')
const dist = path.join(root, 'tmp/billing-v2-dist')
const php = process.env.E2E_PHP_PATH
const playwrightPackage = process.env.PLAYWRIGHT_PACKAGE_PATH || path.join(root, 'front-end', 'node_modules', 'playwright')
const { chromium } = createRequire(import.meta.url)(playwrightPackage)

test('React + Laravel + isolated MySQL: checkout, actual deposit, tiered vacancy, lost response and retry', { timeout: 120000 }, async () => {
  assert.ok(php && existsSync(php), 'Provide local PHP')
  assert.ok(existsSync(path.join(dist, 'index.html')), 'Build billing-v2-dist first')
  assert.equal(existsSync(path.join(backend, 'bootstrap/cache/config.php')), false)
  await mkdir(output, { recursive: true })
  const env = { ...process.env, APP_ENV: 'testing', APP_URL: 'http://127.0.0.1:8848', APP_DEBUG: 'false', APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', FRONTEND_URL: 'http://127.0.0.1:8849', BILLING_V2_ENABLED: flagOff ? 'false' : 'true', DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: '13368', DB_DATABASE: 'beehouse_billing_v2_test', DB_USERNAME: 'billing_test', DB_PASSWORD: '', DB_URL: '', DB_SOCKET: '', CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BCRYPT_ROUNDS: '4' }
  const cli = command => {
    const result = spawnSync(php, [path.join(backend, 'tests/Fixtures/BillingV2Browser.php'), command], { cwd: backend, env, encoding: 'utf8', windowsHide: true })
    assert.equal(result.status, 0, result.stdout + result.stderr)
    return JSON.parse(result.stdout)
  }
  const fixture = cli('seed')
  // Exercise the same fail-closed, owner-scoped pilot gate used by the application.
  env.BILLING_V2_PILOT_USER_IDS = flagOff ? '' : String(fixture.user)
  const phpServer = spawn(php, ['-S', '127.0.0.1:8848', path.join(backend, 'tests/Fixtures/BillingV2HttpRouter.php')], { cwd: path.join(backend, 'public'), env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] })
  let phpLog = ''
  phpServer.stdout.on('data', chunk => { phpLog += chunk })
  phpServer.stderr.on('data', chunk => { phpLog += chunk })
  const server = createServer(async (req, res) => {
    if (req.url.startsWith('/api/')) {
      const proxy = request({ host: '127.0.0.1', port: 8848, path: req.url, method: req.method, headers: { ...req.headers, host: '127.0.0.1:8848' } }, upstream => { res.writeHead(upstream.statusCode, upstream.headers); upstream.pipe(res) })
      proxy.on('error', () => { res.writeHead(502); res.end() }); req.pipe(proxy); return
    }
    try {
      let file = path.resolve(dist, '.' + decodeURIComponent(new URL(req.url, 'http://local').pathname))
      if (!file.startsWith(dist + path.sep) || !existsSync(file) || !path.extname(file)) file = path.join(dist, 'index.html')
      res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff' })[path.extname(file)] || 'application/octet-stream')
      res.end(await readFile(file))
    } catch { res.writeHead(404); res.end() }
  })
  let browser, context, page
  try {
    await new Promise(resolve => server.listen(8849, '127.0.0.1', resolve))
    for (let i = 0; i < 50; i++) { try { const response = await fetch('http://127.0.0.1:8848/up'); if (response.ok()) break } catch { /* wait for local PHP */ } await new Promise(resolve => setTimeout(resolve, 100)) }
    browser = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM_PATH, headless: true })
    context = await browser.newContext({ viewport: { width: 1366, height: 900 } })
    await context.tracing.start({ screenshots: true, snapshots: true })
    await context.route('**/*', route => new URL(route.request().url()).origin === 'http://127.0.0.1:8849' ? route.continue() : route.abort())
    page = await context.newPage(); page.setDefaultTimeout(15000)
    const errors = [], serverErrors = []
    page.on('pageerror', error => errors.push(error.message))
    page.on('response', response => { if (response.status() >= 500) serverErrors.push(response.status()) })
    await page.goto('http://127.0.0.1:8849/login')
    await page.getByLabel('Email', { exact: true }).fill('billing-browser@example.invalid')
    await page.getByLabel('Mật khẩu', { exact: true }).fill('BrowserTest123!')
    await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click(); await page.waitForURL('**/dashboard')
    await page.goto('http://127.0.0.1:8849/invoices')
    await page.getByLabel('Chọn nhà trọ', { exact: true }).selectOption(String(fixture.house))
    if (flagOff) {
      await page.getByText('Tạo hóa đơn tháng bằng Billing V2 hiện đang tạm khóa.', { exact: false }).waitFor()
      assert.equal(await page.getByRole('heading', { name: 'Tạo hóa đơn tháng', exact: true }).count(), 0)
      assert.equal(await page.getByRole('link', { name: 'Tạo hóa đơn', exact: true }).count(), 0)
      await page.screenshot({ path: path.join(output, 'invoices-flag-off.png'), fullPage: true })
      const result = { passed: true, billing_v2_enabled: false, legacy_fallback: false, invoices_created: 0 }
      assert.deepEqual(cli('assert-empty'), { invoices: 0, payments: 0, payos_requests: 0 })
      await writeFile(path.join(output, 'result.json'), JSON.stringify(result, null, 2))
      return
    }
    await page.getByRole('heading', { name: 'Tạo hóa đơn tháng', exact: true }).waitFor()
    await page.getByRole('button', { name: 'Tạo hóa đơn tháng', exact: true }).waitFor()
    await page.screenshot({ path: path.join(output, 'invoices-main.png'), fullPage: true })
    await page.goto(`http://127.0.0.1:8849/rooms/${fixture.room}/invoices`)
    assert.equal(await page.getByRole('link', { name: 'Tạo hóa đơn', exact: true }).count(), 0, 'Room history must not expose a second V1 creation path.')
    await page.goto(`http://127.0.0.1:8849/rooms/${fixture.room}/invoices/new`)
    await page.waitForURL(`**/rooms/${fixture.room}/invoices`)
    await page.goto(`http://127.0.0.1:8849/rooms/${fixture.room}/billing-v2`)
    await page.getByRole('heading', { name: 'Quyết toán điện nước V2', exact: true }).waitFor()
    await page.getByLabel('Tháng hóa đơn', { exact: true }).fill('2026-10')
    const submit = async (name, url) => {
      const response = page.waitForResponse(response => response.url().includes(url) && response.request().method() === 'POST')
      await page.getByRole('button', { name, exact: true }).click(); assert.equal((await response).status(), 200)
      await page.getByRole('button', { name, exact: true }).waitFor({ state: 'visible' })
      await page.waitForFunction(() => !document.querySelector('fieldset').disabled)
    }
    await page.getByLabel('Hợp đồng ghi cọc', { exact: true }).selectOption(String(fixture.a))
    await page.getByLabel('Số tiền cọc (đ)', { exact: true }).fill('1000000')
    await page.getByLabel('Thời điểm giao dịch cọc', { exact: true }).fill('2026-10-01T00:00')
    await page.getByLabel('Mã giao dịch cọc', { exact: true }).fill('browser-receipt')
    await submit('Ghi giao dịch cọc', '/deposits'); await submit('Ghi giao dịch cọc', '/deposits')
    await page.getByLabel('Giao dịch cọc', { exact: true }).selectOption('REFUNDED')
    await page.getByLabel('Số tiền cọc (đ)', { exact: true }).fill('200000')
    await page.getByLabel('Mã giao dịch cọc', { exact: true }).fill('browser-refund')
    await submit('Ghi giao dịch cọc', '/deposits')
    await page.getByLabel('Hợp đồng trả phòng', { exact: true }).selectOption(String(fixture.a))
    await page.getByLabel('Lý do kết thúc', { exact: true }).selectOption('TENANT_ABANDONMENT')
    await page.getByLabel('Thời điểm trả phòng', { exact: true }).fill('2026-10-15T10:00')
    await page.getByLabel('Chỉ số trả phòng: Điện giả lập', { exact: true }).fill('130')
    await page.getByLabel('Chỉ số trả phòng: Nước giả lập', { exact: true }).fill('25')
    await submit('Xác nhận trả phòng', '/checkout')
    await page.locator('[data-settlement-status="PENDING_FINAL_SETTLEMENT"]').waitFor()
    const pendingProof = cli('assert-pending')
    await page.getByRole('button', { name: 'Đóng thông báo' }).evaluateAll(buttons => buttons.forEach(button => button.click()))
    await page.evaluate(() => window.scrollTo(0, 0))
    await page.screenshot({ path: path.join(output, 'pending-checkout.png'), fullPage: true })
    await page.getByLabel('Công tơ', { exact: true }).selectOption(String(fixture.meter))
    await page.getByLabel('Loại mốc', { exact: true }).selectOption('CHECK_IN')
    await page.getByLabel('Hợp đồng nhận phòng', { exact: true }).selectOption(String(fixture.b))
    await page.getByLabel('Thời điểm ghi nhận', { exact: true }).fill('2026-10-20T10:00')
    await page.getByLabel('Chỉ số xác nhận', { exact: true }).fill('135')
    await submit('Xác nhận mốc', '/checkpoints')
    await page.getByLabel('Công tơ', { exact: true }).selectOption(String(fixture.waterMeter))
    await page.getByLabel('Loại mốc', { exact: true }).selectOption('CHECK_IN')
    await page.getByLabel('Hợp đồng nhận phòng', { exact: true }).selectOption(String(fixture.b))
    await page.getByLabel('Thời điểm ghi nhận', { exact: true }).fill('2026-10-20T10:00')
    await page.getByLabel('Chỉ số xác nhận', { exact: true }).fill('30')
    await submit('Xác nhận mốc', '/checkpoints')
    await page.getByLabel('Công tơ', { exact: true }).selectOption(String(fixture.meter))
    await page.getByLabel('Loại mốc', { exact: true }).selectOption('PERIOD_BOUNDARY')
    await page.getByLabel('Thời điểm ghi nhận', { exact: true }).fill('2026-11-01T00:00')
    await page.getByLabel('Chỉ số xác nhận', { exact: true }).fill('150')
    await submit('Xác nhận mốc', '/checkpoints')
    await page.getByLabel('Công tơ', { exact: true }).selectOption(String(fixture.waterMeter))
    await page.getByLabel('Loại mốc', { exact: true }).selectOption('PERIOD_BOUNDARY')
    await page.getByLabel('Thời điểm ghi nhận', { exact: true }).fill('2026-11-01T00:00')
    await page.getByLabel('Chỉ số xác nhận', { exact: true }).fill('40')
    await submit('Xác nhận mốc', '/checkpoints')
    if (monthly) {
      await page.goto('http://127.0.0.1:8849/invoices')
      await page.getByLabel('Chọn nhà trọ', { exact: true }).selectOption(String(fixture.house))
      const panel = page.getByRole('region', { name: 'Tạo hóa đơn tháng' })
      await panel.getByLabel('Tháng hóa đơn', { exact: true }).fill('2026-10')
      await panel.getByRole('button', { name: 'Tạo hóa đơn tháng', exact: true }).click()
      await panel.getByRole('heading', { name: 'Phòng 101 — Cần xem xét', exact: true }).waitFor()
      await panel.locator('summary').first().click()
      const bFields = panel.locator('fieldset').nth(1)
      await bFields.getByLabel('Tiền phòng xác nhận (đ)').fill('1500000')
      await bFields.getByLabel('Căn cứ thỏa thuận').fill('Theo thỏa thuận kỳ đầu của B')
      let requests = 0, lose = true
      await page.route('**/monthly-invoices', async route => {
        requests++
        if (requests === 1) {
          const payload = JSON.parse(route.request().postData() || '{}')
          assert.ok(payload.confirmations?.some(input => Number(input.contract_id) === Number(fixture.b) && Number(input.rent_amount) === 1500000 && input.reason), `The browser must submit B’s explicit rent confirmation. Payload: ${JSON.stringify(payload.confirmations)}`)
        }
        const response = await route.fetch()
        assert.equal(response.status(), 200)
        const result = await response.json()
        assert.equal(result.data?.[0]?.status, requests === 1 ? 'CREATED' : 'EXISTING', JSON.stringify(result.data?.[0]?.errors || result))
        const ktxResult = result.data?.find(room => Number(room.room_id) === Number(fixture.ktxRoom))
        assert.equal(ktxResult?.status, 'NO_ELIGIBLE_CONTRACT', 'KTX only bills bed contracts; a bare shared meter must not create any tenant receivable.')
        assert.equal((ktxResult?.invoices || []).length, 0, 'No contract means no KTX bill or invented meter charges.')
        if (lose) { lose = false; await route.abort('failed') } else await route.fulfill({ response })
      })
      await panel.getByRole('button', { name: 'Tạo hóa đơn tháng', exact: true }).evaluate(button => { button.click(); button.click() })
      await panel.getByRole('alert').filter({ hasText: 'Chưa xác định' }).waitFor()
      assert.equal(requests, 1)
      const proof = cli('assert-monthly')
      await page.reload()
      await page.getByLabel('Chọn nhà trọ', { exact: true }).selectOption(String(fixture.house))
      await panel.getByLabel('Tháng hóa đơn', { exact: true }).fill('2026-10')
      await panel.getByRole('button', { name: 'Tạo hóa đơn tháng', exact: true }).click()
      await panel.getByText(/Hóa đơn đã tồn tại/).waitFor()
      await panel.getByRole('status').filter({ hasText: 'Bị chặn: 1' }).waitFor()
      assert.equal(requests, 2)
      cli('assert-monthly')
      let recalculationProof = null, waterRecalculationProof = null, confirmationRequests = 0, browserManualId = null
      if (recalculate) {
        const invoiceId = Number((await panel.getByRole('link', { name: /INV-/ }).getAttribute('href')).split('/').pop())
        const login = await context.request.post('http://127.0.0.1:8849/api/login', { data: { email: 'billing-browser@example.invalid', password: 'BrowserTest123!' }, headers: { Accept: 'application/json' } })
        assert.equal(login.status(), 200)
        const session = (await login.json()).data
        const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}` }
        const legacyGenerate = await context.request.post(`http://127.0.0.1:8849/api/invoices/${invoiceId}/generate`, { headers })
        const legacyUpdate = await context.request.put(`http://127.0.0.1:8849/api/invoices/${invoiceId}`, { headers, data: { billing_period: '2026-10-01', status: 'UNPAID' } })
        const legacyDelete = await context.request.delete(`http://127.0.0.1:8849/api/invoices/${invoiceId}`, { headers })
        assert.equal(legacyGenerate.status(), 422); assert.equal(legacyUpdate.status(), 422); assert.equal(legacyDelete.status(), 422)
        const manual = await context.request.post(`http://127.0.0.1:8849/api/invoices/${invoiceId}/items`, { headers, data: { type: 'OTHER', description: 'Browser manual retained', quantity: 1, unit_price: 5000 } })
        assert.equal(manual.status(), 201)
        const invoiceBefore = await context.request.get(`http://127.0.0.1:8849/api/invoices/${invoiceId}`, { headers })
        assert.equal(invoiceBefore.status(), 200)
        browserManualId = (await invoiceBefore.json()).data.items.find(item => item.source === 'MANUAL')?.id
        assert.ok(browserManualId, 'Manual invoice item must have a persisted ID before recalculation.')
        const checkpoint = await context.request.post(`http://127.0.0.1:8849/api/billing-v2/meters/${fixture.meter}/checkpoints`, { headers, data: { kind: 'PERIOD_BOUNDARY', observed_at: '2026-11-01 00:00:00', reading_value: 155 } })
        assert.equal(checkpoint.status(), 200)
        const update = panel.getByRole('region', { name: 'Cập nhật tính toán hóa đơn nháp' })
        await update.getByRole('button', { name: 'Cập nhật tính toán hóa đơn nháp', exact: true }).click()
        await update.getByRole('heading', { name: 'Chênh lệch trước / sau' }).waitFor()
        cli('assert-update-preview')
        await update.locator('textarea').fill('Đối soát chỉ số điện cuối kỳ')
        await update.getByRole('checkbox').check()
        let loseConfirm = true
        await page.route('**/monthly-recalculations/*/confirm', async route => {
          confirmationRequests++
          const response = await route.fetch()
          if (loseConfirm) { assert.equal(response.status(), 200); loseConfirm = false; await route.abort('failed') } else await route.fulfill({ response })
        })
        await update.getByRole('button', { name: 'Xác nhận cập nhật draft', exact: true }).evaluate(button => { button.click(); button.click() })
        await update.getByRole('alert').filter({ hasText: 'Chưa xác định' }).waitFor()
        assert.equal(confirmationRequests, 1)
        cli('assert-update')
        await update.getByRole('button', { name: 'Xác nhận cập nhật draft', exact: true }).click()
        await update.getByRole('status').filter({ hasText: 'Đã cập nhật bởi người dùng' }).waitFor()
        assert.equal(confirmationRequests, 2)
        recalculationProof = cli('assert-update')
        const waterCheckpoint = await context.request.post(`http://127.0.0.1:8849/api/billing-v2/meters/${fixture.waterMeter}/checkpoints`, { headers, data: { kind: 'PERIOD_BOUNDARY', observed_at: '2026-11-01 00:00:00', reading_value: 45 } })
        assert.equal(waterCheckpoint.status(), 200)
        await update.getByRole('button', { name: 'Cập nhật tính toán hóa đơn nháp', exact: true }).click()
        await update.getByRole('heading', { name: 'Chênh lệch trước / sau' }).waitFor()
        cli('assert-water-update-preview')
        await update.locator('textarea').fill('Đối soát chỉ số nước cuối kỳ')
        await update.getByRole('checkbox').check()
        const changedManual = await context.request.put(`http://127.0.0.1:8849/api/invoice-items/${browserManualId}`, { headers, data: { type: 'OTHER', description: 'Browser manual retained', quantity: 1, unit_price: 6000 } })
        assert.equal(changedManual.status(), 200)
        const staleConfirm = page.waitForResponse(response => response.url().includes('/monthly-recalculations/') && response.url().endsWith('/confirm') && response.request().method() === 'POST')
        await update.getByRole('button', { name: 'Xác nhận cập nhật draft', exact: true }).click()
        assert.equal((await staleConfirm).status(), 422)
        await update.getByRole('alert').filter({ hasText: 'Hóa đơn đã thay đổi sau khi xem trước' }).waitFor()
        const restoredManual = await context.request.put(`http://127.0.0.1:8849/api/invoice-items/${browserManualId}`, { headers, data: { type: 'OTHER', description: 'Browser manual retained', quantity: 1, unit_price: 5000 } })
        assert.equal(restoredManual.status(), 200)
        await update.getByRole('button', { name: 'Cập nhật tính toán hóa đơn nháp', exact: true }).click()
        await update.getByRole('heading', { name: 'Chênh lệch trước / sau' }).waitFor()
        cli('assert-water-update-preview')
        await update.locator('textarea').fill('Đối soát chỉ số nước cuối kỳ')
        await update.getByRole('checkbox').check()
        await update.getByRole('button', { name: 'Xác nhận cập nhật draft', exact: true }).click()
        await update.getByRole('status').filter({ hasText: 'Đã cập nhật bởi người dùng' }).waitFor()
        waterRecalculationProof = cli('assert-water-update')
        const invoiceAfter = await context.request.get(`http://127.0.0.1:8849/api/invoices/${invoiceId}`, { headers })
        assert.equal(invoiceAfter.status(), 200)
        assert.equal((await invoiceAfter.json()).data.items.find(item => item.source === 'MANUAL')?.id, browserManualId, 'Recalculation must keep the manual item ID.')
      }
      await panel.getByText('Chi tiết khoản thu', { exact: true }).click()
      await page.getByRole('button', { name: 'Đóng thông báo' }).evaluateAll(buttons => buttons.forEach(b => b.click()))
      await page.evaluate(() => scrollTo(0, 0))
      await page.screenshot({ path: path.join(output, 'desktop.png'), fullPage: true })
      await page.setViewportSize({ width: 390, height: 844 })
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
      await page.screenshot({ path: path.join(output, 'mobile.png'), fullPage: true })
      assert.deepEqual(errors, []); assert.deepEqual(serverErrors, [])
      if (recalculate) {
        await panel.getByRole('link', { name: /INV-/ }).click()
        await page.getByRole('heading', { name: 'Nguồn tính hóa đơn tháng' }).waitFor()
        await page.getByText(/Cập nhật #.*Đối soát chỉ số nước cuối kỳ/).waitFor()
        await page.screenshot({ path: path.join(output, 'history.png'), fullPage: true })
      }
      await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, ...proof, requests, lostResponseRetryAfterReload: true, ...(recalculationProof ? { recalculation: recalculationProof, waterRecalculation: waterRecalculationProof, confirmationRequests } : {}), pageErrors: errors, serverErrors }, null, 2))
      return
    }
    let generationRequests = 0, loseResponse = true
    await page.route('**/billing-v2/rooms/*/generate', async route => {
      generationRequests++
      if (loseResponse) { const response = await route.fetch(); assert.equal(response.status(), 200); loseResponse = false; await route.abort('failed') } else await route.continue()
    })
    await page.getByRole('button', { name: 'Tính khoản thu điện nước', exact: true }).evaluate(button => { button.click(); button.click() })
    await page.locator('section > [role=alert]').waitFor()
    assert.match(await page.locator('section > [role=alert]').innerText(), /Chưa xác định thao tác/)
    assert.equal(generationRequests, 1, 'Double click must submit once even when response is lost')
    await page.waitForFunction(() => !document.querySelector('fieldset').disabled)
    await submit('Tính khoản thu điện nước', '/generate')
    await page.getByRole('link', { name: new RegExp(`Hợp đồng #${fixture.b} — hóa đơn`) }).waitFor()
    assert.equal(generationRequests, 2)
    assert.equal(await page.getByRole('link', { name: /— hóa đơn #/ }).count(), 1)
    const proof = cli('assert')
    let invoiceId
    if (combined) {
      const login = await context.request.post('http://127.0.0.1:8849/api/login', { data: { email: 'billing-browser@example.invalid', password: 'BrowserTest123!' }, headers: { Accept: 'application/json' } })
      assert.equal(login.status(), 200)
      const session = (await login.json()).data
      const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}` }
      invoiceId = Number((await page.getByRole('link', { name: /— hóa đơn #/ }).getAttribute('href')).split('/').pop())
      for (const [type, description, quantity, unit_price] of [['RENT', 'Synthetic agreed rent - MANUAL', 1, 3800000], ['INTERNET', 'Synthetic fixed charge - MANUAL', 1, 120000], ['WATER', 'Synthetic person charge - MANUAL', 2, 100000]]) {
        const response = await context.request.post(`http://127.0.0.1:8849/api/invoices/${invoiceId}/items`, { headers, data: { type, description, quantity, unit_price } })
        assert.equal(response.status(), 201)
      }
      const regenerated = await context.request.post(`http://127.0.0.1:8849/api/invoices/${invoiceId}/generate`, { headers })
      assert.equal(regenerated.status(), 200)
      await page.reload()
      await page.getByRole('heading', { name: 'Quyết toán điện nước V2', exact: true }).waitFor()
      await page.getByLabel('Kỳ hóa đơn', { exact: true }).fill('2026-10')
      await page.getByRole('button', { name: /Phát hành hóa đơn #/ }).waitFor()
    }
    const issueButton = page.getByRole('button', { name: /Phát hành hóa đơn #/ })
    page.once('dialog', dialog => dialog.accept())
    const issueResponse = page.waitForResponse(response => response.url().includes('/issue') && response.request().method() === 'POST')
    await issueButton.click(); assert.equal((await issueResponse).status(), 200)
    await page.getByText('Chưa thanh toán', { exact: true }).waitFor()
    let combinedProof = null
    if (combined) {
      await page.getByRole('link', { name: /— hóa đơn #/ }).click()
      await page.getByText('Synthetic agreed rent - MANUAL', { exact: true }).waitFor()
      await page.getByText('Synthetic fixed charge - MANUAL', { exact: true }).waitFor()
      await page.getByText('Synthetic person charge - MANUAL', { exact: true }).waitFor()
      await page.getByText('4.168.000 đ', { exact: true }).first().waitFor()
      await page.getByRole('button', { name: 'Ghi nhận tiền mặt', exact: true }).click()
      const paymentDialog = page.getByRole('dialog', { name: 'Ghi nhận thanh toán', exact: true })
      await paymentDialog.getByLabel('Số tiền thanh toán', { exact: true }).fill('4168000')
      await paymentDialog.getByPlaceholder('Mã tham chiếu').fill('synthetic-combined-payment')
      const paidResponse = page.waitForResponse(response => response.url().endsWith(`/invoices/${invoiceId}/payments`) && response.request().method() === 'POST')
      await paymentDialog.getByRole('button', { name: 'Lưu thanh toán', exact: true }).click()
      assert.equal((await paidResponse).status(), 201)
      await paymentDialog.waitFor({ state: 'hidden' })
      await page.getByText('Đã thanh toán', { exact: true }).first().waitFor()
      await page.getByRole('button', { name: 'Ghi nhận tiền mặt', exact: true }).waitFor({ state: 'hidden' })
      combinedProof = cli('assert-combined')
    }
    await page.getByRole('button', { name: 'Đóng thông báo' }).evaluateAll(buttons => buttons.forEach(button => button.click()))
    await page.screenshot({ path: path.join(output, 'desktop.png'), fullPage: true })
    await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(() => scrollTo(0, 0))
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
    await page.screenshot({ path: path.join(output, 'mobile.png'), fullPage: true })
    assert.deepEqual(errors, []); assert.deepEqual(serverErrors, [])
    await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, ...proof, pendingCheckout: pendingProof, generationRequests, ...(combinedProof ? { combinedInvoice: combinedProof } : {}), pageErrors: errors, serverErrors }, null, 2))
    console.log(JSON.stringify(proof))
  } catch (error) { if (page) { await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }); console.error((await page.locator('body').innerText()).slice(-1200)) } console.error(phpLog.split('\n').filter(line => line.includes('ERROR')).map(line => line.slice(0, 1200)).join('\n')); throw error }
  finally { if (context) await context.tracing.stop({ path: path.join(output, 'trace.zip') }); await browser?.close(); await new Promise(resolve => server.close(resolve)); phpServer.kill() }
})
