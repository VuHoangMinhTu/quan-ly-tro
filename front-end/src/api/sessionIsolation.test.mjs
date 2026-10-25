import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import axios from 'axios'
import { QueryObserver, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { endSession, getSession, isCurrentSession, replaceSession, subscribeSession } from './session.js'
import { installSessionInterceptors } from './sessionTransport.js'

async function component(path, name, bindings) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replaceAll('import.meta.env.VITE_API_BASE_URL', "'http://synthetic.invalid/api'").replaceAll('import.meta.env.DEV', 'false').replace('export default function ', 'function ').replace('export async function ', 'async function ')
  const { code } = await transformWithOxc(source, name + '.jsx', { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), code + '\nreturn ' + name)(React, ...Object.values(bindings))
}

test('logout A and login B never reuse cached rooms, invoices or tenants in the same tab', async () => {
  const previousStorage = globalThis.localStorage
  const values = new Map([['access_token', 'synthetic-token-a'], ['theme', 'dark']])
  globalThis.localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  const a = replaceSession('synthetic-token-a')
  const client = a.queryClient
  const keys = [['rooms', '1'], ['invoices', '1'], ['tenants'], ['payments', '1']]
  try {
    for (const key of keys) await client.fetchQuery({ queryKey: key, queryFn: async () => ({ owner: 'A' }) })
    let logoutOptions, loginOptions
    const icons = Object.fromEntries(['Building2', 'CreditCard', 'FileText', 'Gauge', 'KeyRound', 'LayoutDashboard', 'LogOut', 'Menu', 'Receipt', 'UserRound', 'Users', 'X'].map(name => [name, () => null]))
    const common = { ...icons, getSession, endSession, isCurrentSession, useQueryClient: () => client, useNavigate: () => () => {}, useLocation: () => ({}), getApiErrorMessage: () => '', useState: value => [value, () => {}], useEffect: () => {}, useRef: () => ({ current: null }) }
    const Layout = await component('../layouts/MainLayout.jsx', 'MainLayout', { ...common, useMutation: options => { logoutOptions = options; return {} }, logout: async () => {}, APP_NAME: 'Test', beeHouseLogo: '', Outlet: () => null, Link: () => null })
    Layout()
    await logoutOptions.onSettled(null, null)
    const completeLogin = await component('./authSession.js', 'completeLogin', { axios, endSession, isCurrentSession, replaceSession, getMe: async () => ({ data: { data: { user: { id: 'B' } } } }) })
    const Login = await component('../pages/auth/LoginPage.jsx', 'LoginPage', { ...common, completeLogin, useMutation: options => { loginOptions = options; return {} }, useForm: () => ({ register: () => ({}), formState: { errors: {} }, handleSubmit: () => () => {} }), z: { object: () => ({}), string: () => ({ min() { return this }, email() { return this } }) }, zodResolver: () => {}, login: async () => ({ data: { data: { token: 'synthetic-token-b' } } }), GoogleButton: () => null, Link: () => null })
    Login()
    const b = await loginOptions.mutationFn({})
    assert.notEqual(b.queryClient, client)
    for (const key of keys) {
      assert.equal(client.getQueryData(key), undefined, 'Old cache was not cleared: ' + key[0])
      assert.equal(b.queryClient.getQueryData(key), undefined, 'Data A survived account switch: ' + key[0])
    }
    assert.equal(b.queryClient.getQueryData(['me']).data.data.user.id, 'B')
    assert.equal(values.get('access_token'), 'synthetic-token-b')
    assert.equal(values.get('theme'), 'dark')
  } finally { endSession(getSession()); client.clear(); globalThis.localStorage = previousStorage }
})

function storage() {
  const previous = globalThis.localStorage
  const values = new Map([['theme', 'dark']])
  globalThis.localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  return () => { endSession(getSession()); globalThis.localStorage = previous }
}
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const response = (config, owner) => ({ config, status: 200, statusText: 'OK', headers: {}, data: { data: { owner } } })

