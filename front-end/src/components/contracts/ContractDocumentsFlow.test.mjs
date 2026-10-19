import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { contractDocumentKeys } from '../../api/contractTemplateKeys.js'
import * as helpers from '../../utils/contractDocuments.js'
import { getApiErrorMessage, getApiFieldErrors } from '../../api/getApiErrorMessage.js'

function nodes(node) {
  if (Array.isArray(node)) return node.flatMap(nodes)
  if (!React.isValidElement(node)) return []
  return [node, ...nodes(node.props.children)]
}
function text(node) {
  if (Array.isArray(node)) return node.map(text).join(' ')
  if (React.isValidElement(node)) return text(node.props.children)
  return node == null || typeof node === 'boolean' ? '' : String(node)
}
async function load(path, name, bindings) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  return new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
}
const Link = ({ to, children }) => React.createElement('a', { href: to }, children)
const variables = [{ key: 'tenant.full_name', placeholder: '{{tenant.full_name}}', label: 'Họ tên', group: 'Người thuê' }]

test('placeholder validation identifies unknown names and insertion uses the current text selection', () => {
  assert.equal(helpers.validateContractTemplateVariables('<p>{{ tenant.full_name }}</p>', variables), '')
  assert.equal(helpers.validateContractTemplateVariables('{{tenant.fulln_ame}}', variables), 'Biến {{tenant.fulln_ame}} không được hỗ trợ.')
  assert.match(helpers.validateContractTemplateVariables('{{tenant.full_name', variables), /Cú pháp/)
  assert.deepEqual(helpers.insertContractTemplateVariable('ABC DEF', '{{tenant.full_name}}', 4, 7), { content: 'ABC {{tenant.full_name}}', caret: 24 })
})

test('template preview is isolated in a scriptless sandbox rather than inserted into app DOM', async () => {
  const Preview = await load('./ContractTemplatePreview.jsx', 'ContractTemplatePreview', {})
  const element = Preview({ html: '<p>Nguyễn Văn A</p>' })
  assert.equal(element.type, 'iframe')
  assert.equal(element.props.sandbox, '')
  assert.equal(element.props.referrerPolicy, 'no-referrer')
  assert.match(element.props.srcDoc, /Nguyễn Văn A/)
})

test('document section supports draft finalization, invalidates dependent data and navigates to the frozen document', async () => {
  const calls = []; const destinations = []; const invalidated = []
  let settled
  const Section = await load('./ContractDocumentSection.jsx', 'ContractDocumentSection', {
    ...helpers, Link, contractDocumentKeys, getApiErrorMessage,
    useNavigate: () => value => destinations.push(value), useQueryClient: () => ({ invalidateQueries: async ({ queryKey }) => invalidated.push(queryKey) }),
    useMutation: options => ({ mutate: () => { settled = (async () => { await options.mutationFn(); await options.onSuccess() })() } }),
    finalizeContractDocument: async id => calls.push(id), window: { confirm: () => true },
  })
  const contract = { id: 1, status: 'DRAFT', contract_template: { name: 'Mẫu riêng', version: 2 } }
  const tree = Section({ contract })
  assert.ok(nodes(tree).some(node => node.type === Link && node.props.to === '/contracts/1/document?preview=1'))
  await nodes(tree).find(node => node.type === 'button' && text(node) === 'Chốt hợp đồng').props.onClick()
  await settled
  assert.deepEqual(calls, [1])
  assert.deepEqual(destinations, ['/contracts/1/document'])
  assert.ok(invalidated.some(key => key[0] === 'room-tenants'))
  const legacy = Section({ contract: { id: 2, status: 'ACTIVE' } })
  assert.match(text(legacy), /Chưa có tài liệu hợp đồng được chốt/)
  assert.equal(nodes(legacy).filter(node => node.type === 'button').length, 0)
  const finalized = Section({ contract: { id: 1, status: 'ACTIVE', document: { template_name: 'Mẫu đã chốt', template_version: 1, finalized_at: '2026-11-01T03:00:00Z', document_hash: 'hidden-hash' } } })
  assert.match(text(finalized), /Mẫu đã chốt/)
  assert.doesNotMatch(text(finalized), /hidden-hash/)
})

