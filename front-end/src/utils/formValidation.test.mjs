import assert from 'node:assert/strict'
import { test } from 'node:test'
import { contractFormSchema, dormRoomFormSchema, invoiceFormSchema, numberInput, paymentFormSchema } from './formValidation.js'

const invoice = { contract_id: '', auto_code: true, invoice_code: '', billing_period: '10/2026', discount_amount: '', status: 'DRAFT', issued_at: '', due_date: '', note: '' }
const contract = { tenant_id: '1', auto_code: true, contract_code: '', start_date: '', end_date: '', signed_date: '', monthly_rent: '', deposit_amount: '', status: 'DRAFT', note: '' }
const payment = { amount: 100, payment_method: 'CASH', paid_at: '2026-10-25T09:30', reference_code: '', note: '' }
const dorm = { room_code: '101', room_name: '', area: '', description: '', beds: [{ bed_code: 'G01', monthly_rent: '', status: 'AVAILABLE' }] }
const errors = result => Object.fromEntries(result.error.issues.map(issue => [issue.path.join('.'), issue.message]))

test('optional numeric blanks are null and zero remains zero', () => {
  const schema = numberInput('Giá thuê', { optional: true })
  for (const value of ['', '  ', null, undefined]) assert.equal(schema.parse(value), null)
  for (const value of [0, '0']) assert.equal(schema.parse(value), 0)
})

for (const value of ['abc', 'NaN', NaN, Infinity, -1, {}, []]) {
  test(`invalid numeric input ${String(value)} is rejected with Vietnamese text`, () => {
    const result = numberInput('Giá thuê', { optional: true }).safeParse(value)
    assert.equal(result.success, false)
    assert.match(result.error.issues[0].message, /Giá thuê/)
    assert.doesNotMatch(result.error.issues[0].message, /expected|received|Invalid input/)
  })
}

test('invoice validates each field without requiring an optional contract or dates', () => {
  assert.equal(invoiceFormSchema().parse(invoice).discount_amount, 0)
  const result = invoiceFormSchema().safeParse({ ...invoice, auto_code: false, billing_period: '13/2026', discount_amount: -1, due_date: '31/02/2026' })
  assert.deepEqual(Object.keys(errors(result)).sort(), ['billing_period', 'discount_amount', 'due_date', 'invoice_code'])
})

test('invoice manual codes are trimmed and limited, server status choices are validated', () => {
  assert.equal(invoiceFormSchema().parse({ ...invoice, auto_code: false, invoice_code: ' INV-01 ' }).invoice_code, 'INV-01')
  for (const change of [{ invoice_code: 'x'.repeat(101) }, { status: 'INVALID' }, { discount_amount: 'abc' }]) assert.equal(invoiceFormSchema().safeParse({ ...invoice, ...change }).success, false)
})

test('paid invoice editing does not block on locked legacy financial fields', () => {
  assert.equal(invoiceFormSchema(false).safeParse({ ...invoice, billing_period: null, status: 'LEGACY', discount_amount: null }).success, true)
  assert.equal(invoiceFormSchema(false).safeParse({ ...invoice, due_date: '31/02/2026' }).success, false)
})

test('draft contract accepts absent dates/rent while active contract needs them', () => {
  assert.equal(contractFormSchema.parse(contract).monthly_rent, null)
  const result = contractFormSchema.safeParse({ ...contract, status: 'ACTIVE' })
  assert.deepEqual(Object.keys(errors(result)).sort(), ['monthly_rent', 'start_date'])
  assert.equal(contractFormSchema.safeParse({ ...contract, status: 'ACTIVE', start_date: '25/10/2026', monthly_rent: 0 }).success, true)
})

test('contract selection, date chronology and financial limits have field errors', () => {
  const result = contractFormSchema.safeParse({ ...contract, tenant_id: '', start_date: '25/10/2026', end_date: '24/10/2026', deposit_amount: -1 })
  assert.deepEqual(Object.keys(errors(result)).sort(), ['deposit_amount', 'end_date', 'tenant_id'])
  assert.equal(contractFormSchema.safeParse({ ...contract, monthly_rent: 1e14 }).success, false)
})

test('payment rejects nonnumbers, zero, overpayment and invalid timestamps', () => {
  const schema = paymentFormSchema(100)
  assert.equal(schema.parse(payment).amount, 100)
  for (const amount of ['', 'abc', NaN, Infinity, -1, 0, 101]) assert.equal(schema.safeParse({ ...payment, amount }).success, false)
  for (const paid_at of ['', '2026-02-31T09:30', '2026-10-25T24:00']) assert.equal(schema.safeParse({ ...payment, paid_at }).success, false)
})

test('dorm optional fields remain null and duplicate bed codes flag both rows', () => {
  const parsed = dormRoomFormSchema.parse(dorm)
  assert.equal(parsed.area, null)
  assert.equal(parsed.beds[0].monthly_rent, null)
  const result = dormRoomFormSchema.safeParse({ ...dorm, beds: [...dorm.beds, { ...dorm.beds[0], bed_code: ' g01 ' }] })
  assert.deepEqual(Object.keys(errors(result)).sort(), ['beds.0.bed_code', 'beds.1.bed_code'])
  assert.equal(dormRoomFormSchema.safeParse({ ...dorm, area: -1 }).success, false)
  assert.equal(dormRoomFormSchema.safeParse({ ...dorm, beds: [] }).success, false)
})
