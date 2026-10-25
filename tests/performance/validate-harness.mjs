// Offline safety/contract checks only. No k6 runtime, network or database calls.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const dir = new URL('./', import.meta.url)
const fixture = JSON.parse(readFileSync(new URL('fixtures.example.json', dir), 'utf8'))
const source = readFileSync(new URL('read-only.k6.js', dir), 'utf8')
  .replace(/^import .*$/gm, '')
  .replace(/export default function \(\)/g, 'function runIteration()')
  .replace(/export (const|function) /g, '$1 ')

function harness(overrides = {}, mode = 'normal') {
  const calls = []
  const metrics = new Map()
  const execution = {
    instance: { vusActive: 1 },
    vu: { idInTest: 1, iterationInScenario: 0 },
    test: { abort: reason => { throw new Error(reason) } },
  }
  class Metric {
    constructor(name) { this.name = name }
    add(value) { metrics.set(this.name, [...metrics.get(this.name) || [], value]) }
  }
  const context = vm.createContext({
    __ENV: { PERF_DATASET_CONFIRMED: 'synthetic-test-only', PERF_TOKEN_A: 'offline-a', PERF_TOKEN_B: 'offline-b', ...overrides },
    open: () => JSON.stringify(fixture),
    Rate: Metric, Trend: Metric, Counter: Metric, exec: execution,
    check: (value, predicates) => Object.values(predicates).every(predicate => predicate(value)),
    sleep: () => {},
    http: {
      expectedStatuses: (...codes) => codes,
      get(url, params) {
        calls.push({ url, params })
        const tenant = params.headers.Authorization.endsWith('offline-a') ? fixture.tenants[0] : fixture.tenants[1]
        const other = tenant === fixture.tenants[0] ? fixture.tenants[1] : fixture.tenants[0]
        const name = params.tags.endpoint
        let status = 200, data
        if (name === 'foreign_resource') status = mode === 'leak' ? 200 : 404
        else if (name === 'identity') data = { user: { id: tenant.userId }, landlord: { id: tenant.landlordId } }
        else if (name === 'dashboard') data = {
          summary: tenant.dashboard,
          boarding_houses: tenant.houseIds.map(id => ({ id })),
          recent_unpaid_invoices: [], expiring_contracts: [],
        }
        else {
          const key = { houses: 'houseIds', rooms: 'roomIds', tenants: 'tenantIds', tenant_search: 'tenantIds', contracts: 'contractIds', services: 'serviceIds', meters: 'meterIds', readings: 'readingIds', invoices: 'invoiceIds', invoice_detail: 'invoiceIds', payments: 'paymentIds' }[name]
          data = tenant[key].map(id => ({ id }))
          if (mode === 'foreign-list') data.push({ id: other[key][0] })
          if (name === 'invoice_detail') data = data[0]
        }
        if (mode === 'auth') status = 401
        if (mode === 'server-error') status = 500
        const body = JSON.stringify({ success: status === 200, data })
        return { status, body, timings: { duration: mode === 'slow' ? 5001 : 10 }, json: () => JSON.parse(body) }
      },
    },
  })
  vm.runInContext(source, context, { filename: fileURLToPath(new URL('read-only.k6.js', dir)) })
  return { context, calls, execution, metrics }
}

for (const base of ['https://quanlynhatro.com.vn/api', 'http://localhost:8000/api', 'http://127.0.0.1:8000/api/../webhooks']) {
  assert.throws(() => harness({ PERF_BASE_URL: base }), /Only http/)
}
assert.throws(() => harness({ PERF_DATASET_CONFIRMED: '' }), /Confirm/)
assert.throws(() => harness({ PERF_VUS: '6' }), /Safety limits/)
assert.throws(() => harness({ PERF_SECONDS: '121' }), /Safety limits/)
assert.throws(() => harness({ PERF_TOKEN_A: '' }), /Missing synthetic/)

const valid = harness()
vm.runInContext('setup()', valid.context)
assert.equal(valid.calls.length, 50)
assert.equal(valid.metrics.get('workload_requests'), undefined, 'Preflight must not enter workload metrics')
for (let iteration = 0; iteration < 24; iteration++) {
  valid.execution.vu.iterationInScenario = iteration
  vm.runInContext('runIteration()', valid.context)
}
assert.equal(valid.metrics.get('workload_requests').length, 24)
assert.ok(valid.calls.every(call => call.params.redirects === 0 && call.params.timeout === '5s'))
assert.ok(valid.calls.every(call => call.url.startsWith('http://127.0.0.1:8000/api/')))
assert.ok(valid.calls.some(call => call.params.headers.Authorization === 'Bearer offline-a'))
assert.ok(valid.calls.some(call => call.params.headers.Authorization === 'Bearer offline-b'))
for (const [mode, pattern] of [['leak', /Isolation/], ['foreign-list', /unexpected resource/], ['auth', /Authentication/], ['slow', /timeout/], ['server-error', /Preflight/]]) {
  const test = harness({}, mode)
  assert.throws(() => vm.runInContext('setup()', test.context), pattern)
}
valid.execution.instance.vusActive = 6
assert.throws(() => vm.runInContext('runIteration()', valid.context), /Runtime VU/)
console.log('Offline harness safety checks passed. No HTTP, SQL, k6 or performance benchmark executed.')
