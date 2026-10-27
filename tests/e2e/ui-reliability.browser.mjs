import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { accounts, startSyntheticServer } from './synthetic-session-server.mjs'

const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const baseline = process.argv.includes('--baseline')
const blurBaseline = process.argv.includes('--blur-baseline')
const output = resolve(root, 'tmp/ui-reliability')
await mkdir(output, { recursive: true })
const api = await startSyntheticServer(resolve(root, 'front-end/dist'))
const origin = 'http://127.0.0.1:18940'
const server = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'),
  define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') },
  plugins: [{ name: 'reliability-probe', enforce: 'pre', transform(source, id) {
    if (blurBaseline && id.endsWith('/BoardingHouseForm.jsx')) return source.replace("mode: 'onSubmit'", "mode: 'onTouched'")
  }, transformIndexHtml: html => html.replace('</body>', '<script type="module" src="/__reliability-probe.js"></script></body>'), configureServer(vite) {
    vite.middlewares.use('/__reliability-probe.js', (_request, response) => {
      response.setHeader('Content-Type', 'text/javascript')
      response.end("import client from '/src/api/axiosClient.js'; import { getSession } from '/src/api/session.js'; import { clearToasts, getToasts } from '/src/notifications/toastStore.js'; window.__reliability = { client, getSession, clearToasts, getToasts };")
    })
  } }], server: { host: '127.0.0.1', port: 18940, strictPort: true } })
