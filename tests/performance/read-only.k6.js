import http from 'k6/http'
import { check, sleep } from 'k6'
import exec from 'k6/execution'
import { Rate, Trend, Counter } from 'k6/metrics'

// Literal IPv4 loopback only: no DNS, production override, redirects or writes.
const base = __ENV.PERF_BASE_URL || 'http://127.0.0.1:8000/api'
if (!/^http:\/\/127\.0\.0\.1:\d{2,5}\/api$/.test(base)) {
  throw new Error('Only http://127.0.0.1:PORT/api is allowed; use an isolated local test server.')
}
if (__ENV.PERF_DATASET_CONFIRMED !== 'synthetic-test-only') {
  throw new Error('Confirm the server uses only synthetic test data before running.')
}
const fixtures = JSON.parse(open(__ENV.PERF_FIXTURES || './fixtures.example.json'))
const idKeys = ['houseIds', 'roomIds', 'tenantIds', 'contractIds', 'serviceIds', 'meterIds', 'readingIds', 'invoiceIds', 'paymentIds']
if (fixtures.dataset !== 'synthetic-test-only' || !Array.isArray(fixtures.tenants) || fixtures.tenants.length < 2 || fixtures.tenants.length > 10) {
  throw new Error('Require 2..10 independent synthetic landlords for this bounded smoke test.')
}
for (const [i, tenant] of fixtures.tenants.entries()) {
  if (!__ENV[tenant.tokenEnv] || !Number.isSafeInteger(tenant.landlordId) || !Number.isSafeInteger(tenant.userId)) {
    throw new Error('Missing synthetic token environment variable or identity.')
  }
  if (!tenant.dashboard || !['total_boarding_houses', 'total_rooms', 'active_tenants'].every(key => Number.isSafeInteger(tenant.dashboard[key]))) {
    throw new Error('Expected dashboard counts are required.')
  }
  for (const key of idKeys) {
    if (!Array.isArray(tenant[key]) || !tenant[key].length || !tenant[key].every(id => Number.isSafeInteger(id) && id > 0)) {
      throw new Error('Provide nonempty complete synthetic ID allowlists for every resource.')
    }
    for (let j = 0; j < i; j++) {
      if (tenant[key].some(id => fixtures.tenants[j][key].includes(id))) throw new Error('Fixture ownership overlaps.')
    }
  }
  if (fixtures.tenants.slice(0, i).some(other => other.landlordId === tenant.landlordId || other.userId === tenant.userId || other.tokenEnv === tenant.tokenEnv)) {
    throw new Error('Synthetic landlord identities must be independent.')
  }
}
const vus = Number(__ENV.PERF_VUS || 1)
const seconds = Number(__ENV.PERF_SECONDS || 30)
if (!Number.isInteger(vus) || vus < 1 || vus > 5 || !Number.isInteger(seconds) || seconds < 10 || seconds > 120) {
  throw new Error('Safety limits: 1..5 VUs, 10..120 seconds.')
}
const errors = new Rate('workload_errors')
const accessErrors = new Rate('auth_or_fixture_errors')
const isolationErrors = new Rate('isolation_failures')
const latency = new Trend('workload_latency', true)
const requests = new Counter('workload_requests')
const responseCharacters = new Trend('response_characters')
const names = ['dashboard', 'houses', 'rooms', 'tenants', 'tenant_search', 'contracts', 'services', 'meters', 'readings', 'invoices', 'invoice_detail', 'payments']
const thresholds = {
  workload_errors: [{ threshold: 'rate<0.01', abortOnFail: true, delayAbortEval: '10s' }],
  auth_or_fixture_errors: ['rate==0'],
  isolation_failures: ['rate==0'],
  workload_latency: [{ threshold: 'p(95)<2000', abortOnFail: true, delayAbortEval: '10s' }],
}
for (const name of names) thresholds[`workload_latency{endpoint:${name}}`] = ['p(95)<2000']
export const options = {
  scenarios: { smoke: { executor: 'constant-vus', vus, duration: `${seconds}s`, gracefulStop: '3s' } },
  setupTimeout: '60s', teardownTimeout: '5s', maxRedirects: 0,
  summaryTrendStats: ['avg', 'min', 'med', 'p(50)', 'p(95)', 'p(99)', 'max', 'count'],
  systemTags: ['name', 'method', 'status', 'scenario', 'expected_response', 'check'],
  thresholds,
}

function abort(reason) { exec.test.abort(reason); throw new Error(reason) }
function get(tenant, path, name, isolation = false, phase = 'measurement') {
  const response = http.get(`${base}${path}`, {
    headers: { Authorization: `Bearer ${__ENV[tenant.tokenEnv]}`, Accept: 'application/json' },
    timeout: '5s', redirects: 0,
    tags: { name, endpoint: name, traffic: isolation ? 'isolation' : 'workload', phase },
    responseCallback: http.expectedStatuses(isolation ? 404 : 200),
  })
  if (isolation) {
    const safe = response.status === 404
    isolationErrors.add(!safe)
    check(response, { 'foreign resource returns 404': () => safe })
    if (!safe) abort('Isolation precondition failed; inspect privately. No response body logged.')
    return null
  }
  const authFailure = [401, 403, 404, 422].includes(response.status)
  accessErrors.add(authFailure)
  if (authFailure) abort('Authentication/fixture failure, not a performance result.')
  if (phase === 'measurement') {
    errors.add(response.status !== 200)
    latency.add(response.timings.duration, { endpoint: name })
    requests.add(1, { endpoint: name })
    responseCharacters.add(response.body ? response.body.length : 0, { endpoint: name })
  }
  if (response.timings.duration >= 5000 || response.status === 0) abort('Request exceeded safety timeout or transport failed.')
  check(response, { 'owned endpoint returns 200': r => r.status === 200 })
  if (response.status !== 200) {
    if (phase === 'preflight') abort('Preflight failed; no workload started.')
    return null
  }
  let body
  try { body = response.json() } catch { abort('Response is not JSON.') }
  if (body.success !== true || !Object.prototype.hasOwnProperty.call(body, 'data')) abort('Unexpected API envelope.')
  return body.data
}