test('document page reads the finalized HTML endpoint; only drafts use preview and only final documents offer print', async () => {
  let preview = false; let printed = 0; const calls = []
  const data = { rendered_html: '<p>Văn bản đã chốt</p>', template_name: 'Mẫu riêng', template_version: 1, finalized_at: '2026-11-01T03:00:00Z', is_preview: false }
  const Page = await load('../../pages/contracts/ContractDocumentPage.jsx', 'ContractDocumentPage', {
    ...helpers, Link, contractDocumentKeys, getApiErrorMessage,
    useParams: () => ({ id: '7' }), useSearchParams: () => [new URLSearchParams(preview ? 'preview=1' : '')],
    useQuery: options => { options.queryFn(); return { data: { data: { data: { ...data, is_preview: preview } } } } },
    getContractDocument: id => calls.push(['final', id]), previewContractDocument: id => calls.push(['preview', id]),
    document: { fonts: { ready: Promise.resolve() } }, window: { print: () => { printed += 1 } },
  })
  const final = Page()
  assert.deepEqual(calls[0], ['final', '7'])
  assert.equal(nodes(final).find(node => node.props.dangerouslySetInnerHTML).props.dangerouslySetInnerHTML.__html, data.rendered_html)
  await nodes(final).find(node => node.type === 'button').props.onClick()
  assert.equal(printed, 1)
  preview = true
  assert.equal(nodes(Page()).filter(node => node.type === 'button').length, 0)
  assert.deepEqual(calls.at(-1), ['preview', '7'])
})

test('template editor prevents unknown variables before API calls and locks content of used versions', async () => {
  const state = []; let cursor = 0; let calls = 0
  const Preview = () => null
  const Editor = await load('../../pages/contracts/ContractTemplateEditorPage.jsx', 'TemplateEditor', {
    ...helpers, getApiErrorMessage, getApiFieldErrors, ContractTemplatePreview: Preview,
    useState: initial => { const index = cursor++; if (!(index in state)) state[index] = initial; return [state[index], value => { state[index] = value }] },
    useRef: () => ({ current: null }), previewContractTemplate: async () => { calls += 1; return { data: { data: { rendered_html: '<p>Minh họa</p>' } } } },
  })
  const render = (initial = null) => { cursor = 0; return Editor({ initial, variables, canManage: true, save: async () => { calls += 1 }, clone: async () => {} }) }
  nodes(render()).find(node => node.type === 'textarea').props.onChange({ target: { value: '{{tenant.wrong}}' } })
  await nodes(render()).find(node => node.type === 'button' && text(node) === 'Xem trước').props.onClick()
  assert.equal(calls, 0)
  assert.match(text(render()), /không được hỗ trợ/)
  nodes(render()).find(node => node.type === 'textarea').props.onChange({ target: { value: '<p>{{tenant.full_name}}</p>' } })
  await nodes(render()).find(node => node.type === 'button' && text(node) === 'Xem trước').props.onClick()
  assert.equal(calls, 1)
  assert.ok(nodes(render()).some(node => node.type === Preview))
  const locked = render({ id: 1, landlord_id: 3, documents_count: 1 })
  assert.equal(nodes(locked).find(node => node.type === 'textarea').props.readOnly, true)
  assert.match(text(locked), /tạo phiên bản mới/)
})

test('global BeeHouse template is shown for viewing and owner clone opens an editable copy', async () => {
  const navigated = []
  const List = await load('../../pages/contracts/ContractTemplateListPage.jsx', 'ContractTemplateListPage', {
    useNavigate: () => path => navigated.push(path),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
    useState: initial => [initial, () => {}],
    useQuery: () => ({ data: { data: { data: { can_manage: true, templates: [{ id: 4, name: 'Hợp đồng cho thuê phòng tiêu chuẩn', template_key: 'ROOM_RENTAL_STANDARD', version: 1, landlord_id: null, is_active: true, documents_count: 0 }] } } } }),
    useMutation: options => ({ mutate: async payload => options.onSuccess(await options.mutationFn(payload), payload), isPending: false }),
    contractTemplateKeys: { all: ['contract-templates'] },
    getContractTemplates: async () => ({}),
    createContractTemplateVersion: async () => ({ data: { data: { id: 9 } } }),
    setContractTemplateStatus: async () => {},
    Link, getApiErrorMessage, formatDate: () => '19/10/2026',
  })
  const list = List()
  assert.match(text(list), /Hợp đồng cho thuê phòng tiêu chuẩn/)
  assert.match(text(list), /BeeHouse\s*· v\s*1/)
  assert.equal(nodes(list).some(node => node.type === 'button' && text(node) === 'Sửa'), false)
  await nodes(list).find(node => node.type === 'button' && text(node) === 'Tạo bản sao').props.onClick()
  assert.deepEqual(navigated, ['/contract-templates/9'])
})

test('A4 document route is outside MainLayout and print styles do not scale the document', async () => {
  const routes = await readFile(new URL('../../routes/AppRoutes.jsx', import.meta.url), 'utf8')
  assert.ok(routes.indexOf('path="/contracts/:id/document"') < routes.indexOf('<Route element={<MainLayout />}'))
  const css = await readFile(new URL('../../pages/contracts/ContractDocumentPage.css', import.meta.url), 'utf8')
  assert.match(css, /@page contract-document.*A4 portrait/)
  assert.doesNotMatch(css, /scale\(|zoom\s*:/)
  assert.match(css, /contract-document-actions \{ display: none !important/)
  assert.match(css, /height: auto/)
})
