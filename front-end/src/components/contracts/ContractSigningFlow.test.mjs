import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { buildSignaturePayload, SIGNING_EVENT_LABELS, SIGNING_STATUS_LABELS } from '../../utils/contractSigning.js'

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
const context = { contract_code: 'HD101', room_code: '101', boarding_house_name: 'BeeHouse', signer_name: 'Nguyễn Văn A', signer_role: 'TENANT', request_status: 'PENDING', signing_status: 'PENDING', masked_email: 'n***@example.test', rendered_html: '<p>Nội dung đã chốt</p>', content_hash: 'a'.repeat(64), consent_version: 'BEEHOUSE_SIGN_V1', consent_text: 'Tôi xác nhận đã đọc và đồng ý với nội dung hợp đồng này.', otp_verified: false }

async function form(initial = context, otpFailure = false) {
  const source = (await readFile(new URL('../../pages/contracts/ContractSigningPage.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'ContractSigningPage.jsx', { jsx: { runtime: 'classic' } })
  const states = [initial, false]
  const refs = []
  const sent = []
  let stateIndex = 0, refIndex = 0, latest = initial
  const canvas = { clear: () => {}, exportPng: async () => new Blob(['png'], { type: 'image/png' }) }
  const bindings = {
    useState(value) { const index = stateIndex++; if (!(index in states)) states[index] = value; return [states[index], next => { states[index] = typeof next === 'function' ? next(states[index]) : next }] },
    useRef(value) { return refs[refIndex++] ??= { current: value === null ? canvas : value } },
    useEffect: () => {}, useCallback: callback => callback,
    SIGNING_STATUS_LABELS, buildSignaturePayload, beeHouseLogo: 'logo.png', SignatureCanvas: () => null, FrozenContractHtml: () => null,
    Link: props => React.createElement('a', { href: props.to }, props.children), getApiErrorMessage: error => error.message,
    getSigningContext: async () => ({ data: { data: latest } }),
    requestSigningOtp: async target => { if (otpFailure) throw new Error('Chưa thể gửi mã OTP.'); sent.push(['otp', target]) },
    verifySigningOtp: async (target, otp) => { sent.push(['verify', otp]); latest = { ...latest, otp_verified: true }; return { data: { data: { verification_token: 'b'.repeat(64) } } } },
    submitContractSignature: async (target, payload) => { sent.push(['sign', payload]); latest = { ...latest, request_status: 'SIGNED', signing_status: 'PARTIALLY_SIGNED' } },
    declineContractSigning: async () => sent.push(['decline']),
  }
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn SigningForm`)(React, ...Object.values(bindings))
  return { states, sent, render() { stateIndex = 0; refIndex = 0; return Component({ token: 'opaque-token' }) } }
}
const button = (tree, label) => nodes(tree).find(node => node.type === 'button' && text(node) === label)

async function management(contact, confirmed = true) {
  const source = (await readFile(new URL('./ContractSigningSection.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'ContractSigningSection.jsx', { jsx: { runtime: 'classic' } })
  const states = [], refs = [], sent = [], prompts = []
  let index = 0, refIndex = 0, settled = Promise.resolve()
  const cached = { status: 'PENDING', signers: [], events: [], tenant_contact: { email: 'old@example.test' } }
  const bindings = {
    useState(value) { const i = index++; if (!(i in states)) states[i] = value; return [states[i], next => { states[i] = next }] },
    useRef(value) { return refs[refIndex++] ??= { current: value } },
    useQuery: () => ({ data: { data: { data: cached } } }),
    useQueryClient: () => ({ setQueryData() {}, invalidateQueries: async () => {} }),
    useMutation: options => ({ mutate(action) { settled = action().then(options.onSuccess) }, isPending: false }),
    getContractSigning: async () => ({ data: { data: { ...cached, tenant_contact: contact } } }),
    startContractSigning: async (...args) => { sent.push(args); return { data: { message: 'sent', data: { recipient_email: contact.email } } } },
    resendContractSigning: async (...args) => { sent.push(args); return { data: { message: 'sent', data: { recipient_email: contact.email } } } },
    signingKeys: { detail: id => ['contract-signing', String(id)] },
    SIGNING_STATUS_LABELS, SIGNER_STATUS_LABELS: {}, SIGNING_EVENT_LABELS,
    formatDocumentFinalizedAt: value => value, getApiErrorMessage: error => error.message,
    Link: props => React.createElement('a', null, props.children),
    window: { confirm: prompt => { prompts.push(prompt); return confirmed } },
  }
  const Component = new Function('React', ...Object.keys(bindings), `${code}\nreturn ContractSigningSection`)(React, ...Object.values(bindings))
  return {
    sent, prompts,
    render() { index = 0; refIndex = 0; return Component({ contract: { id: 14, document: {} } }) },
    async send() { await button(this.render(), 'Gửi lại yêu cầu ký').props.onClick(); await settled },
  }
}

test('resend confirms a fresh tenant email rather than the cached old contact', async () => {
  const ui = await management({ tenant_id: 2, name: 'Nguyễn Văn A', email: 'new@example.test' })
  await ui.send()
  assert.deepEqual(ui.sent, [[14, 'new@example.test']])
  assert.match(ui.prompts[0], /new@example.test/)
  assert.doesNotMatch(ui.prompts[0], /old@example.test/)
})

test('cancelled confirmation does not send a signing email', async () => {
  const ui = await management({ email: 'new@example.test' }, false)
  await ui.send()
  assert.deepEqual(ui.sent, [])
})

test('missing current tenant contact blocks sending instead of using an old snapshot', async () => {
  const ui = await management(null)
  await ui.send()
  assert.deepEqual(ui.sent, [])
  assert.match(text(ui.render()), /Backend chưa cung cấp email/)
})

test('signature payload binds consent/version/hash and sends a PNG file, never an image string', () => {
  const payload = buildSignaturePayload(context, new Blob(['png'], { type: 'image/png' }), 'b'.repeat(64))
  assert.equal(payload.get('consent'), '1')
  assert.equal(payload.get('content_hash'), context.content_hash)
  assert.equal(payload.get('verification_token'), 'b'.repeat(64))
  assert.equal(payload.get('consent_version'), 'BEEHOUSE_SIGN_V1')
  assert.equal(payload.get('signature').name, 'signature.png')
  assert.equal(payload.get('signature').type, 'image/png')
})

test('tenant must verify OTP, consent and draw before signing; success shows partial completion', async () => {
  const ui = await form()
  assert.equal(button(ui.render(), 'Xác nhận ký hợp đồng').props.disabled, true)
  await button(ui.render(), 'Gửi / Gửi lại mã OTP').props.onClick()
  const otp = nodes(ui.render()).find(node => node.type === 'input' && node.props.autoComplete === 'one-time-code')
  otp.props.onChange({ target: { value: '12a3456' } })
  await button(ui.render(), 'Xác thực OTP').props.onClick()
  const checkbox = nodes(ui.render()).find(node => node.type === 'input' && node.props.type === 'checkbox')
  checkbox.props.onChange({ target: { checked: true } })
  ui.states[7] = true // Canvas onChange updates the same form ink state.
  assert.equal(button(ui.render(), 'Xác nhận ký hợp đồng').props.disabled, false)
  await button(ui.render(), 'Xác nhận ký hợp đồng').props.onClick()
  assert.deepEqual(ui.sent.map(value => value[0]), ['otp', 'verify', 'sign'])
  assert.equal(ui.sent[1][1], '123456')
  assert.match(text(ui.render()), /Bạn đã xác nhận ký hợp đồng/)
  assert.equal(button(ui.render(), 'Xác nhận ký hợp đồng'), undefined)
})

test('OTP success displays an accessible alert next to the OTP controls with the masked email', async () => {
  const ui = await form()
  await button(ui.render(), 'Gửi / Gửi lại mã OTP').props.onClick()
  const tree = ui.render()
  const notice = nodes(tree).find(node => node.props.role === 'status' && text(node).includes('Đã gửi mã xác thực'))
  assert.ok(notice)
  assert.match(text(notice), /Đã gửi mã OTP đến n\*\*\*@example.test/)
  assert.match(text(notice), /Spam \/ Thư rác/)
  assert.equal(notice.props['aria-live'], 'polite')
  assert.ok(nodes(tree).some(node => node.type === 'div' && nodes(node.props.children).includes(notice) && nodes(node).some(child => child.props.autoComplete === 'one-time-code')))
})

test('OTP failure displays an error without announcing a successful send', async () => {
  const ui = await form(context, true)
  await button(ui.render(), 'Gửi / Gửi lại mã OTP').props.onClick()
  assert.match(text(ui.render()), /Chưa thể gửi mã OTP/)
  assert.doesNotMatch(text(ui.render()), /Đã gửi mã xác thực/)
})

test('signed state hides OTP/canvas controls; completed state offers print only when images ready', async () => {
  const ui = await form({ ...context, request_status: 'SIGNED', signing_status: 'COMPLETED', signed_rendered_html: '<p>Bản đã ký</p>' })
  assert.equal(button(ui.render(), 'Gửi / Gửi lại mã OTP'), undefined)
  assert.equal(button(ui.render(), 'In hợp đồng / Lưu PDF').props.disabled, true)
  assert.match(text(ui.render()), /Hợp đồng đã hoàn tất ký/)
})

test('public signing is outside authentication and uses a separate client without Sanctum interceptor', async () => {
  const routes = await readFile(new URL('../../routes/AppRoutes.jsx', import.meta.url), 'utf8')
  assert.ok(routes.indexOf('path="/sign/:token"') < routes.indexOf('<Route element={<ProtectedRoute />}'))
  const api = await readFile(new URL('../../api/contractSigningApi.js', import.meta.url), 'utf8')
  assert.match(api, /const publicClient = axios.create/)
  assert.match(api, /target.token \? publicClient : client/)
  assert.doesNotMatch(api, /localStorage|console\.log/)
  assert.equal(SIGNING_STATUS_LABELS.COMPLETED, 'Đã hoàn tất ký')
  assert.equal(SIGNING_EVENT_LABELS.OTP_VERIFIED, 'Đã xác thực OTP')
})
