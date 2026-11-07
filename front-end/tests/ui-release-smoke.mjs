import assert from 'node:assert/strict'
import { chromium } from 'playwright'

// Supply a short-lived token and owned resource routes through the environment.
// This smoke check navigates and reads UI; it does not submit business forms.
const origin = process.env.BEEHOUSE_QA_ORIGIN || 'http://localhost:5173'
const privateRoutes = JSON.parse(process.env.BEEHOUSE_QA_ROUTES || '[]')
const token = process.env.BEEHOUSE_QA_TOKEN
const widths = (process.env.BEEHOUSE_QA_WIDTHS || '1440,768,375').split(',').map(Number)
const publicRoutes = [
  '/', '/login', '/register', '/forgot-password', '/reset-password',
  '/reset-password?token=synthetic&email=qa%40example.invalid',
  '/verify-email-sent', '/email-verified?status=success', '/email-verified',
  '/auth/google/callback',
]
const browser = await chromium.launch({ channel: 'msedge' })
const failures = []
let count = 0

try {
  for (const width of widths) {
    for (const authenticated of [false, true]) {
      if (authenticated && !token) continue
      const context = await browser.newContext({ viewport: { width, height: 1000 } })
      if (authenticated) {
        await context.addInitScript(value => localStorage.setItem('access_token', value), token)
      }
      const page = await context.newPage()
      let routeErrors = []
      page.on('pageerror', error => routeErrors.push(error.name))
      page.on('response', response => {
        if (response.status() === 401 && authenticated) {
          routeErrors.push('Authenticated HTTP 401 ' + new URL(response.url()).pathname)
        }
        if (response.status() >= 500) routeErrors.push('HTTP ' + response.status())
        if (response.status() === 404 && /\.(js|css|woff2?)(\?|$)/.test(response.url())) {
          routeErrors.push('Missing asset')
        }
      })
      for (const route of authenticated ? privateRoutes : publicRoutes) {
        console.log('Checking ' + width + 'px ' + route)
        routeErrors = []
        await page.goto(origin + route)
        await page.waitForLoadState('networkidle')
        await page.evaluate(() => document.fonts.ready.then(() => true))
        const layout = await page.evaluate(() => ({
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
          content: document.querySelector('main')?.textContent.trim().length || 0,
          heading: document.querySelector('main h1, main h2, main [role="alert"]') !== null,
        }))
        const documentView = /\/print|\/document|\/demos\//.test(route)
        if (layout.overflow && !documentView) routeErrors.push('Horizontal overflow')
        if (!layout.content) routeErrors.push('Empty main content')
        if (authenticated && page.url().includes('/login')) routeErrors.push('Unexpected login redirect')
        if (routeErrors.length) failures.push({ width, route, errors: [...new Set(routeErrors)] })
        if (['/', '/login', '/dashboard'].includes(route)) {
          const name = route === '/' ? 'landing' : route.slice(1)
          await page.screenshot({ path: 'tests/screenshots/release-' + name + '-' + width + '.png', fullPage: true })
        }
        count++
      }
      if (authenticated && privateRoutes.includes('/dashboard')) {
        await page.goto(origin + '/dashboard')
        if (width < 1024) {
          await page.getByRole('button', { name: 'Mở menu', exact: true }).click()
          const dialog = page.getByRole('dialog', { name: 'Menu BeeHouse' })
          assert.equal(await dialog.isVisible(), true)
          await dialog.getByRole('link', { name: 'Người thuê', exact: true }).click()
          assert.equal(await dialog.isVisible(), false)
        }
        await page.getByRole('button', { name: 'Menu tài khoản', exact: true }).click()
        await page.getByRole('menuitem', { name: 'Đổi mật khẩu', exact: true }).click()
        await page.getByRole('heading', { name: 'Đổi mật khẩu', exact: true }).waitFor()
      }
      if (authenticated && privateRoutes.includes('/rooms/136')) {
        await page.goto(origin + '/rooms/136')
        await page.getByRole('button', { name: /^(Thêm|Chỉnh sửa) tiện nghi$/ }).click()
        const amenities = page.getByRole('dialog', { name: 'Tiện nghi phòng', exact: true })
        await amenities.waitFor()
        assert.equal(await amenities.getByRole('button', { name: 'Lưu tiện nghi', exact: true }).isVisible(), true)
        await amenities.getByRole('button', { name: 'Hủy', exact: true }).click()
        await page.getByRole('button', { name: 'Thêm hợp đồng', exact: true }).click()
        const contracts = page.getByRole('dialog', { name: 'Chọn hợp đồng', exact: true })
        await contracts.waitFor()
        await contracts.getByRole('button', { name: 'Đóng', exact: true }).click()
      }
      await context.close()
    }
  }
  console.log(JSON.stringify({ views: count, failures }, null, 2))
  if (failures.length) process.exitCode = 1
} finally {
  await browser.close()
}
