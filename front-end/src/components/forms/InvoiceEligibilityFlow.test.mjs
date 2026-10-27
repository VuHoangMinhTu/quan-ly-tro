import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import React from 'react'
import { createFormControl } from 'react-hook-form'
import { transformWithOxc } from 'vite'
import { zodResolver } from '@hookform/resolvers/zod'
import * as dates from '../../utils/date.js'
import { invoiceFormSchema } from '../../utils/formValidation.js'
import { contractKeys } from '../../api/contractKeys.js'
import { invoiceStatusLabels } from '../../utils/invoiceStatus.js'
import { toFormString } from '../../utils/form.js'

test('creation options follow billing period and room; changing month clears the old selection', async () => {
  let form, dependencies, query
  const requests = []
  const input = props => React.createElement('input', props)
  const contracts = {
    '1:2026-11-01': [{ id: 10, contract_code: 'ACTIVE-10', status: 'ACTIVE', room_code: '101', tenant: { full_name: 'Người hiện tại' }, start_date: '2026-10-21', end_date: '2027-10-20' }],
    '1:2026-09-01': [],
    '2:2026-11-01': [{ id: 20, contract_code: 'ROOM-2', status: 'ACTIVE', room_code: '102', tenant: { full_name: 'Phòng khác' }, start_date: '2026-09-01' }],
    '3:2026-11-01': [{ id: 30, contract_code: 'BED-G01', status: 'ACTIVE', room_code: '201', bed: { id: 1, bed_code: 'G01' }, tenant: { full_name: 'Người thuê giường' }, start_date: '2026-09-01' }],
  }
  const bindings = {
    ...dates, invoiceFormSchema, zodResolver, contractKeys, invoiceStatusLabels, toFormString,
    handleFormSubmit: () => () => {}, applyApiFieldErrors: () => {}, getApiErrorMessage: () => '',
    useState: value => [value, () => {}],
    useEffect: (effect, next) => {
      if (!dependencies || next.some((value, index) => value !== dependencies[index])) { effect(); dependencies = next }
    },
    useForm: options => {
      if (!form) {
        form = createFormControl(options)
        form.subscribe({ formState: { values: true }, callback: () => {} })
        Object.defineProperty(form, 'formState', { get: () => form.control._formState })
      }
      return form
    },
    useWatch: ({ name }) => form.getValues(name),
    useQuery: options => {
      query = options
      return { data: { data: { data: { contracts: contracts[`${options.queryKey[1]}:${options.queryKey[3]}`] || [], warnings: [] } } }, isPending: false, isSuccess: true }
    },
    getInvoiceContracts: (...args) => requests.push(args), getContractsByRoom: () => { throw new Error('Creation must not request all room contracts') },
    Controller: ({ name, render }) => render({ field: { name, value: form.getValues(name), onChange: value => form.setValue(name, value) } }),
    DateInput: input, MoneyInput: input,
  }
  const source = (await readFile(new URL('./InvoiceForm.jsx', import.meta.url), 'utf8')).replace(/^import .*$/gm, '').replace('export default function ', 'function ')
  const { code } = await transformWithOxc(source, 'InvoiceForm.jsx', { jsx: { runtime: 'classic' } })
  const Form = new Function('React', ...Object.keys(bindings), `${code}\nreturn InvoiceForm`)(React, ...Object.values(bindings))
  const values = { billing_period: '2026-11-01' }
  const render = roomId => Form({ roomId, initialValues: values, onSubmit: () => {}, label: 'Tạo' })
  const elements = node => {
    if (Array.isArray(node)) return node.flatMap(elements)
    if (!React.isValidElement(node)) return []
    if (typeof node.type === 'function') return elements(node.type(node.props))
    return [node, ...elements(node.props.children)]
  }
  const text = node => Array.isArray(node) ? node.map(text).join(' ') : React.isValidElement(node) ? text(node.props.children) : typeof node === 'string' ? node : ''
  render(1)
  let tree = render(1)
  assert.deepEqual(query.queryKey, ['contracts', 1, 'invoice-eligible', '2026-11-01'])
  query.queryFn()
  assert.deepEqual(requests[0], [1, '2026-11-01'])
  assert.match(text(tree), /ACTIVE-10.*Người hiện tại.*Phòng 101.*Đang hiệu lực/)
  assert.doesNotMatch(text(tree), /OLD-9/)
  form.setValue('contract_id', '10')
  elements(tree).find(element => element.type === 'input' && element.props.name === 'billing_period').props.onChange('09/2026')
  tree = render(1)
  assert.equal(form.getValues('contract_id'), '')
  assert.deepEqual(query.queryKey, ['contracts', 1, 'invoice-eligible', '2026-09-01'])
  assert.match(text(tree), /Phòng hiện chưa có người thuê\/hợp đồng đang hoạt động/)
  assert.doesNotMatch(text(tree), /OLD-9/)
  assert.equal(elements(tree).find(element => element.type === 'button').props.disabled, true)
  assert.doesNotMatch(text(tree), /ACTIVE-10/)
  render(2)
  tree = render(2)
  assert.deepEqual(query.queryKey, ['contracts', 2, 'invoice-eligible', '2026-11-01'])
  assert.match(text(tree), /ROOM-2/)
  assert.doesNotMatch(text(tree), /OLD-9|ACTIVE-10/)
  render(3)
  tree = render(3)
  assert.match(text(tree), /Phòng 201 \/ Giường G01/)
})
