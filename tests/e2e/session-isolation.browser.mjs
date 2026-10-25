import assert from 'node:assert/strict'
import { before, after, test } from 'node:test'
import { createRequire } from 'node:module'
import { resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { accounts, startSyntheticServer } from './synthetic-session-server.mjs'

const require = createRequire(new URL('../../front-end/package.json', import.meta.url))
let playwright
try { playwright = require('playwright') } catch {
  if (!process.env.PLAYWRIGHT_PACKAGE_PATH) throw new Error('Provide a local Playwright package via PLAYWRIGHT_PACKAGE_PATH; no automatic install/download.')
  playwright = require(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH))
}
const root = fileURLToPath(new URL('../../', import.meta.url))
const dist = resolve(process.env.E2E_DIST || resolve(root, 'tmp/e2e-session-dist'))
assert.ok(dist.startsWith(resolve(root, 'tmp') + sep), 'E2E build must be under the project tmp directory.')
let browser
before(async () => {
  browser = await playwright.chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) })
  console.log(`Browser: Chromium ${browser.version()}; synthetic loopback API only`)
}, { timeout: 20000 })
after(async () => { await browser?.close() })

async function scenario(run) {
  const server = await startSyntheticServer(dist)
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' })
  const blocked = [], pageErrors = []
  await context.route('**/*', async route => {
    if (new URL(route.request().url()).origin === server.origin) await route.continue()
    else { blocked.push('external request blocked'); await route.abort('blockedbyclient') }
  })
  await context.addInitScript(() => {
    window.__e2eDocument = Math.random().toString(36)
    const inspect = records => {
      if (sessionStorage.getItem('__e2eGuard') !== 'B') return
      const leaked = /E2E_A_(PRIVATE|LATE_RESPONSE)/.test(document.body?.textContent || '') || records.some(record => [...record.addedNodes].some(node => /E2E_A_(PRIVATE|LATE_RESPONSE)/.test(node.textContent || '')))
      if (leaked) sessionStorage.setItem('__e2eLeakDetected', 'true')
    }
    new MutationObserver(inspect).observe(document, { childList: true, characterData: true, subtree: true })
  })
  const page = await context.newPage()
  page.setDefaultTimeout(8000)
  page.on('pageerror', error => pageErrors.push(error.message))
  try {
    await page.goto(`${server.origin}/login`)
    await page.evaluate(() => { localStorage.setItem('theme', 'synthetic-dark'); sessionStorage.setItem('display-setting', 'synthetic-compact') })
    await run({ page, server })
    assert.deepEqual(server.unexpected, [], 'Unexpected fixture endpoint')
    assert.deepEqual(blocked, [], 'Application tried to leave the local test origin')
    assert.deepEqual(pageErrors, [], 'Browser runtime error')
    assert.equal(context.pages().length, 1, 'Account switch must stay in the same tab')
  } finally { await context.close(); await server.close() }
}

async function login(page, letter) {
  const who = accounts[letter]
  await page.getByLabel('Email', { exact: true }).fill(who.user.email)
  await page.getByLabel('Mật khẩu', { exact: true }).fill(who.password)
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
  await page.waitForURL('**/dashboard')
  await page.getByText(`Xin chào, ${who.user.name}`, { exact: true }).waitFor()
}
async function deadline(promise) {
  let timer
  try {
    return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Synthetic response barrier timed out')), 8000) })])
  } finally { clearTimeout(timer) }
}
async function logout(page) {
  await page.getByRole('button', { name: 'Menu tài khoản', exact: true }).click()
  await page.getByRole('menuitem', { name: 'Đăng xuất', exact: true }).click()
  await page.waitForURL('**/login')
  assert.equal(await page.evaluate(() => localStorage.getItem('access_token')), null)
}
async function navigate(page, path) {
  // Preserve the JS document/cache when visiting an exact route (including foreign IDs).
  await page.evaluate(path => { history.pushState(null, '', path); dispatchEvent(new PopStateEvent('popstate')) }, path)
  await page.waitForURL(url => url.pathname === path)
}
async function rooms(page, letter) {
  await navigate(page, `/boarding-houses/${accounts[letter].house.id}/rooms`)
  await page.getByText(accounts[letter].room.room_code, { exact: true }).waitFor()
}
async function tenants(page, letter) {
  await page.getByRole('link', { name: 'Người thuê', exact: true }).click()
  await page.getByText(accounts[letter].tenant.full_name, { exact: true }).filter({ visible: true }).first().waitFor()
}
async function invoices(page, letter) {
  const who = accounts[letter]
  await page.getByRole('link', { name: 'Hóa đơn', exact: true }).click()
  await page.getByLabel('Chọn nhà trọ', { exact: true }).selectOption(String(who.house.id))
  await page.locator(`a[href="/rooms/${who.room.id}/invoices"]`).click()
  await page.getByText(who.invoice.invoice_code, { exact: true }).filter({ visible: true }).first().waitFor()
}
async function guardB(page) {
  await page.evaluate(() => { sessionStorage.setItem('__e2eGuard', 'B'); sessionStorage.removeItem('__e2eLeakDetected') })
}
async function assertB(page) {
  const state = await page.evaluate(() => ({ token: localStorage.getItem('access_token'), theme: localStorage.getItem('theme'), display: sessionStorage.getItem('display-setting'), leak: sessionStorage.getItem('__e2eLeakDetected'), text: document.body.textContent }))
  assert.equal(state.token, accounts.B.token)
  assert.equal(state.theme, 'synthetic-dark')
  assert.equal(state.display, 'synthetic-compact')
  assert.equal(state.leak, null, 'MutationObserver detected A data after account switch')
  assert.doesNotMatch(state.text, /E2E_A_(PRIVATE|LATE_RESPONSE)/)
}

