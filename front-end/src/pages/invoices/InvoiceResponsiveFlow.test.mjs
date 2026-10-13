import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { transformWithOxc } from 'vite'
import { invoiceKeys } from '../../api/invoiceKeys.js'
import { paymentKeys } from '../../api/paymentKeys.js'
import { canEditInvoiceFinancials } from '../../utils/invoiceFinancials.js'
import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage.js'

function elements(node) {
  if (Array.isArray(node)) return node.flatMap(elements)
  if (!React.isValidElement(node)) return []
  if (typeof node.type === 'function') return elements(node.type(node.props))
  return [node, ...elements(node.props.children)]
}

function text(node) {
  if (Array.isArray(node)) return node.map(text).join(' ')
  if (React.isValidElement(node)) return text(node.props.children)
  return typeof node === 'string' || typeof node === 'number' ? String(node) : ''
}

const invoiceFixture = {
  id: 1, room_id: 2, invoice_code: 'HD-2026-10', billing_period: '2026-10-01',
  status: 'UNPAID', subtotal: 3800000, discount_amount: 0, total_amount: 3800000,
  paid_amount: 1000000, items: [], note: 'Thông tin có dấu tiếng Việt',
}

async function pageHarness(path, { invoice = invoiceFixture, payments = [] } = {}) {
  const states = []
  let stateCursor = 0
  let mutationCursor = 0
  const mutations = []
  const bindings = {
    invoiceKeys, paymentKeys, canEditInvoiceFinancials, applyApiFieldErrors, getApiErrorMessage,
    useState: (initial) => {
      const index = stateCursor++
      if (!(index in states)) states[index] = initial
      return [states[index], (value) => { states[index] = value }]
    },
    useParams: () => ({ id: '1', roomId: '2' }),
    useNavigate: () => () => {},
    useQueryClient: () => ({ invalidateQueries: () => {} }),
    useQuery: ({ queryKey }) => ({ data: { data: { data: queryKey[0] === 'payments' ? payments : queryKey[0] === 'invoices' ? [invoice] : invoice } } }),
    useMutation: (options) => {
      const index = mutationCursor++
      if (!mutations[index]) mutations[index] = { options, calls: [] }
      return { mutate: (payload) => mutations[index].calls.push(payload), mutateAsync: async (payload) => mutations[index].calls.push(payload) }
    },
    formatCurrency: (value) => `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value))} đ`,
    formatDate: (value) => value || '—',
    Link: ({ to, children, ...props }) => React.createElement('a', { ...props, href: to }, children),
    InvoiceStatusBadge: ({ status }) => React.createElement('span', null, status),
    PaymentForm: (props) => React.createElement('payment-form', props),
    QRCodeSVG: (props) => React.createElement('svg', props),
    deleteInvoice: () => {}, generateInvoice: () => {}, getInvoice: () => {}, getInvoicesByRoom: () => {},
    createInvoiceItem: () => {}, deleteInvoiceItem: () => {}, updateInvoiceItem: () => {},
    createPayment: () => {}, deletePayment: () => {}, getPayments: () => {}, updatePayment: () => {}, createPayOSPaymentRequest: () => {},
    window: { confirm: () => true },
  }
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const name = source.match(/function (InvoiceDetailPage|InvoiceListPage)\(/)[1]
  const { code } = await transformWithOxc(source, `${name}.jsx`, { jsx: { runtime: 'classic' } })
  const Page = new Function('React', ...Object.keys(bindings), `${code}\nreturn ${name}`)(React, ...Object.values(bindings))
  const render = () => { stateCursor = 0; mutationCursor = 0; return elements(Page()) }
  return { render, mutations }
}

test('invoice list uses the same amounts and detail route in mobile cards and desktop table', async () => {
  const page = await pageHarness('./InvoiceListPage.jsx')
  const rendered = page.render()
  assert.match(rendered.find((node) => node.type === 'h2').props.className, /text-xl/)
  assert.match(rendered.find((node) => node.type === 'h2').props.className, /sm:text-2xl/)
  const card = rendered.find((node) => node.type === 'article')
  assert.match(text(card), /2\.800\.000 đ/)
  assert.equal(rendered.filter((node) => node.type === 'a' && node.props.href === '/invoices/1').length, 2)
  const tableContainer = rendered.find((node) => node.type === 'div' && node.props.className?.includes('overflow-x-auto'))
  assert.ok(tableContainer.props.className.includes('max-w-full'))
})

test('existing payOS QR is reused in a bounded modal with an aspect-preserving SVG', async () => {
  const paymentRequest = { amount: 2800000, qr_code: 'existing-qr-payload', order_code: '123456', checkout_url: 'https://pay.payos.vn/example', description: 'NGUYEN VAN AN CK' }
  const page = await pageHarness('./InvoiceDetailPage.jsx', { invoice: { ...invoiceFixture, payos_payment_request: paymentRequest } })
  page.render().find((node) => node.type === 'button' && text(node) === 'Xem QR thanh toán').props.onClick()
  const rendered = page.render()
  const modal = rendered.find((node) => node.props.role === 'dialog')
  assert.equal(modal.props['aria-label'], 'Thanh toán qua payOS')
  const close = elements(modal).find((node) => node.type === 'button' && node.props['aria-label'] === 'Đóng')
  assert.match(close.props.className, /min-h-11/)
  assert.match(close.props.className, /min-w-11/)
  const scrollPanel = elements(modal).find((node) => node.props.className?.includes('max-h-[90dvh]'))
  assert.ok(scrollPanel.props.className.includes('overflow-y-auto'))
  const qr = rendered.find((node) => node.type === 'svg')
  assert.equal(qr.props.value, paymentRequest.qr_code)
  assert.equal(qr.props.size, 220)
  assert.match(qr.props.className, /h-auto/)
  assert.match(qr.props.className, /max-w-full/)
  assert.equal(page.mutations[4].calls.length, 0)
})

test('payment cards and table preserve source restrictions and the edit remaining amount', async () => {
  const cash = { id: 1, amount: 1000000, payment_method: 'CASH', payment_source: 'MANUAL', reference_code: 'CASH-1' }
  const payos = { id: 2, amount: 500000, payment_method: 'PAYOS', payment_source: 'PAYOS_WEBHOOK', reference_code: 'PAYOS-2' }
  const page = await pageHarness('./InvoiceDetailPage.jsx', { payments: [cash, payos] })
  const rendered = page.render()
  const paymentCards = rendered.filter((node) => node.type === 'article')
  assert.equal(paymentCards.length, 2)
  assert.ok(elements(paymentCards[0]).some((node) => node.type === 'button' && text(node) === 'Sửa'))
  assert.equal(elements(paymentCards[1]).some((node) => node.type === 'button'), false)
  elements(paymentCards[0]).find((node) => node.type === 'button' && text(node) === 'Sửa').props.onClick()
  const form = page.render().find((node) => node.type === 'payment-form')
  assert.equal(form.props.remaining, 3800000)
  assert.equal(form.props.initialValues.id, 1)
})

test('PAID invoice hides payment creation and retains the paid display', async () => {
  const page = await pageHarness('./InvoiceDetailPage.jsx', { invoice: { ...invoiceFixture, status: 'PAID', paid_amount: 3800000, paid_at: '2026-10-13T03:00:00Z' } })
  const rendered = page.render()
  assert.equal(rendered.some((node) => node.type === 'button' && text(node) === 'Ghi nhận tiền mặt'), false)
  assert.equal(rendered.some((node) => node.type === 'button' && text(node) === 'Tạo QR thanh toán'), false)
  assert.ok(rendered.some((node) => text(node).includes('Đã thanh toán đầy đủ')))
})
