import { createServer } from 'node:http'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve, sep, extname } from 'node:path'

const owner = letter => {
  const id = letter === 'A' ? 101 : 201
  const prefix = `E2E_${letter}_PRIVATE`
  return {
    letter, prefix, token: `e2e-synthetic-token-${letter}`, password: 'synthetic-password-only',
    user: { id, name: `${prefix}_OWNER`, email: `landlord-${letter.toLowerCase()}@example.invalid` },
    landlord: { id, user_id: id },
    house: { id, landlord_id: id, name: `${prefix}_HOUSE`, address: 'Synthetic test address' },
    room: { id, boarding_house_id: id, room_code: `${prefix}_ROOM`, room_name: 'Synthetic room', status: 'AVAILABLE', room_type: 'PRIVATE_ROOM', monthly_rent: '1000000.00', area: '20.00', max_tenants: 2, amenities: [] },
    tenant: { id, landlord_id: id, full_name: `${prefix}_TENANT`, current_residence: null },
    invoice: { id, room_id: id, invoice_code: `${prefix}_INVOICE`, billing_period: '2026-10-01', total_amount: '1000000.00', paid_amount: '0.00', status: 'UNPAID' },
  }
}
export const accounts = { A: owner('A'), B: owner('B') }

function dashboard(who) {
  return {
    viewer: { name: who.user.name, can_create_boarding_house: true, owned_boarding_houses: 1, managed_boarding_houses: 0 },
    period: { label: '10/2026' },
    summary: { total_boarding_houses: 1, total_rooms: 1, occupied_rooms: 0, available_rooms: 1, active_tenants: 1, active_contracts: 0, expiring_soon_contracts: 0, expiring_window_days: 30, occupancy_rate: '0.00', occupancy_status_mismatches: 0 },
    invoice_summary: { invoice_total: '1000000.00', invoice_count: 1, paid_amount: '0.00', outstanding_amount: '1000000.00', overdue_amount: '0.00', overdue_invoice_count: 0, unpaid_invoice_count: 1, statuses: { UNPAID: 1, PAID: 0 } },
    boarding_houses: [{ ...who.house, operational_role: 'OWNER', total_rooms: 1, occupied_rooms: 0, available_rooms: 1, occupancy_rate: '0.00', outstanding_amount: '1000000.00' }],
    revenue_chart: [{ label: '10/2026', month: '2026-10', amount: '0.00' }],
    recent_unpaid_invoices: [], expiring_contracts: [],
  }
}

