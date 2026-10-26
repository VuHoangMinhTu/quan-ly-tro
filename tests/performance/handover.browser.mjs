import assert from 'node:assert/strict'
import { mkdtemp, readFile, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { createRequire } from 'node:module'
import { pathToFileURL, fileURLToPath } from 'node:url'
import { spawn, execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'

const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const php = process.env.HANDOVER_AUDIT_PHP || 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
const directory = await mkdtemp(resolve(tmpdir(), 'beehouse-handover-'))
const environment = { ...process.env, BEEHOUSE_AUDIT_DB: resolve(directory, 'audit.sqlite'), BEEHOUSE_AUDIT_KEY: randomBytes(24).toString('hex') }
const fixture = JSON.parse(execFileSync(php, [resolve(root, 'tests/performance/handover-fixture.php')], { env: environment, encoding: 'utf8' }))
const port = 18937
const backend = spawn(php, ['-S', `127.0.0.1:${port}`, resolve(root, 'tests/performance/handover-fixture.php')], { env: environment, stdio: 'ignore', windowsHide: true })
let vite, browser
const samples = []
const initialRenders = []
const stat = (values) => { const sorted = [...values].sort((a, b) => a - b); return { median: +sorted[Math.floor(sorted.length / 2)].toFixed(2), p95: +sorted[Math.ceil(sorted.length * 0.95) - 1].toFixed(2) } }
const reset = async (count, confirmed = false) => {
  const response = await fetch(`http://127.0.0.1:${port}/__audit/reset`, { method: 'POST', headers: { 'X-Audit-Key': environment.BEEHOUSE_AUDIT_KEY, 'Content-Type': 'application/json' }, body: JSON.stringify({ count, confirmed }) })
  assert.equal(response.status, 200)
}
try {
  vite = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'), plugins: [{ name: 'audit-entry', configureServer(server) {
    server.middlewares.use('/audit', async (request, response) => {
      response.setHeader('Content-Type', 'text/html')
      response.end(await server.transformIndexHtml('/audit', '<html><body><div id="root"></div><script type="module" src="/tests/handover-audit.jsx"></script></body></html>'))
    })
  } }, { name: 'audit-render-counters', enforce: 'pre', transform(source, id) {
    for (const [file, fn, counter] of [['ContractDetailPage.jsx', 'ContractDetailPage()', 'detailRenders'], ['ContractHandoverSection.jsx', 'ContractHandoverSection({ contract })', 'handoverRenders'], ['ToastProvider.jsx', 'ToastProvider({ children })', 'toastRenders']]) {
      if (id.endsWith('/' + file)) return source.replace(`function ${fn} {`, `function ${fn} { window.__audit && window.__audit.${counter}++;`)
    }
  } }], server: { host: '127.0.0.1', port: 18938, strictPort: true, proxy: { '/api': { target: `http://127.0.0.1:${port}`, configure(proxy) { proxy.on('proxyReq', (request) => request.setHeader('X-Audit-Key', environment.BEEHOUSE_AUDIT_KEY)) } } }, fs: { allow: [root] } }, define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') } })
  await vite.listen()
  browser = await chromium.launch({ headless: true, executablePath: process.env.HANDOVER_AUDIT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' })
  const context = await browser.newContext({ viewport: { width: 1365, height: 900 }, serviceWorkers: 'block' })
  await context.route('**/*', (route) => new URL(route.request().url()).origin === 'http://127.0.0.1:18938' ? route.continue() : route.abort())
  await context.addInitScript((token) => localStorage.setItem('access_token', token), fixture.token)
  const page = await context.newPage()
  const failures = []
  page.on('pageerror', (error) => failures.push(error.message))
  page.on('dialog', (dialog) => dialog.accept())
  const responses = []
  const apiRequests = []
  page.on('request', (request) => { if (new URL(request.url()).pathname.startsWith('/api/')) apiRequests.push({ method: request.method(), path: new URL(request.url()).pathname }) })
  page.on('response', async (response) => {
    if (!new URL(response.url()).pathname.startsWith('/api/')) return
    const request = response.request(), timing = request.timing()
    responses.push({ url: new URL(response.url()).pathname, method: request.method(), status: response.status(), apiMs: timing.responseStart - timing.requestStart, kernelMs: Number(response.headers()['x-audit-kernel-ms']), queries: Number(response.headers()['x-audit-queries']), selects: Number(response.headers()['x-audit-selects']), updates: Number(response.headers()['x-audit-updates']), transactions: Number(response.headers()['x-audit-transactions']), bytes: Buffer.byteLength(request.postData() || '') })
  })
  const load = async (count) => {
    await reset(count)
    await page.goto('http://127.0.0.1:18938/audit')
    await page.getByRole('button', { name: 'Lưu nháp', exact: true }).waitFor({ timeout: 10000 }).catch(async (error) => {
      console.log(JSON.stringify({ diagnostic: (await page.locator('body').innerText()).slice(0, 1800), failures, responses }))
      throw error
    })
    await page.waitForLoadState('networkidle')
    initialRenders.push({ count, commits: await page.evaluate(() => window.__audit.commits.map((entry) => entry.actualDuration)) })
    await page.evaluate(() => { window.__audit.clear(); window.__audit.commits = []; window.__audit.starts = []; window.__audit.visible = []; window.__audit.shifts = [] })
    responses.length = 0
    apiRequests.length = 0
  }
  for (const count of process.env.HANDOVER_AUDIT_INTERACTIONS_ONLY ? [] : [13, 20, 50]) {
    for (const scenario of count === 20 ? ['save', 'invalid', 'confirm', 'confirm-error'] : ['save']) {
      for (let run = 0; run < 21; run++) {
        await load(count)
        if (scenario === 'invalid') await page.locator('#contract-handover input[name^="items."]').last().fill('')
        if (scenario === 'confirm-error') await page.route('**/api/contracts/*/handover-check-in/confirm', async (route) => { await reset(count, true); await route.continue() })
        const before = await page.evaluate(() => ({ detail: window.__audit.detailRenders, parent: window.__audit.parentRenders }))
        const navigation = []
        const navigationListener = () => navigation.push(true)
        page.on('framenavigated', navigationListener)
        await page.getByRole('button', { name: scenario.startsWith('confirm') ? 'Xác nhận bàn giao' : 'Lưu nháp', exact: true }).click()
        await page.waitForFunction(() => window.__audit.visible.length > 0)
        await page.waitForFunction(() => window.__audit.client.isMutating() === 0)
        await page.waitForLoadState('networkidle')
        const observed = await page.evaluate((before) => ({ visibleMs: window.__audit.visible[0].time - window.__audit.starts[0], parentRenders: window.__audit.parentRenders - before.parent, detailRenders: window.__audit.detailRenders - before.detail, commits: window.__audit.commits.map((entry) => entry.actualDuration), shifts: window.__audit.shifts, toastCount: window.__audit.snapshot().length }), before)
        page.off('framenavigated', navigationListener)
        const writes = responses.filter((response) => response.method !== 'GET')
        assert.equal(writes.length, scenario === 'confirm' || scenario === 'confirm-error' ? 2 : 1)
        assert.equal(observed.toastCount, 1)
        assert.equal(navigation.length, 0)
        assert.equal(writes.at(-1).status, scenario === 'invalid' || scenario === 'confirm-error' ? 422 : 200)
        if (run > 0) samples.push({ count, scenario, writes, refetches: apiRequests.filter((request) => request.method === 'GET').length, ...observed })
        if (scenario === 'confirm-error') await page.unroute('**/api/contracts/*/handover-check-in/confirm')
      }
    }
  }
  const interactions = {}
  for (const count of [13, 20, 50]) {
    await load(count)
    const before = await page.evaluate(() => performance.now())
    await page.getByRole('button', { name: 'Thêm hạng mục', exact: true }).click()
    const editableName = page.locator('#contract-handover input[name^="items."]').last()
    await editableName.waitFor()
    const addMs = await page.evaluate((start) => performance.now() - start, before)
    await editableName.pressSequentially('Synthetic name', { delay: 5 })
    const entered = await editableName.inputValue()
    assert.equal(entered, 'Synthetic name', 'Editing an item name must preserve focus across every character')
    const removeStart = await page.evaluate(() => performance.now())
    await page.locator('#contract-handover').getByRole('button', { name: 'Xóa', exact: true }).last().click()
    const removeMs = await page.evaluate((start) => performance.now() - start, removeStart)
    interactions[`addRemove${count}`] = { addMs: +addMs.toFixed(2), removeMs: +removeMs.toFixed(2), typedName: entered, expectedName: 'Synthetic name', requests: responses.length }
  }
  await load(20)
  await page.evaluate(() => {
    const save = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Lưu nháp')
    save.click(); save.click(); save.click()
  })
  await page.waitForFunction(() => window.__audit.visible.length > 0)
  await page.waitForTimeout(700)
  assert.equal(responses.filter((response) => response.method !== 'GET').length, 1)
  interactions.rapidSaveClicks = { clicks: 3, writes: 1, toasts: await page.evaluate(() => window.__audit.snapshot().length) }

  await load(20)
  await page.evaluate(() => {
    const confirm = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Xác nhận bàn giao')
    confirm.click(); confirm.click(); confirm.click()
  })
  await page.waitForFunction(() => window.__audit.visible.length > 0)
  await page.waitForFunction(() => window.__audit.client.isMutating() === 0)
  await page.waitForLoadState('networkidle')
  assert.equal(responses.filter((response) => response.method === 'PUT').length, 1)
  assert.equal(responses.filter((response) => response.method === 'POST').length, 1)
  assert.equal(await page.evaluate(() => window.__audit.snapshot().length), 1)
  interactions.rapidConfirmClicks = { clicks: 3, puts: 1, posts: 1, toasts: 1 }

  await load(20)
  await page.locator('#contract-handover input[name^="items."]').last().fill('')
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click()
  await page.waitForFunction(() => window.__audit.visible.length > 0)
  await page.waitForTimeout(700)
  assert.equal(responses.filter((response) => response.method !== 'GET').length, 1)
  interactions.validationRetries = 0

  await load(13)
  await page.locator('#contract-handover').getByLabel('Ghi chú', { exact: true }).first().fill('Synthetic persisted note')
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click()
  await page.waitForFunction(() => window.__audit.visible.length > 0)
  await page.reload()
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).waitFor()
  assert.equal(await page.locator('#contract-handover').getByLabel('Ghi chú', { exact: true }).first().inputValue(), 'Synthetic persisted note')
  interactions.reloadKeepsDraft = true

  await load(50)
  const note = page.locator('#contract-handover').getByLabel('Ghi chú', { exact: true }).first()
  await note.fill('')
  await page.evaluate(() => {
    window.__audit.inputFrames = []
    document.querySelector('#contract-handover').addEventListener('input', () => {
      const start = performance.now()
      requestAnimationFrame(() => window.__audit.inputFrames.push(performance.now() - start))
    })
  })
  await note.pressSequentially('synthetic-input-123456789', { delay: 5 })
  await page.waitForTimeout(100)
  interactions.input50RowsMs = stat(await page.evaluate(() => window.__audit.inputFrames))
  assert.equal(responses.filter((response) => response.method !== 'GET').length, 0)

  await page.evaluate(() => { window.__audit.clear(); window.__audit.commits = []; window.__audit.shifts = [] })
  const beforeToast = await page.evaluate(() => ({ parent: window.__audit.parentRenders, detail: window.__audit.detailRenders, handover: window.__audit.handoverRenders }))
  responses.length = 0
  await page.evaluate(() => window.__audit.toast.success('Synthetic toast overhead'))
  await page.getByText('Synthetic toast overhead', { exact: true }).waitFor()
  await page.waitForTimeout(50)
  interactions.toast = await page.evaluate((before) => ({ parentRenders: window.__audit.parentRenders - before.parent, detailRenders: window.__audit.detailRenders - before.detail, handoverRenders: window.__audit.handoverRenders - before.handover, commitMs: window.__audit.commits.map((entry) => entry.actualDuration), layoutShift: window.__audit.shifts.reduce((sum, entry) => sum + entry.value, 0) }), beforeToast)
  assert.equal(responses.length, 0)
  assert.equal(interactions.toast.parentRenders, 0)
  assert.equal(interactions.toast.detailRenders, 0)
  assert.equal(interactions.toast.handoverRenders, 0)
  assert.equal(interactions.toast.layoutShift, 0)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.evaluate(() => {
    window.__audit.clear()
    const save = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Lưu nháp')
    save.scrollIntoView({ block: 'end' })
    window.__audit.toast.error('Synthetic mobile toast')
  })
  await page.getByText('Synthetic mobile toast', { exact: true }).waitFor()
  interactions.mobile = await page.evaluate(() => {
    const toast = document.querySelector('[aria-label="Thông báo"]').getBoundingClientRect()
    const save = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Lưu nháp').getBoundingClientRect()
    return { scrollY, toastTop: toast.top, toastBottom: toast.bottom, saveTop: save.top, overflow: document.documentElement.scrollWidth > innerWidth }
  })
  assert.ok(interactions.mobile.scrollY > 1000)
  assert.ok(interactions.mobile.toastBottom < interactions.mobile.saveTop)
  assert.equal(interactions.mobile.overflow, false)
  await page.getByRole('button', { name: 'Đóng thông báo', exact: true }).click()
  await page.clock.install()
  await page.evaluate(() => window.__audit.toast.success('Synthetic timer success'))
  await page.clock.fastForward(4100)
  assert.equal(await page.evaluate(() => window.__audit.snapshot().length), 0)
  await page.evaluate(() => window.__audit.toast.error('Synthetic timer error'))
  await page.clock.fastForward(7100)
  assert.equal(await page.evaluate(() => window.__audit.snapshot().length), 0)
  interactions.toastTimers = 'success 4s / error 7s; browser clock verified'
  await page.clock.resume()

  const cdp = await context.newCDPSession(page)
  await cdp.send('HeapProfiler.collectGarbage')
  const heapBefore = (await cdp.send('Runtime.getHeapUsage')).usedSize
  await page.evaluate(() => { for (let index = 0; index < 500; index++) { window.__audit.toast.success(`Synthetic lifecycle ${index}`); window.__audit.clear() } })
  await page.waitForTimeout(50)
  await cdp.send('HeapProfiler.collectGarbage')
  interactions.toastHeapDelta500 = (await cdp.send('Runtime.getHeapUsage')).usedSize - heapBefore
  assert.equal(await page.evaluate(() => window.__audit.snapshot().length), 0)
  assert.deepEqual(failures, [])
  const summary = []
  for (const count of [13, 20, 50]) for (const scenario of ['save', 'invalid', 'confirm', 'confirm-error']) {
    const cases = samples.filter((sample) => sample.count === count && sample.scenario === scenario)
    if (!cases.length) continue
    summary.push({ count, scenario, samples: cases.length, visibleMs: stat(cases.map((sample) => sample.visibleMs)), apiMs: stat(cases.map((sample) => sample.writes.reduce((sum, write) => sum + write.apiMs, 0))), kernelMs: stat(cases.map((sample) => sample.writes.reduce((sum, write) => sum + write.kernelMs, 0))), writes: cases[0].writes.length, queries: cases[0].writes.map((write) => write.queries), selects: cases[0].writes.map((write) => write.selects), updates: cases[0].writes.map((write) => write.updates), transactions: cases[0].writes.map((write) => write.transactions), payloadBytes: cases[0].writes.reduce((sum, write) => sum + write.bytes, 0), refetches: [...new Set(cases.map((sample) => sample.refetches))], parentRenders: [...new Set(cases.map((sample) => sample.parentRenders))], detailRenders: [...new Set(cases.map((sample) => sample.detailRenders))], reactCommitMs: stat(cases.flatMap((sample) => sample.commits)) })
  }
  const previous = process.env.HANDOVER_AUDIT_INTERACTIONS_ONLY ? JSON.parse(await readFile(resolve(root, 'tests/performance/handover-current-metrics.json'), 'utf8')) : null
  const output = { environment: 'Chromium + Vite development React Profiler + Laravel HTTP kernel + disposable SQLite; 1 warmup / 20 samples; no Dashboard requests', baseline: 'Không có baseline trước sửa Toast. Trước/sau sửa key hạng mục: handover-before-input-metrics.json và kết quả hiện tại.', summary: previous?.summary || summary, interactions, initialRenders }
  await writeFile(resolve(root, 'tests/performance/handover-current-metrics.json'), JSON.stringify(output, null, 2))
  console.log(JSON.stringify(output))
  await context.close()
} finally {
  await browser?.close()
  await vite?.close()
  backend.kill()
}