test('late A response cannot populate B cache or update a manual state callback; caller signals still cancel', async () => {
  const restore = storage()
  const a = replaceSession('synthetic-token-a')
  const pending = deferred()
  let request, displayed
  const api = axios.create({ adapter: config => { request = config; return pending.promise } })
  installSessionInterceptors(api)
  const oldQuery = a.queryClient.fetchQuery({ queryKey: ['rooms', '1'], queryFn: () => api.get('/rooms/1') })
  const rejectedQuery = assert.rejects(oldQuery)
  const manual = api.get('/tenants').then(result => { displayed = result.data })
  const rejectedManual = assert.rejects(manual, axios.isCancel)
  try {
    assert.equal(request.headers.Authorization, 'Bearer synthetic-token-a')
    const b = replaceSession('synthetic-token-b')
    b.queryClient.setQueryData(['rooms', '1'], { owner: 'B' })
    assert.equal(request.signal.aborted, true)
    pending.resolve(response(request, 'A'))
    await Promise.all([rejectedQuery, rejectedManual])
    assert.equal(displayed, undefined)
    assert.deepEqual(b.queryClient.getQueryData(['rooms', '1']), { owner: 'B' })
    // Even an old async mutation callback holding its client cannot reach B.
    a.queryClient.setQueryData(['tenants'], { owner: 'A' })
    assert.equal(b.queryClient.getQueryData(['tenants']), undefined)
    const caller = new AbortController()
    const aborted = api.get('/rooms/2', { signal: caller.signal })
    caller.abort()
    await assert.rejects(aborted, axios.isCancel)
  } finally { a.queryClient.clear(); restore() }
})