// Standalone disposable API double: never imports Laravel, env files or a database.
export async function startSyntheticServer(dist, { apiDelayMs = 0 } = {}) {
  const root = resolve(dist)
  if (!existsSync(resolve(root, 'index.html'))) throw new Error('Build the isolated E2E frontend first.')
  const active = new Set()
  const requests = []
  const unexpected = []
  const holds = []
  const dashboardRoomCounts = new Map()
  const json = (res, status, data, message = 'Synthetic response') => {
    if (res.destroyed) return
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' })
    res.end(JSON.stringify(status === 200 ? { success: true, data, message } : { success: false, message }))
  }
  const server = createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1')
      if (!url.pathname.startsWith('/api/')) {
        if (req.method !== 'GET') return json(res, 405, null)
        const candidate = resolve(root, '.' + decodeURIComponent(url.pathname))
        if (candidate !== root && !candidate.startsWith(root + sep)) return json(res, 403, null)
        const file = existsSync(candidate) && statSync(candidate).isFile() ? candidate : resolve(root, 'index.html')
        const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.svg': 'image/svg+xml' }[extname(file)] || 'application/octet-stream'
        res.writeHead(200, { 'Content-Type': mime, 'Cache-Control': 'no-store' })
        return res.end(readFileSync(file))
      }
      const path = url.pathname.slice(4)
      const who = Object.values(accounts).find(account => req.headers.authorization === `Bearer ${account.token}` && active.has(account.token))
      const metric = { method: req.method, path, owner: who?.letter ?? 'anonymous' }
      requests.push(metric)
      const started = performance.now()
      res.on('finish', () => { metric.status = res.statusCode; metric.durationMs = performance.now() - started })
      if (apiDelayMs) await new Promise(resolve => setTimeout(resolve, apiDelayMs))
      if (req.method === 'POST' && path === '/login') {
        let raw = ''
        for await (const chunk of req) { raw += chunk; if (raw.length > 2048) return json(res, 413, null) }
        const payload = JSON.parse(raw)
        const account = Object.values(accounts).find(item => item.user.email === payload.email && item.password === payload.password)
        if (!account) return json(res, 401, null, 'Thông tin đăng nhập test không hợp lệ.')
        active.add(account.token)
        return json(res, 200, { token: account.token, user: account.user, landlord: account.landlord })
      }
      if (!who) return json(res, 401, null, 'Phiên đăng nhập test đã hết hạn.')
      if (req.method === 'POST' && path === '/logout') { active.delete(who.token); return json(res, 200, null) }
      if (req.method !== 'GET') { unexpected.push({ method: req.method, path }); return json(res, 405, null) }
      const hold = holds.find(item => !item.used && item.owner === who.letter && item.path === path)
      if (hold) {
        hold.used = true
        res.on('close', () => { if (!res.writableEnded) hold.aborted = true })
        hold.enter()
        await hold.wait
        json(res, hold.status, [{ ...who.tenant, full_name: who.letter === 'A' ? 'E2E_A_LATE_RESPONSE' : who.tenant.full_name }], 'Synthetic delayed response')
        hold.finish()
        return
      }
      if (path === '/me') return json(res, 200, { user: who.user, landlord: who.landlord })
      if (path === '/dashboard') {
        const data = dashboard(who)
        if (dashboardRoomCounts.has(who.letter)) {
          data.summary.total_rooms = dashboardRoomCounts.get(who.letter)
          data.summary.available_rooms = data.summary.total_rooms
        }
        return json(res, 200, data)
      }
      if (path === '/boarding-houses') return json(res, 200, [who.house])
      if (path === '/tenants') return json(res, 200, [who.tenant])
      if (path === `/boarding-houses/${who.house.id}`) return json(res, 200, who.house)
      if (path === `/boarding-houses/${who.house.id}/rooms`) return json(res, 200, [who.room])
      if (path === `/rooms/${who.room.id}/invoices`) return json(res, 200, [who.invoice])
      if (/^\/(boarding-houses|rooms|invoices|tenants)\/\d+(\/rooms|\/invoices)?$/.test(path)) return json(res, 404, null, 'Không tìm thấy dữ liệu test.')
      unexpected.push({ method: req.method, path })
      return json(res, 404, null, 'Endpoint chưa được định nghĩa trong fixture E2E.')
    } catch {
      unexpected.push({ method: req.method, path: 'synthetic-handler-error' })
      json(res, 500, null, 'Synthetic fixture failed')
    }
  })
  await new Promise((yes, no) => { server.once('error', no); server.listen(0, '127.0.0.1', yes) })
  return {
    origin: `http://127.0.0.1:${server.address().port}`, requests, unexpected,
    setDashboardRoomCount(letter, count) { dashboardRoomCounts.set(letter, count) },
    holdNext(owner, path, status = 200) {
      let release, enter, finish
      const item = { owner, path, status, used: false, aborted: false,
        wait: new Promise(resolve => { release = resolve }),
        entered: new Promise(resolve => { enter = resolve }),
        settled: new Promise(resolve => { finish = resolve }),
      }
      Object.assign(item, { release, enter, finish })
      holds.push(item)
      return item
    },
    async close() {
      for (const hold of holds) hold.release()
      server.closeAllConnections()
      await new Promise(resolve => server.close(resolve))
    },
  }
}