test('same tab A → logout → B: rooms/invoices/tenants, pending B, old URLs, refresh and logged-out refresh', { timeout: 60000 }, async () => scenario(async ({ page, server }) => {
  const documentId = await page.evaluate(() => window.__e2eDocument)
  await login(page, 'A')
  await rooms(page, 'A')
  await invoices(page, 'A')
  await tenants(page, 'A')
  await logout(page)
  await guardB(page)
  await login(page, 'B')
  const pendingB = server.holdNext('B', '/tenants')
  await page.getByRole('link', { name: 'Người thuê', exact: true }).click()
  await deadline(pendingB.entered)
  await assertB(page)
  pendingB.release()
  await deadline(pendingB.settled)
  await page.getByText(accounts.B.tenant.full_name, { exact: true }).filter({ visible: true }).first().waitFor()
  await invoices(page, 'B')
  await rooms(page, 'B')
  await navigate(page, `/boarding-houses/${accounts.A.house.id}/rooms`)
  await page.getByText('Không tìm thấy dữ liệu test.', { exact: true }).waitFor()
  await assertB(page)
  await navigate(page, `/rooms/${accounts.A.room.id}/invoices`)
  await page.getByText('Không tìm thấy dữ liệu test.', { exact: true }).waitFor()
  await assertB(page)
  assert.equal(await page.evaluate(() => window.__e2eDocument), documentId, 'The account switch must not reload away stale cache')
  await rooms(page, 'B')
  const meBefore = server.requests.filter(item => item.path === '/me' && item.owner === 'B').length
  await page.reload()
  await page.getByText(accounts.B.room.room_code, { exact: true }).waitFor()
  assert.ok(server.requests.filter(item => item.path === '/me' && item.owner === 'B').length > meBefore)
  await tenants(page, 'B')
  await invoices(page, 'B')
  await assertB(page)
  await logout(page)
  await page.reload()
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).waitFor()
  assert.equal(await page.evaluate(() => localStorage.getItem('access_token')), null)
  assert.equal(await page.evaluate(() => sessionStorage.getItem('__e2eLeakDetected')), null)
}))

test('dashboard skips fresh remount requests, polls changed totals at 30 seconds, and never shares A cache with B', { timeout: 60000 }, async () => scenario(async ({ page, server }) => {
  await page.clock.install()
  await login(page, 'A')
  await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
  const count = letter => server.requests.filter(item => item.path === '/dashboard' && item.owner === letter).length
  assert.equal(count('A'), 1)
  await tenants(page, 'A')
  await page.getByRole('link', { name: 'Dashboard', exact: true }).click()
  await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
  await page.waitForTimeout(200)
  assert.equal(count('A'), 1)
  server.setDashboardRoomCount('A', 2)
  const polled = page.waitForResponse(response => new URL(response.url()).pathname === '/api/dashboard' && response.status() === 200)
  await page.clock.fastForward(30001)
  await polled
  await page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Phòng', exact: true }) }).getByText('2', { exact: true }).waitFor()
  assert.equal(count('A'), 2)
  await logout(page)
  await guardB(page)
  await login(page, 'B')
  await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
  assert.equal(count('B'), 1)
  await page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Phòng', exact: true }) }).getByText('1', { exact: true }).waitFor()
  await assertB(page)
}))

for (const mode of ['cancelled-200', 'delivered-200', 'delivered-401']) {
  test(`A request completes after B login: ${mode}`, { timeout: 60000 }, async () => scenario(async ({ page, server }) => {
    await login(page, 'A')
    await rooms(page, 'A')
    await invoices(page, 'A')
    await tenants(page, 'A')
    if (mode.startsWith('delivered')) {
      // Test-only fault injection: keep the old XHR physically alive after Axios cancels.
      await page.evaluate(() => {
        const open = XMLHttpRequest.prototype.open, abort = XMLHttpRequest.prototype.abort
        XMLHttpRequest.prototype.open = function (method, url, ...args) {
          this.__e2eLate = String(url).includes('/api/tenants') && String(url).includes('late-test')
          if (this.__e2eLate) this.addEventListener('load', () => { window.__e2eLateStatus = this.status })
          return open.call(this, method, url, ...args)
        }
        XMLHttpRequest.prototype.abort = function () { if (!this.__e2eLate) return abort.call(this) }
      })
    }
    const hold = server.holdNext('A', '/tenants', mode.endsWith('401') ? 401 : 200)
    await page.getByRole('searchbox', { name: 'Tìm người thuê', exact: true }).fill('late-test')
    await deadline(hold.entered)
    await logout(page)
    await guardB(page)
    await login(page, 'B')
    await tenants(page, 'B')
    await assertB(page)
    hold.release()
    await deadline(hold.settled)
    if (mode.startsWith('delivered')) await page.waitForFunction(status => window.__e2eLateStatus === status, mode.endsWith('401') ? 401 : 200)
    else assert.equal(hold.aborted, true, 'Real A request should be aborted on session switch')
    await assertB(page)
    await invoices(page, 'B')
    await rooms(page, 'B')
    await assertB(page)
  }))
}
