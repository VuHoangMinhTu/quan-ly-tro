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
  const bindings = { BedDouble: Icon, Building2: Icon, CirclePlus: Icon, Droplet: Icon, Handshake: Icon, Home: Icon, Settings: Icon, Zap: Icon,
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
  assert.match(markup, /Quét mã QR để chuyển khoản thanh toán/)
  assert.match(markup, /invoice-bank-qr/)
  assert.match(markup, /Vietcombank/)
  assert.match(markup, /0012345/)
  assert.doesNotMatch(markup, /data-payos-qr/)
})

test('static landlord QR and bank details stay visible independently of an existing PayOS QR', async () => {
  const request = { qr_code: 'dynamic-payos-value', amount: 150000, status: 'PENDING', order_code: 123456, payment_link_id: 'link-123', checkout_url: 'https://pay.payos.vn/link-123' }
  const dynamic = await render({ ...invoice, payos_payment_request: request })
  assert.match(dynamic, /data-payos-qr="dynamic-payos-value"/)
  assert.match(dynamic, /data-endpoint="\/invoices\/1\/payment-qr"/)
  assert.match(dynamic, /Vietcombank/)
  assert.match(dynamic, /0012345/)
  for (const invalid of [{ ...request, status: 'CANCELLED' }, { ...request, expired_at: '2020-01-01T00:00:00Z' }, { ...request, amount: 1 }]) {
    const markup = await render({ ...invoice, payos_payment_request: invalid })
    assert.match(markup, /data-endpoint=/)
    assert.doesNotMatch(markup, /data-payos-qr/)
  }
})

test('missing QR renders text without image placeholder and offers settings only to owner', async () => {
  const textOnly = await render({ ...invoice, payment_profile: { ...bank, payment_qr_url: null } })
  assert.match(textOnly, /Vietcombank/)
  assert.doesNotMatch(textOnly, /data-endpoint=/)
  const empty = await render({ ...invoice, payment_profile: null, can_manage: true })
  assert.match(empty, /Chưa thiết lập thông tin nhận thanh toán/)
  assert.match(empty, /Vui lòng liên hệ chủ trọ/)
  assert.match(empty, /Thiết lập ngay/)
  assert.doesNotMatch(empty, /data-endpoint=/)
})

test('bill shows base charges without source badges and keeps historical VAT transparent', async () => {
  const items = [{ id: 1, description: 'Tiền phòng tháng 10/2026', source: 'AUTO', type: 'RENT', quantity: 1, unit_price: 3000000, amount: 3000000 },
    { id: 2, description: 'Điện', source: 'AUTO', type: 'ELECTRICITY', quantity: 71, unit_price: 4000, amount: 284000 },
    { id: 3, description: 'Nước', source: 'AUTO', type: 'WATER', quantity: 1, unit_price: 20000, amount: 20000 },
    { id: 4, description: 'Internet', source: 'AUTO', type: 'INTERNET', quantity: 1, unit_price: 100000, amount: 100000 }]
  const markup = await render({ ...invoice, subtotal: 3404000, total_amount: 3404000, items })
  assert.doesNotMatch(markup, /Tự động|Thủ công|VAT/)
  assert.match(markup, /3\.404\.000/)
  const historical = await render({ ...invoice, subtotal: 3426720, total_amount: 3426720, items: [...items,
    { id: 5, description: 'VAT tiền điện (8%)', type: 'SERVICE_TAX', source: 'AUTO', quantity: 1, unit_price: 22720, amount: 22720 }] })
  assert.match(historical, /VAT tiền điện \(8%\)/)
  assert.match(historical, /3\.426\.720/)
  assert.match(historical, /không tự điều chỉnh dữ liệu lịch sử/)
})