function assertOwned(tenant, rows, key) {
  if (!Array.isArray(rows)) abort('Unexpected list shape; update script after API contract changes.')
  const allowed = new Set(tenant[key])
  const safe = rows.every(row => allowed.has(Number(row.id)) && (row.landlord_id == null || Number(row.landlord_id) === tenant.landlordId))
  isolationErrors.add(!safe)
  if (!safe) abort('List contained an unexpected resource; stop and inspect privately.')
}

function workload(tenant, index, phase = 'measurement') {
  const h = tenant.houseIds[0], r = tenant.roomIds[0], m = tenant.meterIds[0], i = tenant.invoiceIds[0]
  const operations = [
    ['/dashboard', 'dashboard', null],
    ['/boarding-houses', 'houses', 'houseIds'],
    [`/boarding-houses/${h}/rooms`, 'rooms', 'roomIds'],
    ['/tenants', 'tenants', 'tenantIds'],
    ['/tenants?q=synthetic&occupancy_status=ACTIVE', 'tenant_search', 'tenantIds'],
    [`/rooms/${r}/contracts`, 'contracts', 'contractIds'],
    [`/boarding-houses/${h}/services`, 'services', 'serviceIds'],
    [`/rooms/${r}/utility-meters`, 'meters', 'meterIds'],
    [`/utility-meters/${m}/readings`, 'readings', 'readingIds'],
    [`/rooms/${r}/invoices`, 'invoices', 'invoiceIds'],
    [`/invoices/${i}`, 'invoice_detail', null],
    [`/invoices/${i}/payments`, 'payments', 'paymentIds'],
  ]
  const [path, name, key] = operations[index % operations.length]
  const data = get(tenant, path, name, false, phase)
  if (data === null) return
  if (key) assertOwned(tenant, data, key)
  if (name === 'invoice_detail') assertOwned(tenant, [data], 'invoiceIds')
  if (name === 'dashboard') {
    assertOwned(tenant, data.boarding_houses, 'houseIds')
    assertOwned(tenant, data.recent_unpaid_invoices, 'invoiceIds')
    assertOwned(tenant, data.expiring_contracts, 'contractIds')
    if (!Object.entries(tenant.dashboard).every(([field, value]) => data.summary[field] === value)) abort('Dashboard aggregate differs from synthetic fixture.')
  }
}

function foreign(tenant, other, index, phase = 'measurement') {
  const paths = [
    `/boarding-houses/${other.houseIds[0]}`, `/boarding-houses/${other.houseIds[0]}/rooms`,
    `/rooms/${other.roomIds[0]}`, `/tenants/${other.tenantIds[0]}`,
    `/contracts/${other.contractIds[0]}`, `/services/${other.serviceIds[0]}`,
    `/utility-meters/${other.meterIds[0]}`, `/utility-readings/${other.readingIds[0]}`,
    `/invoices/${other.invoiceIds[0]}`, `/invoices/${other.invoiceIds[0]}/payments`,
    `/payments/${other.paymentIds[0]}`, `/rooms/${other.roomIds[0]}/contract-context`,
  ]
  get(tenant, paths[index % paths.length], 'foreign_resource', true, phase)
}

export function setup() {
  // Validate every configured identity, then fully probe the first A/B pair.
  for (const tenant of fixtures.tenants) {
    const me = get(tenant, '/me', 'identity', false, 'preflight')
    if (!me || Number(me.user.id) !== tenant.userId || Number(me.landlord.id) !== tenant.landlordId) abort('Token identity differs from fixture.')
    sleep(0.2)
  }
  for (let i = 0; i < 2; i++) {
    const tenant = fixtures.tenants[i], other = fixtures.tenants[1 - i]
    for (let endpoint = 0; endpoint < 12; endpoint++) {
      workload(tenant, endpoint, 'preflight'); sleep(0.2)
      foreign(tenant, other, endpoint, 'preflight'); sleep(0.2)
    }
  }
}

export default function () {
  if (exec.instance.vusActive > 5) abort('Runtime VU limit exceeded.')
  // Rotate landlords even with one VU; with two or more VUs, owners overlap in time.
  const iteration = exec.vu.iterationInScenario
  const at = (exec.vu.idInTest - 1 + iteration) % fixtures.tenants.length
  const tenant = fixtures.tenants[at], other = fixtures.tenants[(at + 1) % fixtures.tenants.length]
  workload(tenant, iteration + exec.vu.idInTest - 1)
  sleep(1)
  if (iteration % 5 === 0) { foreign(tenant, other, Math.floor(iteration / 5)); sleep(1) }
}