test('late A 401/logout cannot revoke B; a current authenticated 401 clears only the session token', async () => {
  const restore = storage()
  const pending = deferred()
  let request
  const api = axios.create({ adapter: config => { request = config; return pending.promise } })
  installSessionInterceptors(api)
  try {
    const a = replaceSession('synthetic-token-a')
    const call = api.get('/me')
    const cancelled = assert.rejects(call, axios.isCancel)
    const b = replaceSession('synthetic-token-b')
    pending.reject(new axios.AxiosError('Synthetic 401', 'ERR_BAD_REQUEST', request, null, { status: 401, config: request }))
    await cancelled
    assert.equal(endSession(a), null)
    assert.equal(getSession(), b)
    assert.equal(localStorage.getItem('access_token'), 'synthetic-token-b')
    const expired = axios.create({ adapter: config => Promise.reject(new axios.AxiosError('Synthetic 401', 'ERR_BAD_REQUEST', config, null, { status: 401, config })) })
    installSessionInterceptors(expired)
    b.queryClient.setQueryData(['tenants'], { owner: 'B' })
    await assert.rejects(expired.get('/me'))
    assert.equal(getSession().token, null)
    assert.equal(getSession().errorMessage, 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
    assert.equal(b.queryClient.getQueryData(['tenants']), undefined)
    assert.equal(localStorage.getItem('theme'), 'dark')
  } finally { restore() }
})

test('a transport that ignores abort still cannot deliver a previous session response', async () => {
  const restore = storage()
  const pending = deferred()
  let request
  const api = axios.create({ adapter: config => { request = config; config.signal = undefined; return pending.promise } })
  installSessionInterceptors(api)
  try {
    replaceSession('synthetic-token-a')
    const call = api.get('/tenants')
    const cancelled = assert.rejects(call, axios.isCancel)
    replaceSession('synthetic-token-b')
    pending.resolve(response(request, 'A'))
    await cancelled
    assert.equal(getSession().token, 'synthetic-token-b')
  } finally { restore() }
})

test('failed late profile verification cannot clear a newer login', async () => {
  const restore = storage()
  const pending = deferred()
  try {
    const completeLogin = await component('./authSession.js', 'completeLogin', { axios, endSession, isCurrentSession, replaceSession, getMe: () => pending.promise })
    const verify = completeLogin('synthetic-token-a', getSession())
    const rejected = assert.rejects(verify)
    const b = replaceSession('synthetic-token-b')
    pending.reject(new Error('Synthetic failed profile verification'))
    await rejected
    assert.equal(getSession(), b)
    assert.equal(localStorage.getItem('access_token'), 'synthetic-token-b')
  } finally { restore() }
})

test('Google callback shares the session boundary and exchanges its code once during effect replay', async () => {
  const restore = storage()
  const effects = [], navigation = [], errors = []
  let exchanges = 0
  try {
    replaceSession('synthetic-token-a')
    getSession().queryClient.setQueryData(['tenants'], { owner: 'A' })
    const completeLogin = await component('./authSession.js', 'completeLogin', { axios, endSession, isCurrentSession, replaceSession, getMe: async () => ({ data: { data: { user: { id: 'Google B' } } } }) })
    const Page = await component('../pages/auth/GoogleCallbackPage.jsx', 'GoogleCallbackPage', {
      completeLogin, getSession, isCurrentSession, getApiErrorMessage: error => error.message,
      useEffect: effect => effects.push(effect), useRef: () => ({ current: null }), useState: value => [value, error => errors.push(error)],
      useSearchParams: () => [new URLSearchParams('code=synthetic')], useNavigate: () => (...args) => navigation.push(args), Link: () => null,
      exchangeGoogleCode: async () => { exchanges++; return { data: { data: { token: 'synthetic-google-b' } } } },
    })
    Page()
    const cleanup = effects[0]()
    cleanup()
    effects[0]()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(exchanges, 1)
    assert.equal(getSession().token, 'synthetic-google-b')
    assert.equal(getSession().queryClient.getQueryData(['tenants']), undefined)
    assert.equal(getSession().queryClient.getQueryData(['me']).data.data.user.id, 'Google B')
    assert.deepEqual(navigation, [['/dashboard', { replace: true }]])
    assert.deepEqual(errors, [])
  } finally { restore() }
})

test('session provider switches its client on token storage events and ignores unrelated preferences', async () => {
  const restore = storage()
  const previousWindow = globalThis.window
  let changed, cleanup
  globalThis.window = { addEventListener: (_type, handler) => { changed = handler }, removeEventListener: () => { changed = null } }
  try {
    const Provider = await component('./SessionQueryProvider.jsx', 'SessionQueryProvider', {
      useSyncExternalStore: (_subscribe, snapshot) => snapshot(), useEffect: effect => { cleanup = effect() },
      QueryClientProvider, getSession, replaceSession, subscribeSession,
    })
    const a = replaceSession('synthetic-token-a')
    assert.equal(Provider({ children: null }).props.client, a.queryClient)
    changed({ key: 'theme' })
    assert.equal(getSession(), a)
    localStorage.setItem('access_token', 'synthetic-token-b')
    changed({ key: 'access_token' })
    assert.equal(a.controller.signal.aborted, true)
    assert.equal(getSession().token, 'synthetic-token-b')
    assert.notEqual(Provider({ children: null }).props.client, a.queryClient)
    assert.equal(localStorage.getItem('theme'), 'dark')
  } finally { cleanup?.(); globalThis.window = previousWindow; restore() }
})

test('observers and private route gate cannot display A while B data is pending, including the same URL', async () => {
  const restore = storage()
  const observed = []
  let unsubscribe
  try {
    const a = replaceSession('synthetic-token-a')
    a.queryClient.setQueryData(['me'], { data: { data: { user: { id: 'A' } } } })
    a.queryClient.setQueryData(['tenants'], [{ full_name: 'Private A' }])
    const Gate = await component('../routes/ProtectedRoute.jsx', 'ProtectedRoute', {
      useSyncExternalStore: (_subscribe, snapshot) => snapshot(), useEffect: React.useEffect, subscribeSession, getSession, endSession, useQuery,
      getMe: async () => ({}), getApiErrorMessage: () => '', Navigate: () => React.createElement('p', null, 'login'),
      Outlet: () => React.createElement('p', null, getSession().queryClient.getQueryData(['tenants'])?.[0]?.full_name ?? 'empty'),
    })
    const render = session => renderToStaticMarkup(React.createElement(QueryClientProvider, { client: session.queryClient }, React.createElement(Gate)))
    assert.match(render(a), /Private A/)
    const keyA = Gate().key
    const b = replaceSession('synthetic-token-b')
    assert.notEqual(Gate().key, keyA, 'Private page/form subtree must remount on identity change')
    assert.doesNotMatch(render(b), /Private A/)
    assert.match(render(b), /Đang kiểm tra đăng nhập/)
    const pending = deferred()
    const observer = new QueryObserver(b.queryClient, { queryKey: ['tenants'], queryFn: () => pending.promise })
    unsubscribe = observer.subscribe(result => observed.push(result.data))
    assert.equal(observer.getCurrentResult().data, undefined)
    const loaded = b.queryClient.fetchQuery({ queryKey: ['tenants'], queryFn: () => pending.promise })
    pending.resolve([{ full_name: 'Private B' }])
    await loaded
    assert.ok(observed.every(data => !JSON.stringify(data)?.includes('Private A')))
    b.queryClient.setQueryData(['me'], { data: { data: { user: { id: 'B' } } } })
    assert.match(render(b), /Private B/)
    assert.doesNotMatch(render(b), /Private A/)
  } finally { unsubscribe?.(); restore() }
})
