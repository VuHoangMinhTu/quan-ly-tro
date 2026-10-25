import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { accounts, startSyntheticServer } from './synthetic-session-server.mjs'

const require = createRequire(new URL('../../front-end/package.json', import.meta.url))
let playwright
try { playwright = require('playwright') } catch {
  if (!process.env.PLAYWRIGHT_PACKAGE_PATH) throw new Error('Provide an installed local Playwright package.')
  playwright = require(resolve(process.env.PLAYWRIGHT_PACKAGE_PATH))
}
const root = fileURLToPath(new URL('../../', import.meta.url))
const dist = resolve(process.env.E2E_DIST || resolve(root, 'tmp/e2e-session-dist'))
assert.ok(dist.startsWith(resolve(root, 'tmp') + sep))
const browser = await playwright.chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) })
const samples = []
try {
  for (let run = 0; run < 3; run++) {
    const server = await startSyntheticServer(dist, { apiDelayMs: 75 })
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' })
    const page = await context.newPage()
    page.setDefaultTimeout(8000)
    const errors = [], blocked = []
    page.on('pageerror', error => errors.push(error.message))
    await context.route('**/*', async route => {
      if (new URL(route.request().url()).origin === server.origin) await route.continue()
      else { blocked.push('external'); await route.abort('blockedbyclient') }
    })
    try {
      await page.goto(`${server.origin}/login`)
      await page.getByLabel('Email', { exact: true }).fill(accounts.A.user.email)
      await page.getByLabel('Mật khẩu', { exact: true }).fill(accounts.A.password)
      const started = performance.now()
      await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
      await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
      const coldReadyMs = performance.now() - started
      await page.waitForTimeout(200)
      const coldDashboardRequests = server.requests.filter(item => item.path === '/dashboard').length
      const coldMeRequests = server.requests.filter(item => item.path === '/me').length
      const warmReadyMs = []
      for (let revisit = 0; revisit < 3; revisit++) {
        await page.getByRole('link', { name: 'Người thuê', exact: true }).click()
        await page.getByRole('heading', { name: 'Người thuê', exact: true }).waitFor()
        await page.waitForTimeout(200)
        const at = performance.now()
        await page.getByRole('link', { name: 'Dashboard', exact: true }).click()
        await page.getByRole('heading', { name: 'Tổng quan vận hành', exact: true }).waitFor()
        warmReadyMs.push(performance.now() - at)
        await page.waitForTimeout(200)
      }
      const afterRevisits = server.requests.filter(item => item.path === '/dashboard').length
      for (let focus = 0; focus < 3; focus++) {
        // Synthetic visibility notification, delivered to the actual installed Query focus manager.
        await page.evaluate(() => window.dispatchEvent(new Event('visibilitychange')))
        await page.waitForTimeout(200)
      }
      const dashboardTimings = await page.evaluate(() => performance.getEntriesByType('resource').filter(entry => new URL(entry.name).pathname === '/api/dashboard').map(entry => ({ durationMs: entry.duration, ttfbMs: entry.responseStart - entry.requestStart })))
      samples.push({ run: run + 1, coldReadyMs: +coldReadyMs.toFixed(3), warmReadyMs: warmReadyMs.map(value => +value.toFixed(3)), coldDashboardRequests, coldMeRequests, revisitDashboardRequests: afterRevisits - coldDashboardRequests, focusDashboardRequests: server.requests.filter(item => item.path === '/dashboard').length - afterRevisits, totalDashboardRequests: dashboardTimings.length, dashboardTimings: dashboardTimings.map(item => ({ durationMs: +item.durationMs.toFixed(3), ttfbMs: +item.ttfbMs.toFixed(3) })) })
      assert.deepEqual(errors, [])
      assert.deepEqual(blocked, [])
      assert.deepEqual(server.unexpected, [])
    } finally { await context.close(); await server.close() }
  }
  console.log(JSON.stringify({ kind: 'dashboard-browser-synthetic-api', phase: process.env.DASHBOARD_METRICS || 'unspecified', browser: browser.version(), simulatedApiDelayMs: 75, visibilityEvents: 'synthetic', samples }))
} finally { await browser.close() }