let browser, releaseHold
const report = { mode: baseline ? 'before' : blurBaseline ? 'blur-before' : 'after', cases: [], requests: [], errors: [], consoleErrors: [] }
try {
  await server.listen()
  browser = await chromium.launch({ headless: true, executablePath: process.env.E2E_CHROMIUM_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe' })
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' })
  await context.tracing.start({ screenshots: true, snapshots: true })
  const page = await context.newPage()
  await page.clock.install()
  page.on('pageerror', error => report.errors.push(error.message))
  page.on('console', message => {
    if (message.type() === 'error' && !/Failed to load resource|net::ERR_/i.test(message.text())) report.consoleErrors.push(message.text())
  })
  let fault = '', writes = 0, offlineNetwork = false
  const house = { ...accounts.A.house, rooms_count: 1, managers: [], operational_role: 'OWNER' }
  const room = { ...accounts.A.room, boarding_house: house, services: [] }
  const contract = { id: 101, room_id: 101, contract_code: 'TEST-CONTRACT', contract_type: 'ROOM_RENTAL', status: 'DRAFT',
    start_date: '2026-10-01', end_date: '2027-09-30', monthly_rent: 1000000, tenant: accounts.A.tenant, room, occupants: [] }
  let handover = { id: 101, status: 'DRAFT', items: [{ name: 'Tivi', quantity: 1, condition: 'GOOD', note: null }] }
  const reply = (route, status, data, message = 'Đã lưu dữ liệu kiểm thử.') => route.fulfill({ status, json: { success: status < 400, data, message, ...(status === 422 ? { errors: { name: ['Tên nhà trọ đã tồn tại.'] } } : {}) } })
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    assert.equal(url.origin, origin, 'No production or external requests allowed')
    if (!url.pathname.startsWith('/api/')) return route.continue()
    const path = url.pathname.slice(4), method = request.method()
    const metric = { method, path }
    report.requests.push(metric)
    if (offlineNetwork) { metric.status = 'offline'; return route.abort('internetdisconnected') }
    if (method === 'PUT' && path === '/boarding-houses/101') {
      writes++
      if (fault === 'slow') await new Promise(resolve => setTimeout(resolve, 250))
      if (fault === 'hang') await new Promise(resolve => { releaseHold = resolve })
      if (fault === 'network') { metric.status = 'network-error'; return route.abort('failed') }
      const status = Number(fault) || 200
      metric.status = status
      if (status === 200) Object.assign(house, request.postDataJSON())
      return reply(route, status, null, status === 200 ? 'Đã cập nhật nhà trọ.' : status === 422 ? 'Dữ liệu không hợp lệ.' : 'Không thể lưu dữ liệu.')
    }
    if (method === 'GET' && path === '/boarding-houses/101') {
      metric.status = 200
      return reply(route, 200, house)
    }
    if (method === 'GET') {
      const fixtures = {
        '/residence/boarding-houses': [house], '/residence/boarding-houses/101/rooms': [room], '/residence-declarations': [],
        '/rooms/101': room, '/rooms/101/contracts': [contract], '/rooms/101/room-tenants': [], '/rooms/101/tenants': [], '/rooms/101/beds': [],
        '/boarding-houses/101/residence-profile': {}, '/boarding-houses/101/managers': [],
        '/rooms/101/services': [], '/rooms/101/utility-meters': [], '/boarding-houses/101/services': [], '/boarding-houses/101/floors': [], '/amenities': [],
        '/contracts/101': contract, '/contracts/101/handover-check-in': handover, '/contracts/101/services': { terms: [], available_services: [], today: '2026-10-27' },
        '/landlord/payment-profile': { bank_name: 'Synthetic bank', bank_account_name: 'Synthetic owner', bank_account_number: '000000', has_payment_qr: false },
        '/landlord/payos-config': null,
      }
      if (path in fixtures) { metric.status = 200; return reply(route, 200, fixtures[path]) }
    }
    if (method === 'PUT' && path === '/rooms/101/services') { metric.status = 200; return reply(route, 200, null) }
    if (method === 'PUT' && path === '/contracts/101/handover-check-in') { handover = { ...handover, ...request.postDataJSON() }; metric.status = 200; return reply(route, 200, handover, 'Đã lưu nháp bàn giao.') }
    const response = await fetch(`${api.origin}${url.pathname}${url.search}`, { method, headers: { 'Content-Type': 'application/json', ...(request.headers().authorization ? { Authorization: request.headers().authorization } : {}) }, ...(request.postData() ? { body: request.postData() } : {}) })
    metric.status = response.status
    return route.fulfill({ status: response.status, contentType: 'application/json', body: await response.text() })
  })
  await page.addInitScript(() => {
    window.__documentIdentity = Math.random(); window.__actionClicks = 0; window.__submitPointers = []
    document.addEventListener('click', event => { if (event.target.closest('button[type="submit"], form button:not([type])')) window.__actionClicks++ })
    for (const type of ['pointerdown', 'pointerup']) document.addEventListener(type, event => {
      const button = event.target.closest('form button')
      window.__submitPointers.push({ type, x: event.clientX, y: event.clientY, button: button?.textContent ?? null })
    })
  })
  await page.goto(`${origin}/login`)
  await page.getByLabel('Email', { exact: true }).fill(accounts.A.user.email)
  await page.getByLabel('Mật khẩu', { exact: true }).fill(accounts.A.password)
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
  await page.waitForURL('**/dashboard')
  await page.waitForLoadState('networkidle')
  const documentId = await page.evaluate(() => window.__documentIdentity)
  const cdp = await context.newCDPSession(page)
  await cdp.send('Debugger.enable')
  report.listenerDetails = []
  const harnessScripts = new Map()
  const listenerCounts = async () => {
    const counts = {}
    for (const expression of ['document', 'window']) {
      const result = await cdp.send('Runtime.evaluate', { expression })
      const { listeners } = await cdp.send('DOMDebugger.getEventListeners', { objectId: result.result.objectId })
      for (const listener of listeners) {
        if (!harnessScripts.has(listener.scriptId)) {
          const { scriptSource } = await cdp.send('Debugger.getScriptSource', { scriptId: listener.scriptId })
          harnessScripts.set(listener.scriptId, /__playwright_(?:snapshotter_)?global_listeners_check__/.test(scriptSource))
        }
      }
      // Playwright installs its own pointer interceptors during tab/clock tests.
      // Count app listeners by their script identity, not by event type.
      counts[expression] = listeners.filter(listener => !harnessScripts.get(listener.scriptId)).length
      report.listenerDetails.push({ target: expression, rawCount: listeners.length, listeners: listeners.map(listener => ({ type: listener.type, testHarness: harnessScripts.get(listener.scriptId) })) })
      await cdp.send('Runtime.releaseObject', { objectId: result.result.objectId })
    }
    return counts
  }
  const initialListeners = await listenerCounts()
  const navigate = async path => {
    await page.evaluate(path => { history.pushState(null, '', path); dispatchEvent(new PopStateEvent('popstate')) }, path)
    await page.waitForURL(url => url.pathname === path)
  }
  const edit = async () => {
    fault = ''
    await navigate('/boarding-houses/101/edit')
    await page.getByLabel('Tên nhà trọ', { exact: true }).waitFor()
    await page.waitForLoadState('networkidle')
    await page.getByLabel('Tên nhà trọ', { exact: true }).fill('Synthetic reliability house')
    await page.evaluate(() => window.__reliability.clearToasts())
  }
  const save = () => page.getByRole('button', { name: 'Lưu thay đổi', exact: true })
  const snapshot = () => page.evaluate(() => ({ clicked: window.__actionClicks, timeout: window.__reliability.client.defaults.timeout,
    mutations: window.__reliability.getSession().queryClient.getMutationCache().getAll().map(mutation => ({ status: mutation.state.status, paused: mutation.state.isPaused })),
    toasts: window.__reliability.getToasts().map(toast => ({ type: toast.type, message: toast.message })),
    disabled: [...document.querySelectorAll('form button')].some(button => button.disabled), overlay: Boolean(document.querySelector('dialog[open]')) }))

  await edit()
  offlineNetwork = true
  await context.setOffline(true)
  const beforeOfflineWrites = writes
  await save().click()
  await page.waitForTimeout(500)
  const offline = await snapshot()
  report.cases.push({ name: 'offline-submit', ...offline, writes: writes - beforeOfflineWrites })
  if (!baseline) {
    await save().waitFor()
    assert.equal(await save().isEnabled(), true)
    assert.equal(offline.mutations.some(mutation => mutation.status === 'pending'), false)
    assert.equal(offline.toasts.length, 1)
  }
  offlineNetwork = false
  await context.setOffline(false)
  if (baseline) await page.waitForURL('**/boarding-houses/101')
  else { await save().click(); await page.waitForURL('**/boarding-houses/101') }

  await edit()
  fault = 'hang'
  if (!baseline) await page.evaluate(() => { window.__reliability.client.defaults.timeout = 700 })
  await save().click()
  await page.waitForTimeout(1100)
  report.cases.push({ name: 'stalled-request', ...await snapshot() })
  if (baseline) { fault = '500'; releaseHold?.(); await page.waitForTimeout(150) }
  else {
    assert.equal(await save().isEnabled(), true)
    assert.equal((await snapshot()).toasts.length, 1)
    fault = '500'; releaseHold?.()
    await page.waitForTimeout(100)
    fault = ''; await save().click(); await page.waitForURL('**/boarding-houses/101')
  }

  await edit()
  await page.getByLabel('Tên nhà trọ', { exact: true }).fill('')
  const beforeValidation = writes
  const beforeValidationClicks = await page.evaluate(() => window.__actionClicks)
  await save().click()
  await page.waitForTimeout(150)
  report.cases.push({ name: 'client-validation', ...await snapshot(), writes: writes - beforeValidation, clickDelta: await page.evaluate(before => window.__actionClicks - before, beforeValidationClicks), pointers: await page.evaluate(() => window.__submitPointers.slice(-2)) })
  if (!baseline && !blurBaseline) {
    assert.equal((await snapshot()).toasts.length, 1)
  }

  if (!baseline && !blurBaseline) {
    for (const failure of ['422', '403', '500', 'network']) {
      await edit(); fault = failure
      const before = writes
      await save().click()
      await page.waitForFunction(() => window.__reliability.getToasts().length > 0)
      assert.equal(await save().isEnabled(), true)
      assert.equal(writes - before, 1)
      assert.equal((await snapshot()).toasts.length, 1)
      report.cases.push({ name: `retry-after-${failure}`, ...await snapshot(), writes: writes - before })
      fault = ''
      await page.getByLabel('Tên nhà trọ', { exact: true }).fill(`Synthetic recovered ${failure}`)
      await save().click()
      await page.waitForURL('**/boarding-houses/101')
    }
    for (let cycle = 0; cycle < 2; cycle++) {
      for (const menu of ['Dashboard', 'Nhà trọ', 'Phòng', 'Người thuê', 'Hợp đồng', 'Hóa đơn', 'Dịch vụ', 'Điện nước', 'Khai báo cư trú']) {
        await page.locator('aside').getByRole('link', { name: menu, exact: true }).click()
        await page.waitForLoadState('networkidle')
        assert.equal(await page.locator('form form').count(), 0)
      }
      await navigate('/rooms/101')
      await page.getByRole('button', { name: 'Chỉnh sửa dịch vụ', exact: true }).waitFor()
      for (let reopen = 0; reopen < 2; reopen++) {
        await page.getByRole('button', { name: 'Chỉnh sửa dịch vụ', exact: true }).click()
        await page.getByRole('dialog').getByRole('button', { name: 'Đóng', exact: true }).click()
      }
      await page.getByRole('button', { name: 'Chỉnh sửa dịch vụ', exact: true }).click()
      await page.getByRole('dialog').getByRole('button', { name: 'Lưu dịch vụ', exact: true }).click()
      await page.getByRole('dialog').waitFor({ state: 'hidden' })
      await navigate('/contracts/101')
      await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click()
      await page.waitForFunction(() => window.__reliability.getSession().queryClient.isMutating() === 0)
      await edit()
      await save().click()
      await page.waitForURL('**/boarding-houses/101')
    }
    report.cases.push({ name: 'spa-nine-modules-room-contract-mutation', cycles: 2, reloads: 0 })

    await navigate('/payment-settings')
    await page.getByLabel('Ngân hàng', { exact: true }).waitFor()
    await page.locator('aside').getByRole('link', { name: 'Người thuê', exact: true }).click()
    await page.getByRole('button', { name: 'Import Excel', exact: true }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Đóng import', exact: true }).click()
    await page.getByRole('button', { name: 'Import Excel', exact: true }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Đóng import', exact: true }).click()
    await navigate('/payment-settings')
    await page.getByLabel('Ngân hàng', { exact: true }).waitFor()
    await page.getByRole('button', { name: 'Menu tài khoản', exact: true }).click()
    await page.keyboard.press('Escape')
    await page.goBack(); await page.goForward()
    await page.getByLabel('Ngân hàng', { exact: true }).waitFor()
    const otherTab = await context.newPage(); await otherTab.bringToFront(); await page.bringToFront(); await otherTab.close()
    await page.clock.fastForward(20 * 60 * 1000); await page.clock.resume()
    await edit()
    fault = 'slow'
    const beforeRapid = writes
    await save().evaluate(button => { button.click(); button.click(); button.click() })
    await page.waitForURL('**/boarding-houses/101')
    assert.equal(writes - beforeRapid, 1)
    report.cases.push({ name: 'tab-history-20min-virtual-idle-slow-triple-click', writes: 1, clicks: 3 })

    await page.setViewportSize({ width: 390, height: 844 })
    for (let round = 0; round < 3; round++) {
      await page.getByRole('button', { name: 'Mở menu', exact: true }).click()
      const drawer = page.getByRole('dialog', { name: 'Menu BeeHouse', exact: true })
      await drawer.getByRole('link', { name: 'Nhà trọ', exact: true }).click()
      await drawer.waitFor({ state: 'hidden' })
      assert.notEqual(await page.evaluate(() => document.body.style.overflow), 'hidden')
    }
    await edit(); await save().click(); await page.waitForURL('**/boarding-houses/101')
    report.cases.push({ name: 'mobile-drawer-cleanup-and-save', rounds: 3, reloads: 0 })

    await edit(); fault = '401'
    await save().click()
    await page.waitForURL('**/login')
    assert.equal(await page.evaluate(() => localStorage.getItem('access_token')), null)
    await page.getByLabel('Email', { exact: true }).fill(accounts.A.user.email)
    await page.getByLabel('Mật khẩu', { exact: true }).fill(accounts.A.password)
    await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
    await page.waitForURL('**/dashboard')
    await edit(); await save().click(); await page.waitForURL('**/boarding-houses/101')
    report.cases.push({ name: '401-login-again-without-reload', recovered: true })
  }
  // Compare the same route and closed menus, not route-specific active listeners.
  if (!baseline && !blurBaseline) await navigate('/dashboard')
  await page.waitForLoadState('networkidle')
  report.listeners = { before: initialListeners, after: await listenerCounts() }
  await writeFile(resolve(output, `${report.mode}.json`), JSON.stringify(report, null, 2))
  if (!baseline && !blurBaseline) {
    assert.ok(report.listeners.after.document <= report.listeners.before.document)
    assert.ok(report.listeners.after.window <= report.listeners.before.window)
    assert.deepEqual(report.errors, [])
    assert.deepEqual(report.consoleErrors, [])
  }
  assert.equal(await page.evaluate(() => window.__documentIdentity), documentId, 'Recovery must not need F5')
  await page.screenshot({ path: resolve(output, `${report.mode}.png`), fullPage: true })
  await context.tracing.stop({ path: resolve(output, `${report.mode}.zip`) })
  await writeFile(resolve(output, `${report.mode}.json`), JSON.stringify(report, null, 2))
  console.log(JSON.stringify({ mode: report.mode, cases: report.cases.map(item => ({ name: item.name, disabled: item.disabled, writes: item.writes, clickDelta: item.clickDelta, recovered: item.recovered })), listeners: report.listeners, errors: report.errors, consoleErrors: report.consoleErrors }))
  await context.close()
} finally {
  releaseHold?.()
  await browser?.close()
  await server.close()
  await api.close()
}
