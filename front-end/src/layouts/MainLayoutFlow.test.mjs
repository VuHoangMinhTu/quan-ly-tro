import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { clearContractDocumentCache } from '../api/contractDocumentCache.js'

async function harness(pathname = '/dashboard') {
  const states = []
  let stateIndex = 0
  let mutationOptions
  const navigation = []
  const mutations = []
  const removedQueries = []
  const icons = ['Building2', 'FileText', 'Gauge', 'KeyRound', 'LayoutDashboard', 'LogOut', 'Menu', 'Receipt', 'UserRound', 'Users', 'X']
  const source = (await readFile(new URL('./MainLayout.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = {
    clearContractDocumentCache,
    useState: (initial) => {
      const index = stateIndex++
      if (!(index in states)) states[index] = initial
      return [states[index], (value) => { states[index] = typeof value === 'function' ? value(states[index]) : value }]
    },
    useRef: () => ({ current: null }),
    useEffect: () => {}, // Native focus/ESC/scroll locking is covered by browser QA.
    useLocation: () => ({ pathname }),
    useNavigate: () => (...args) => { navigation.push(args) },
    useQueryClient: () => ({ getQueryData: () => ({ data: { data: { user: { name: 'Minh Tú' } } } }), removeQueries: (value) => removedQueries.push(value) }),
    useMutation: (options) => { mutationOptions = options; return { mutate: () => mutations.push('logout'), isPending: false } },
    logout: () => {},
    getApiErrorMessage: () => 'Không thể đăng xuất.',
    APP_NAME: 'BeeHouse', beeHouseLogo: 'logo.png',
    Link: ({ to, children, ...props }) => React.createElement('a', { href: to, ...props }, children),
    Outlet: () => React.createElement('section', {}, 'Page content'),
    ...Object.fromEntries(icons.map((name) => [name, () => null])),
  }
  const { code } = await transformWithOxc(source, 'MainLayout.jsx', { jsx: { runtime: 'classic' } })
  const Component = new Function('React', ...Object.keys(bindings), code + '\nreturn MainLayout')(React, ...Object.values(bindings))
  const elements = (node) => Array.isArray(node) ? node.flatMap(elements) : !React.isValidElement(node) ? [] : typeof node.type === 'function' ? elements(node.type(node.props)) : [node, ...elements(node.props.children)]
  const render = () => { stateIndex = 0; return elements(Component()) }
  const button = (label) => render().find((node) => node.type === 'button' && node.props['aria-label'] === label)
  return { render, button, mutations, navigation, removedQueries, settle: (...args) => mutationOptions.onSettled(...args) }
}

test('drawer selection closes navigation and keeps the existing active room route', async () => {
  const layout = await harness('/rooms/42/edit')
  layout.button('Mở menu').props.onClick()
  assert.equal(layout.button('Mở menu').props['aria-expanded'], true)
  const active = layout.render().filter((node) => node.type === 'a' && node.props['aria-current'] === 'page')
  assert.deepEqual(active.map((node) => node.props.href), ['/rooms', '/rooms'])
  const selectHouse = layout.render().find((node) => node.type === 'a' && node.props.href === '/boarding-houses' && typeof node.props.onClick === 'function')
  selectHouse.props.onClick()
  assert.equal(layout.button('Mở menu').props['aria-expanded'], false)
  assert.deepEqual(layout.mutations, [])
})

test('account menu opens, preserves the password route and closes after selecting it', async () => {
  const layout = await harness()
  layout.button('Menu tài khoản').props.onClick()
  assert.equal(layout.button('Menu tài khoản').props['aria-expanded'], true)
  const password = layout.render().find((node) => node.type === 'a' && node.props.role === 'menuitem')
  assert.equal(password.props.href, '/change-password')
  password.props.onClick()
  assert.equal(layout.button('Menu tài khoản').props['aria-expanded'], false)
})

test('mobile logout retains token removal, me-cache clearing and login navigation', async () => {
  const layout = await harness()
  const previousStorage = globalThis.localStorage
  const removedTokens = []
  globalThis.localStorage = { removeItem: (key) => removedTokens.push(key) }
  try {
    layout.button('Menu tài khoản').props.onClick()
    layout.render().find((node) => node.type === 'button' && node.props.role === 'menuitem').props.onClick()
    assert.deepEqual(layout.mutations, ['logout'])
    assert.equal(layout.button('Menu tài khoản').props['aria-expanded'], false)
    layout.settle(null, null)
    assert.deepEqual(removedTokens, ['access_token'])
    assert.deepEqual(layout.removedQueries, ['me', 'dashboard', 'contract-document', 'contract-document-preview', 'contract-template', 'contract-templates', 'contract-signing'].map(prefix => ({ queryKey: [prefix] })))
    assert.deepEqual(layout.navigation, [['/login', { state: undefined }]])
  } finally {
    globalThis.localStorage = previousStorage
  }
})
