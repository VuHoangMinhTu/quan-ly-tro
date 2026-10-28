import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { transformWithOxc } from 'vite'
import { normalizeHandoverPrint } from '../../utils/contractHandoverPrint.js'

async function load(path, name, bindings) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}
const nodes = node => Array.isArray(node) ? node.flatMap(nodes) : React.isValidElement(node) ? [node, ...nodes(node.props.children)] : []
const Link = ({ to, children, ...props }) => React.createElement('a', { href: to, ...props }, children)

test('shared paper renderer adds demo labels only when explicitly requested and keeps signatures blank', async () => {
  const Sheet = await load('./ContractDocumentSheet.jsx', 'ContractDocumentSheet', { normalizeHandoverPrint })
  const html = '<div class="word-contract"><p>Khách hàng từ database</p><div class="document-signatures"><div><p>Chưa ký</p></div></div></div>'
  const real = renderToStaticMarkup(Sheet({ html }))
  const demo = renderToStaticMarkup(Sheet({ html, demo: true, summary: '<DEMO>' }))
  assert.doesNotMatch(real, /BẢN MẪU|contract-demo-summary|Chưa ký/)
  assert.match(real, /Khách hàng từ database/)
  assert.match(real, /signature-handwritten-space/)
  assert.match(demo, /BẢN MẪU – DỮ LIỆU MINH HỌA/)
  assert.doesNotMatch(demo, /contract-demo-summary|&lt;DEMO&gt;/)
  assert.match(html, /Chưa ký/, 'View normalization must not mutate the stored HTML')
})

test('cards offer both read-only demos without a contract creation action', async () => {
  const Cards = await load('./ContractDemoCards.jsx', 'ContractDemoCards', { Link })
  const links = nodes(Cards()).filter(node => node.type === Link)
  assert.deepEqual(links.map(node => node.props.to), ['/contract-templates/demos/room', '/contract-templates/demos/room?print=1', '/contract-templates/demos/dorm', '/contract-templates/demos/dorm?print=1'])
  assert.equal(links.filter(node => node.props.target === '_blank').length, 2)
  assert.equal(nodes(Cards()).filter(node => node.type === 'button').length, 0)
})

test('demo page loads only its fixture GET, sets PDF title and uses the shared renderer', async () => {
  const calls = [], effects = []
  let type = 'room', printed = 0, printRequested = false
  const document = { title: 'BeeHouse', fonts: { ready: Promise.resolve() } }
  const Sheet = () => null
  const demo = { name: 'Phòng DEMO', filename: 'BeeHouse_HopDong_PhongTro_DEMO.pdf', summary: 'Minh họa', rendered_html: '<p>Demo</p>' }
  const Page = await load('../../pages/contracts/ContractDemoPage.jsx', 'ContractDemoPage', {
    useParams: () => ({ type }), useSearchParams: () => [new URLSearchParams(printRequested ? 'print=1' : '')], useEffect: callback => effects.push(callback),
    useQuery: options => { if (options.enabled) options.queryFn(); return { data: { data: { data: demo } } } },
    getContractDemo: key => calls.push(key), getApiErrorMessage: () => '', Link, ContractDocumentSheet: Sheet, document, window: { print: () => printed++ },
  })
  const page = Page()
  assert.deepEqual(calls, ['room'])
  assert.equal(nodes(page).find(node => node.type === Sheet).props.demo, true)
  const cleanup = effects[0]()
  assert.equal(document.title, 'BeeHouse_HopDong_PhongTro_DEMO')
  await nodes(page).find(node => node.type === 'button').props.onClick()
  assert.equal(printed, 1)
  cleanup()
  assert.equal(document.title, 'BeeHouse')
  printRequested = true; Page()
  const cleanupPrint = effects.at(-1)()
  await document.fonts.ready
  assert.equal(printed, 2)
  cleanupPrint()
  type = 'unknown'; Page()
  assert.deepEqual(calls, ['room', 'room'])
})
