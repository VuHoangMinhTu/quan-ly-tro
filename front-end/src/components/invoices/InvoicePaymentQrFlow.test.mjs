import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { transformWithOxc } from 'vite'
import { invoicePayosQr } from '../../utils/invoicePayment.js'
import { formatBillingPeriodForDisplay, formatDateForDisplay } from '../../utils/date.js'
import { invoiceStatusLabels } from '../../utils/invoiceStatus.js'

const formatCurrency = value => `${Number(value || 0).toLocaleString('vi-VN')} đ`
const formatUtilityValue = value => String(value ?? '')

const bank = { bank_name: 'Vietcombank', bank_account_name: 'Nguyễn Văn A', bank_account_number: '0012345', payment_qr_url: '/invoices/1/payment-qr', payment_qr_version: 'snapshot-version' }
const invoice = { id: 1, invoice_code: 'INV-1', status: 'UNPAID', total_amount: 150000, paid_amount: 0, payment_profile: bank }

async function render(data) {
  const Icon = () => React.createElement('svg')
  const source = (await readFile(new URL('./InvoicePrintLayout.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const bindings = { Building2: Icon, CreditCard: Icon, Home: Icon, UserRound: Icon,
    QRCodeSVG: ({ value }) => React.createElement('svg', { 'data-payos-qr': value }),
    PrivatePaymentQr: ({ profile }) => React.createElement('img', { src: 'blob:qr-preview', alt: 'QR ngân hàng', 'data-endpoint': profile.payment_qr_url }),
    invoicePayosQr, formatCurrency, formatUtilityValue, formatBillingPeriodForDisplay, formatDateForDisplay, invoiceStatusLabels, APP_TITLE: 'BeeHouse - Quản lý trọ' }
  const { code } = await transformWithOxc(source, 'InvoicePrintLayout.jsx', { jsx: { runtime: 'classic' } })
  const Layout = new Function('React', ...Object.keys(bindings), `${code}\nreturn InvoicePrintLayout`)(React, ...Object.values(bindings))
  return renderToStaticMarkup(React.createElement(Layout, { invoice: data }))
}

test('invoice without PayOS displays private bank QR and bank details', async () => {
  const markup = await render(invoice)
  assert.match(markup, /data-endpoint="\/invoices\/1\/payment-qr"/)
  assert.match(markup, /Quét mã để chuyển khoản/)
  assert.match(markup, /Vietcombank/)
  assert.match(markup, /0012345/)
  assert.doesNotMatch(markup, /data-payos-qr/)
})

test('usable PayOS QR takes priority while cancelled or expired requests fall back to static QR', async () => {
  const request = { qr_code: 'dynamic-payos-value', amount: 150000, status: 'PENDING', order_code: 123456, payment_link_id: 'link-123', checkout_url: 'https://pay.payos.vn/link-123' }
  const dynamic = await render({ ...invoice, payos_payment_request: request })
  assert.match(dynamic, /data-payos-qr="dynamic-payos-value"/)
  assert.doesNotMatch(dynamic, /data-endpoint=/)
  for (const invalid of [{ ...request, status: 'CANCELLED' }, { ...request, expired_at: '2020-01-01T00:00:00Z' }, { ...request, amount: 1 }]) {
    const markup = await render({ ...invoice, payos_payment_request: invalid })
    assert.match(markup, /data-endpoint=/)
    assert.doesNotMatch(markup, /data-payos-qr/)
  }
})

test('missing QR renders text without image placeholder and offers settings only to owner', async () => {
  const textOnly = await render({ ...invoice, payment_profile: { ...bank, payment_qr_url: null } })
  assert.match(textOnly, /Vietcombank/)
  assert.doesNotMatch(textOnly, /<img/)
  const empty = await render({ ...invoice, payment_profile: null, can_manage: true })
  assert.match(empty, /Chưa thiết lập thông tin nhận thanh toán/)
  assert.match(empty, /Thiết lập ngay/)
  assert.doesNotMatch(empty, /<img/)
})
