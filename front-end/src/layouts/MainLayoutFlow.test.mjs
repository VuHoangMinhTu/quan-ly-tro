import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'

async function harness(pathname = '/dashboard', owner = false) {
  const states = []
  let stateIndex = 0
  let mutationOptions
  const navigation = []
  const mutations = []
  const removedQueries = []
  const session = {}
  const endedSessions = []
  const icons = ['Building2', 'CreditCard', 'FileText', 'Gauge', 'KeyRound', 'LayoutDashboard', 'LogOut', 'DoorClosed', 'Grid2X2', 'Receipt', 'UserRound', 'Users', 'X']
  const source = (await readFile(new URL('./MainLayout.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = {
    getSession: () => session,
    isCurrentSession: value => value === session,
    endSession: value => { endedSessions.push(value); globalThis.localStorage.removeItem('access_token') },
    useState: (initial) => {
      const index = stateIndex++
      if (!(index in states)) states[index] = initial
      return [states[index], (value) => { states[index] = typeof value === 'function' ? value(states[index]) : value }]
    },
    useRef: () => ({ current: null }),
    useEffect: () => {}, // Native focus/ESC/scroll locking is covered by browser QA.
    useLocation: () => ({ pathname }),
    useNavigate: () => (...args) => { navigation.push(args) },
    useQueryClient: () => ({ getQueryData: () => ({ data: { data: { user: { name: 'Minh Tú' }, landlord: owner ? { id: 5 } : null } } }), removeQueries: (value) => removedQueries.push(value) }),
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
  return { render, button, setPath: value => { pathname = value }, mutations, navigation, removedQueries, endedSessions, settle: (...args) => mutationOptions.onSettled(...args) }
}

function activeTabs(layout) {
  return layout.render().filter(node => node.props.className === 'bh-bottom-item' && node.props['data-active'] === true)
}
function assertActive(layout, index) {
  const active = activeTabs(layout)
  assert.equal(active.length, 1)
  const nav = layout.render().find(node => node.props['aria-label'] === 'Điều hướng nhanh')
  assert.equal(nav.props.style['--bh-active-index'], index)
  assert.equal(active[0].props.href ?? 'more', ['/dashboard', '/boarding-houses', '/rooms', '/invoices', 'more'][index])
}

test('stable five tabs follow the route, including nested house, room and invoice routes', async () => {
  const layout = await harness('/rooms/42/edit')
  const links = layout.render().filter(node => node.type === 'a' && node.props.className === 'bh-bottom-item')
  assert.deepEqual(links.map(node => node.props.href), ['/dashboard', '/boarding-houses', '/rooms', '/invoices'])
  assertActive(layout, 2)
  for (const [path, index] of [['/dashboard', 0], ['/boarding-houses/new', 1], ['/boarding-houses/10/rooms/new', 2], ['/rooms/42/invoices/new', 3], ['/invoices/7/edit', 3], ['/rooms/42/contracts/new', 4], ['/utility-meters/7', 4], ['/tenants/1/edit', 4], ['/boarding-houses/10/services', 4], ['/residence-declarations/7', 4]]) {
    layout.setPath(path)
    assertActive(layout, index)
  }
})

test('opening More selects only More; dismissing without navigation restores the previous route', async () => {
  const layout = await harness('/rooms/42/edit')
  const more = () => layout.button('Mở menu Khác')
  assertActive(layout, 2)
  more().props.onClick()
  assert.equal(more().props['aria-expanded'], true)
  assertActive(layout, 4)
  layout.button('Đóng menu').props.onClick()
  assert.equal(more().props['aria-expanded'], false)
  assertActive(layout, 2)
  more().props.onClick()
  const selectUtility = layout.render().find(node => node.type === 'a' && node.props.href === '/utilities' && typeof node.props.onClick === 'function')
  selectUtility.props.onClick()
  layout.setPath('/utilities')
  assert.equal(more().props['aria-expanded'], false)
  assertActive(layout, 4)
  assert.equal(more().props['aria-current'], 'true')
  const utilityTile = layout.render().find(node => node.type === 'a' && node.props.href === '/utilities' && typeof node.props.onClick === 'function')
  assert.equal(utilityTile.props['aria-current'], 'page')
  layout.setPath('/boarding-houses/10')
  assertActive(layout, 1)
})

test('More preserves existing owner-only contract templates', async () => {
  const manager = await harness('/utilities')
  assertActive(manager, 4)
  assert.equal(manager.render().filter(node => node.type === 'a' && node.props.href === '/contract-templates').length, 0)
  const owner = await harness('/contracts/7', true)
  assertActive(owner, 4)
  assert.equal(owner.render().filter(node => node.type === 'a' && node.props.href === '/contract-templates').length, 2)
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

test('mobile logout ends the entire session and retains login navigation', async () => {
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
    assert.equal(layout.endedSessions.length, 1)
    assert.deepEqual(layout.removedQueries, [])
    assert.deepEqual(layout.navigation, [['/login', { state: undefined }]])
  } finally {
    globalThis.localStorage = previousStorage
  }
})

test('one responsive account menu contains owner payment settings and neither sidebar does', async () => {
  const layout = await harness('/payment-settings', true)
  assert.equal(layout.render().filter(node => node.type === 'a' && node.props.href === '/payment-settings').length, 0)
  assert.equal(layout.render().filter(node => node.type === 'button' && node.props['aria-label'] === 'Menu tài khoản').length, 1)
  layout.button('Menu tài khoản').props.onClick()
  const links = layout.render().filter(node => node.type === 'a' && node.props.role === 'menuitem')
  assert.deepEqual(links.map(node => node.props.href), ['/payment-settings', '/change-password'])
  links[0].props.onClick()
  assert.equal(layout.button('Menu tài khoản').props['aria-expanded'], false)
})
