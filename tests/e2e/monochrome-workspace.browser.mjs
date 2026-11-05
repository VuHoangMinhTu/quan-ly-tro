import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { accounts, startSyntheticServer } from './synthetic-session-server.mjs'
import { workspaceFixture } from './monochrome-fixtures.mjs'
const root = fileURLToPath(new URL('../../', import.meta.url))
const { chromium } = createRequire('C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json')('playwright')
const output = resolve(root, 'tmp/monochrome-workspace')
await mkdir(output, { recursive: true })
const server = await startSyntheticServer(resolve(root, 'front-end/dist'))
const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
const results = []
try {
 for (const width of [360, 390, 768, 1024, 1440, 1920]) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' })
  let demoOnly = false
  await context.route('**/*', async route => {
   const request = route.request(), url = new URL(request.url())
   assert.equal(url.origin, server.origin, 'Block nonlocal traffic')
   const fixture = workspaceFixture(url, { demoOnly })
   if (fixture !== undefined && url.pathname.startsWith('/api/')) {
    assert.equal(request.method(), 'GET', 'Visual tests cannot write')
    return route.fulfill({ json: { success: true, data: fixture } })
   }
   return route.continue()
  })
  const page = await context.newPage(); page.setDefaultTimeout(12000)
  const errors = []; page.on('pageerror', error => errors.push(error.message))
  const shot = async name => {
   await page.evaluate(() => document.fonts.ready)
   await page.evaluate(() => { if (document.activeElement?.classList.contains('bh-skip')) document.activeElement.blur(); window.scrollTo(0, 0) })
   assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${name}:${width}`)
   await page.screenshot({ path: resolve(output, `${name}-${width}.png`), fullPage: true })
  }
  await page.goto(server.origin + '/register'); await page.getByRole('heading', { name: 'Đăng ký tài khoản', exact: true }).waitFor(); await shot('register')
  await page.goto(server.origin + '/login'); await page.getByLabel('Email', { exact: true }).fill(accounts.A.user.email); await page.getByLabel('Mật khẩu', { exact: true }).fill(accounts.A.password); await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click(); await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
  for (const [path, name] of [['/contract-templates', 'templates'], ['/contract-templates/31', 'template-detail'], ['/rooms/101/invoices', 'invoices'], ['/invoices/101', 'invoice-detail'], ['/boarding-houses/101/services', 'services'], ['/utility-meters/71', 'utilities'], ['/residence-declarations', 'residence'], ['/change-password', 'account']]) {
   await page.goto(server.origin + path); await page.locator('main h2').first().waitFor(); await page.waitForTimeout(180); await shot(name)
   if (name === 'invoice-detail') { await page.getByRole('button', { name: 'Ghi nhận tiền mặt', exact: true }).click(); await page.getByRole('dialog').waitFor(); await shot('payment-modal'); await page.getByRole('button', { name: 'Đóng', exact: true }).click() }
   if (name === 'services') { await page.getByRole('button', { name: 'Thêm dịch vụ', exact: true }).click(); await page.getByRole('dialog').waitFor(); await page.getByRole('button', { name: 'Lưu dịch vụ', exact: true }).click(); await page.getByText('Vui lòng nhập tên dịch vụ', { exact: true }).first().waitFor(); await shot('service-modal-error'); await page.getByRole('button', { name: 'Đóng', exact: true }).click() }
   if (name === 'utilities') { await page.getByRole('button', { name: 'Thêm chỉ số', exact: true }).click(); await page.getByRole('dialog').waitFor(); await shot('reading-modal'); await page.getByRole('button', { name: 'Đóng', exact: true }).click() }
  }
  await page.goto(server.origin + '/rooms/101/contracts/new'); await page.getByRole('heading', { name: 'Tạo hợp đồng mới', exact: true }).waitFor(); await page.locator('.bh-stepper').waitFor(); await shot('wizard-1')
  await page.getByRole('button', { name: 'Người đứng tên', exact: true }).click(); const picker = page.getByRole('dialog', { name: 'Chọn người thuê', exact: true }); await picker.getByRole('radio').first().check(); await shot('tenant-picker'); await picker.getByRole('button', { name: 'Xác nhận chọn', exact: true }).click()
  await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click(); await page.getByRole('heading', { name: 'Người ở & dịch vụ', exact: true }).waitFor(); await shot('wizard-2')
  await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click(); await page.getByRole('heading', { name: 'Đại diện & lịch thanh toán', exact: true }).waitFor(); await shot('wizard-3')
  await page.getByLabel('Ngày thanh toán hàng tháng', { exact: true }).fill('32'); await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click(); await page.locator('.contract-wizard [role=alert]').waitFor(); await shot('wizard-error'); await page.getByLabel('Ngày thanh toán hàng tháng', { exact: true }).fill('5'); await page.getByRole('button', { name: 'Tiếp tục', exact: true }).click(); await page.getByRole('heading', { name: 'Kiểm tra và lưu', exact: true }).waitFor(); await shot('wizard-4')
  demoOnly = true; await page.goto(server.origin + '/rooms/101/contracts/new'); await page.locator('.bh-stepper').waitFor(); await page.getByText('HỢP ĐỒNG DEMO', { exact: true }).waitFor();
  const demoOptions = await page.locator('#contract-template option').evaluateAll(options => options.filter(option => option.value).map(option => option.textContent))
  assert.ok(demoOptions.length > 0 && demoOptions.every(label => /DEMO/.test(label)), 'Demo-only account receives only demo choices')
  await shot('wizard-demo')
  assert.deepEqual(errors, []); results.push({ width, passed: true }); await context.close()
 }
 await writeFile(resolve(output, 'results.json'), JSON.stringify(results, null, 2)); console.log(JSON.stringify(results))
} finally { await browser.close(); await server.close() }
