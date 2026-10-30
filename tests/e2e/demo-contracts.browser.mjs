import test from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { spawn, spawnSync } from 'node:child_process'
import { createServer, request as httpRequest } from 'node:http'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import path from 'node:path'

const root = process.cwd()
const php = process.env.E2E_PHP_PATH
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_PACKAGE_PATH || 'playwright')
const directory = path.join(root, 'tmp', `demo-contract-${Date.now()}`)
const backend = path.join(root, 'backend-api')
const dist = path.join(root, 'tmp', 'demo-contract-dist')

test('real React + Laravel + SQLite: register, save room/dorm demo drafts, reopen, print PDF, preserve FULL', { timeout: 120000 }, async () => {
  assert.ok(php && existsSync(php), 'Set E2E_PHP_PATH to local PHP')
  assert.equal(existsSync(path.join(backend, 'bootstrap/cache/config.php')), false, 'Refuse cached environment configuration')
  await mkdir(directory, { recursive: true })
  const database = path.join(directory, 'database.sqlite')
  await writeFile(database, '')
  const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', APP_URL: 'http://127.0.0.1:8818', FRONTEND_URL: 'http://127.0.0.1:8819', DB_URL: '', DB_CONNECTION: 'sqlite', DB_DATABASE: database, MAIL_MAILER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'stderr', BCRYPT_ROUNDS: '4' }
  const cli = (...args) => { const result = spawnSync(php, args, { cwd: backend, env, encoding: 'utf8' }); assert.equal(result.status, 0, result.stdout + result.stderr); return result.stdout }
  cli('artisan', 'migrate', '--force', '--no-interaction')
  cli('artisan', 'db:seed', '--class=RestoreDefaultContractTemplatesSeeder', '--no-interaction')
  cli('artisan', 'db:seed', '--class=DemoContractTemplatesSeeder', '--no-interaction')
  const probe = createServer()
  await new Promise((resolve, reject) => { probe.once('error', reject); probe.listen(8818, '127.0.0.1', resolve) })
  await new Promise(resolve => probe.close(resolve))
  const processPhp = spawn(php, ['-S', '127.0.0.1:8818', path.join(backend, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(backend, 'public'), env, windowsHide: true, stdio: 'pipe' })
  let phpLog = ''
  processPhp.stdout.on('data', data => { phpLog += data })
  processPhp.stderr.on('data', data => { phpLog += data })
  const server = createServer(async (req, res) => {
    if (req.url.startsWith('/api/')) {
      const proxy = httpRequest({ hostname: '127.0.0.1', port: 8818, path: req.url, method: req.method, headers: { ...req.headers, host: '127.0.0.1:8818' } }, upstream => { res.writeHead(upstream.statusCode, upstream.headers); upstream.pipe(res) })
      proxy.on('error', () => { res.writeHead(502); res.end() }); req.pipe(proxy); return
    }
    try { const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname); let file = path.resolve(dist, '.' + pathname); if (!file.startsWith(dist + path.sep) || !existsSync(file) || !path.extname(file)) file = path.join(dist, 'index.html'); res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml' })[path.extname(file)] || 'application/octet-stream'); res.end(await readFile(file)) } catch { res.writeHead(404); res.end() }
  })
  let browser
  try {
    await new Promise(resolve => server.listen(8819, '127.0.0.1', resolve))
    for (let attempt = 0; attempt < 60; attempt++) { try { await fetch('http://127.0.0.1:8818/up'); break } catch { await new Promise(resolve => setTimeout(resolve, 100)) } }
    browser = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM_PATH, headless: true })
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } })
    await context.route('**/*', route => new URL(route.request().url()).origin === 'http://127.0.0.1:8819' ? route.continue() : route.abort())
    const api = async (method, url, data, token) => { const response = await context.request.fetch('http://127.0.0.1:8819/api' + url, { method, data, headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) } }); const body = await response.json(); assert.ok(response.ok(), `${method} ${url}: ${response.status()} ${JSON.stringify(body)}`); return body.data }
    await api('POST', '/register', { name: 'Browser Demo', email: 'demo-browser@example.invalid', phone: '0000000000', password: 'BrowserTest123!', contract_access: 'FULL' })
    cli('tests/Fixtures/DemoContractBrowser.php', 'verify')
    const login = await api('POST', '/login', { email: 'demo-browser@example.invalid', password: 'BrowserTest123!' })
    assert.equal(login.user.contract_access, 'DEMO')
    const token = login.token
    await api('POST', '/boarding-houses', { name: 'Nhà trọ test trình duyệt', address: 'Địa chỉ giả lập', ward: 'Phường minh họa' }, token)
    const house = (await api('GET', '/boarding-houses', undefined, token))[0]
    await api('POST', '/tenants', { full_name: 'Người thuê trình duyệt', phone: '0000000000' }, token)
    const page = await context.newPage()
    await page.goto('http://127.0.0.1:8819/login')
    await page.locator('input[name=email]').fill('demo-browser@example.invalid')
    await page.locator('input[name=password]').fill('BrowserTest123!')
    await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
    await page.waitForURL('**/dashboard')
    for (const [kind, type, roomType, name] of [['room', 'ROOM_RENTAL', 'PRIVATE_ROOM', 'Hợp đồng thuê phòng trọ — DEMO'], ['dorm', 'DORM_BED', 'DORMITORY', 'Hợp đồng KTX — DEMO']]) {
      const room = await api('POST', `/boarding-houses/${house.id}/rooms`, { room_code: kind, room_type: roomType, status: 'AVAILABLE', monthly_rent: 2345000, max_tenants: 4, ...(kind === 'dorm' ? { beds: [{ bed_code: 'G01', monthly_rent: 2345000 }] } : {}) }, token)
      await page.goto(`http://127.0.0.1:8819/rooms/${room.id}/contracts/new`)
      await page.getByLabel('Loại hợp đồng', { exact: true }).selectOption(type)
      const template = page.getByLabel('Mẫu hợp đồng', { exact: true })
      await template.selectOption({ label: `${name} — v1` })
      assert.equal(await template.locator('option').count(), 2)
      await page.getByRole('button', { name: 'Người đứng tên', exact: true }).click()
      await page.getByRole('dialog').getByRole('radio').first().check()
      await page.getByRole('button', { name: 'Xác nhận chọn' }).click()
      if (kind === 'dorm') await page.getByLabel('Giường', { exact: true }).selectOption({ label: 'G01' })
      await page.getByPlaceholder('dd/mm/yyyy').nth(0).fill('01/11/2026')
      await page.getByPlaceholder('dd/mm/yyyy').nth(1).fill('31/10/2027')
      await page.getByPlaceholder('0', { exact: true }).nth(1).fill('123000')
      await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click()
      await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click()
      await page.getByLabel('Người đại diện Bên A (bắt buộc khi kích hoạt)').selectOption(String(login.user.id))
      await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click()
      const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith(`/rooms/${room.id}/contracts`))
      await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click()
      const saved = await response; assert.equal(saved.status(), 201, await saved.text()); const id = (await saved.json()).data.id
      await page.waitForURL(url => url.pathname === `/contracts/${id}`)
      await page.reload()
      assert.equal(await page.getByRole('button', { name: 'Kích hoạt', exact: true }).isDisabled(), true)
      await api('POST', `/contracts/${id}/handover-check-in`, undefined, token)
      const report = await api('GET', `/contracts/${id}/handover-check-in`, undefined, token)
      report.items[0].condition = 'GOOD'
      report.items[0].note = 'Đã kiểm tra bàn giao qua trình duyệt'
      await api('PUT', `/contracts/${id}/handover-check-in`, { items: report.items }, token)
      await api('POST', `/contracts/${id}/handover-check-in/confirm`, undefined, token)
      await page.goto(`http://127.0.0.1:8819/contracts/${id}/document?preview=1`)
      await page.getByRole('button', { name: 'In hợp đồng / Lưu PDF' }).waitFor()
      assert.equal(await page.locator('.contract-document-html h2').count(), 2)
      assert.equal(await page.locator('.signature-handwritten-space').count(), 2)
      assert.equal(await page.locator('.contract-handover-table tbody tr').count(), kind === 'room' ? 13 : 6)
      const content = await page.locator('.contract-document-html').innerText()
      assert.match(content, /Đã kiểm tra bàn giao qua trình duyệt/); assert.match(content, /Người thuê trình duyệt/); assert.match(content, /2\.345\.000/); assert.doesNotMatch(content, /ĐIỀU 3:|0385528994|052098006132|VU HOANG MINH TU/)
      await page.screenshot({ path: path.join(directory, `${kind}-preview.png`), fullPage: true })
      await page.pdf({ path: path.join(directory, `${kind}-demo.pdf`), format: 'A4', printBackground: true })
    }
    cli('tests/Fixtures/DemoContractBrowser.php', 'full')
    const full = await api('POST', '/login', { email: 'full-browser@example.invalid', password: 'BrowserTest123!' })
    await api('POST', '/boarding-houses', { name: 'Full fixture', address: 'Synthetic' }, full.token)
    const fullHouse = (await api('GET', '/boarding-houses', undefined, full.token))[0]
    const fullRoom = await api('POST', `/boarding-houses/${fullHouse.id}/rooms`, { room_code: 'FULL', status: 'AVAILABLE' }, full.token)
    const fullContext = await api('GET', `/rooms/${fullRoom.id}/contract-context`, undefined, full.token)
    assert.ok(fullContext.templates.some(t => t.template_key === 'ROOM_RENTAL_STANDARD'))
    assert.ok(fullContext.templates.some(t => t.template_key === 'DORM_BED_STANDARD'))
    await api('POST', '/tenants', { full_name: 'Full tenant test' }, full.token)
    const fullTenant = (await api('GET', '/tenants', undefined, full.token))[0]
    for (const type of ['ROOM_RENTAL', 'DORM_BED']) {
      const saved = await api('POST', `/rooms/${fullRoom.id}/contracts`, { tenant_id: fullTenant.id, contract_type: type }, full.token)
      const preview = await api('GET', `/contracts/${saved.id}/document/preview`, undefined, full.token)
      assert.match(preview.rendered_html, /ĐIỀU 3:/)
    }
    const proof = cli('tests/Fixtures/DemoContractBrowser.php', 'assert')
    await writeFile(path.join(directory, 'database-proof.json'), proof)
    console.log(`Artifacts: ${directory}; DB proof: ${proof}`)
  } catch (error) { console.error(error); throw error } finally { if (browser) await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); processPhp.kill(); await writeFile(path.join(directory, 'laravel.log'), phpLog) }
})
