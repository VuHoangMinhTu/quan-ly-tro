import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

// Isolated UI fixtures only. Persistence, snapshots and authorization are tested by PHPUnit.
const root = fileURLToPath(new URL('../../', import.meta.url))
const require = createRequire(resolve(root, 'front-end/package.json'))
const { createServer } = await import(pathToFileURL(require.resolve('vite')))
const { chromium } = createRequire(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH || 'C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json'))('playwright')
const origin = 'http://127.0.0.1:18943'
const output = resolve(root, 'tmp/residence-recovery')
const tenant = { id: 1, full_name: 'Người kê khai kiểm thử', date_of_birth: '2000-01-01', gender: 'MALE', identity_number: '000000000001', phone: '0900000001', permanent_address: 'Địa chỉ kiểm thử' }
const house = { id: 3, name: 'Nhà trọ kiểm thử', residence_profile: null }
const context = { room: { id: 11, boarding_house_id: 3, room_code: '101', boarding_house: house }, occupants: [{ tenant }], contracts: [], operational_role: 'OWNER', suggested_declaration: { request_content: 'Đăng ký tạm trú' } }
const ct01 = { declarant: tenant, household_head: { name: tenant.full_name, identity_number: tenant.identity_number }, household_head_mode: 'DECLARANT', relationship_to_household_head: 'Chủ hộ', authority_name: 'Cơ quan kiểm thử', request_content: 'Đăng ký tạm trú', members: [] }
const consent = { host: { full_name: 'Chủ sở hữu kiểm thử', birth_date: '1970-01-01', permanent_address: 'Địa chỉ Bên A kiểm thử', identity_number: '000000000002', phone: '0900000002' }, guest: { ...tenant, birth_year: '2000' }, document_place: 'Phường kiểm thử', location: 'Địa chỉ kiểm thử', captured_at: '2026-10-28', stay_address: 'Địa chỉ kiểm thử', room_code: '101', purpose: 'để ở', companions: [], start_date: '2026-10-01', end_date: '2027-10-01' }
let record = { id: 1, status: 'DRAFT', room_id: 11, boarding_house_id: 3, declarant_tenant_id: 1 }
const preview = () => ({ declaration: record, ct01, stay_consent: consent, missing_fields: [], stay_consent_missing_fields: {}, is_frozen: false, operational_role: 'OWNER' })
const server = await createServer({ root: resolve(root, 'front-end'), configFile: resolve(root, 'front-end/vite.config.js'), define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api') }, plugins: [{ name: 'residence-recovery-audit', configureServer(vite) {
  vite.middlewares.use('/residence-audit', async (_request, response) => {
    response.setHeader('Content-Type', 'text/html')
    response.end(await vite.transformIndexHtml('/residence-audit', '<html lang="vi"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/tests/residence-recovery-audit.jsx"></script></body></html>'))
  })
} }], server: { host: '127.0.0.1', port: 18943, strictPort: true } })
let browser
try {
  await mkdir(output, { recursive: true })
  await server.listen()
  browser = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
  const errors = [], mutations = []
  page.on('pageerror', error => errors.push(error.message))
  page.on('dialog', dialog => dialog.accept())
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url())
    assert.equal(url.origin, origin, 'No production or external requests')
    if (!url.pathname.startsWith('/api/')) return route.continue()
    const path = url.pathname.replace('/api', ''), method = request.method()
    const ok = data => route.fulfill({ json: { success: true, message: 'Thành công.', data } })
    if (method === 'GET' && path === '/residence/boarding-houses') return ok([{ ...house, operational_role: 'OWNER' }])
    if (method === 'GET' && path === '/residence/boarding-houses/3/rooms') return ok([{ id: 11, room_code: '101' }])
    if (method === 'GET' && path === '/residence/rooms/11/context') return ok(context)
    if (method === 'GET' && path === '/residence-declarations/1') return ok(preview())
    if (['POST', 'PUT'].includes(method)) {
      mutations.push({ method, path, payload: request.postDataJSON() })
      if (path.endsWith('/status')) return route.fulfill({ status: 422, json: { success: false, message: 'Chưa nhập cơ quan đăng ký cư trú.', errors: { authority_name: ['Chưa nhập cơ quan đăng ký cư trú.'] } } })
      record = { ...record, ...request.postDataJSON() }
      return ok(record)
    }
    throw new Error(`Unexpected API ${method} ${path}`)
  })
  const visit = route => page.goto(`${origin}/residence-audit?route=${encodeURIComponent(route)}`)
  await visit('/residence-declarations/create')
  await page.locator('#ct01-house').selectOption('3')
  await page.locator('#ct01-room').selectOption('11')
  await page.getByRole('status').getByText('Nhà trọ chưa có cấu hình cư trú.', { exact: false }).waitFor()
  assert.equal(await page.locator('[name="household_head_name"]').count(), 0)
  await page.locator('#ct01-declarant').selectOption('1')
  await page.getByRole('radio', { name: 'Một người khác', exact: true }).check()
  await page.locator('[name="household_head_name"]').fill('Chủ hộ kiểm thử')
  await page.getByRole('radio', { name: 'Người kê khai', exact: true }).check()
  await page.locator('[name="stay_start_date"]').fill('01/10/2026')
  await page.locator('[name="stay_end_date"]').fill('01/10/2027')
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
  await page.getByRole('button', { name: 'Lưu bản nháp', exact: true }).click()
  await page.getByRole('heading', { name: 'CT01 #1', exact: true }).waitFor()
  assert.equal(mutations.length, 1)
  assert.equal(mutations[0].payload.household_head_name, null)
  await visit('/residence-declarations/1/edit')
  assert.equal(await page.locator('[name="stay_start_date"]').inputValue(), '01/10/2026')
  assert.equal(await page.getByRole('radio', { name: 'Người kê khai', exact: true }).isChecked(), true)
  await page.getByRole('button', { name: 'Hoàn tất', exact: true }).click()
  await page.getByText('Đã lưu bản nháp nhưng chưa thể hoàn tất:', { exact: false }).waitFor()
  await page.locator('[aria-label="Thông báo"] [role="alert"]').getByText('Chưa nhập cơ quan đăng ký cư trú.', { exact: true }).waitFor()
  assert.equal(await page.locator('[aria-label="Thông báo"] [role="alert"]').count(), 1)
  for (const [route, selector, filename] of [
    ['/residence-declarations/1/print', '.ct01-document', 'ct01'],
    ['/residence-declarations/1/stay-consent/print', '.stay-consent-document', 'stay-consent'],
  ]) {
    await page.setViewportSize({ width: 1200, height: 1000 })
    await visit(route)
    await page.locator(selector).waitFor()
    await page.evaluate(() => document.fonts.ready)
    assert.doesNotMatch(await page.locator(selector).innerText(), /Chưa ký|undefined|null/)
    await page.emulateMedia({ media: 'print' })
    assert.equal(await page.locator(`${filename === 'ct01' ? '.ct01' : '.stay-consent'}-print-actions`).isVisible(), false)
    if (filename === 'ct01') {
      assert.equal(await page.locator('.ct01-signature').count(), 4)
      assert.doesNotMatch(await page.locator('.ct01-signatures').innerText(), /kiểm thử|00000000000/)
      assert.ok((await page.locator('.ct01-extra-space').allTextContents()).every(value => value === ''))
    }
    else assert.doesNotMatch(await page.locator('.stay-consent-signatures').innerText(), /kiểm thử/)
    await page.pdf({ path: resolve(output, `${filename}.pdf`), preferCSSPageSize: true, printBackground: true })
    await page.locator(selector).screenshot({ path: resolve(output, `${filename}.png`) })
    await page.emulateMedia({ media: 'screen' })
  }
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ result: 'passed', mobileFormOverflow: false, missingConfigurationNotice: true, draftReload: true, failedFinishSingleToast: true, blankSignatures: true, pdf: ['ct01.pdf', 'stay-consent.pdf'], mutations: mutations.map(({ method, path }) => ({ method, path })), errors }))
} finally {
  await browser?.close()
  await server.close()
}
