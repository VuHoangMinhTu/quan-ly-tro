import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAppQueryClient } from './queryClient.js'
import { QueryObserver, focusManager } from '@tanstack/react-query'
import { DASHBOARD_REFRESH_MS, dashboardRefetchInterval } from './dashboardPolicy.js'

test('dashboard polling remains anchored to the last response when re-entered inside the freshness window', context => {
  context.mock.method(Date, 'now', () => 129000)
  assert.equal(dashboardRefetchInterval({ state: { dataUpdatedAt: 100000 } }), 1000)
  assert.equal(dashboardRefetchInterval({ state: { dataUpdatedAt: 0 } }), DASHBOARD_REFRESH_MS)
  assert.equal(dashboardRefetchInterval({ state: { dataUpdatedAt: 50000 } }), DASHBOARD_REFRESH_MS)
})

test('dashboard reuses fresh data across remount/focus, but refreshes after expiry and mutations', async context => {
  let now = 100000, calls = 0
  context.mock.method(Date, 'now', () => now)
  const client = createAppQueryClient()
  const options = { queryKey: ['dashboard'], queryFn: async () => ({ revision: ++calls }), refetchOnWindowFocus: true }
  let unsubscribe
  client.mount()
  try {
    await client.fetchQuery(options)
    const observer = new QueryObserver(client, options)
    unsubscribe = observer.subscribe(() => {})
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls, 1, 'Fresh dashboard remount should not refetch')
    focusManager.setFocused(false); focusManager.setFocused(true)
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls, 1, 'Fresh dashboard focus should not refetch')
    unsubscribe()
    now += 30001
    unsubscribe = new QueryObserver(client, options).subscribe(() => {})
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls, 2, 'Expired dashboard must refetch')
    const mutation = client.getMutationCache().build(client, { mutationFn: async () => ({}), gcTime: Infinity })
    await mutation.execute({})
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(calls, 3, 'Mutation must invalidate even inside the freshness window')
  } finally { unsubscribe?.(); client.unmount(); client.clear(); focusManager.setFocused(undefined) }
})

test('first invoice read is a polling baseline, while changed financial state invalidates dashboard', async () => {
  const client = createAppQueryClient()
  const poll = value => client.fetchQuery({ queryKey: ['invoice', 'synthetic-1'], queryFn: async () => ({ data: { data: value } }), staleTime: 0 })
  try {
    client.setQueryData(['dashboard'], { owner: 'synthetic-a' })
    await poll({ status: 'UNPAID', total_amount: '100.00', paid_amount: '0.00' })
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, false)
    await poll({ status: 'PARTIALLY_PAID', total_amount: '100.00', paid_amount: '40.00' })
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, true)
  } finally { client.clear() }
})

test('successful room/contract/invoice/payment/assignment mutations invalidate dashboard without replacing local callbacks', async () => {
  const client = createAppQueryClient()
  try {
    for (const kind of ['boarding-house', 'room', 'room-tenant', 'contract', 'invoice', 'payment', 'manager-assignment']) {
      client.setQueryData(['dashboard'], { summary: kind })
      let localSuccess = false
      const mutation = client.getMutationCache().build(client, { mutationFn: async () => ({ success: true }), onSuccess: () => { localSuccess = true }, gcTime: Infinity })
      await mutation.execute({ kind })
      assert.equal(localSuccess, true)
      assert.equal(client.getQueryState(['dashboard']).isInvalidated, true)
    }
  } finally { client.clear() }
})

test('failed mutations do not invalidate dashboard or report success', async () => {
  const client = createAppQueryClient()
  try {
    client.setQueryData(['dashboard'], { summary: 'unchanged' })
    const mutation = client.getMutationCache().build(client, { mutationFn: async () => { throw new Error('test failure') }, gcTime: Infinity })
    await assert.rejects(mutation.execute({}), /test failure/)
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, false)
  } finally { client.clear() }
})

test('payOS polling invalidates dashboard when invoice paid amount/status changes, not on unchanged polls', async () => {
  const client = createAppQueryClient()
  const poll = async invoice => client.fetchQuery({ queryKey: ['invoice', '7'], queryFn: async () => ({ data: { data: invoice } }), staleTime: 0 })
  try {
    await poll({ status: 'UNPAID', total_amount: '100.00', paid_amount: '0.00' })
    client.setQueryData(['dashboard'], {})
    await poll({ status: 'UNPAID', total_amount: '100.00', paid_amount: '0.00' })
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, false)
    await poll({ status: 'PARTIALLY_PAID', total_amount: '100.00', paid_amount: '40.00' })
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, true)
    client.setQueryData(['dashboard'], {})
    await poll({ status: 'PAID', total_amount: '100.00', paid_amount: '100.00' })
    assert.equal(client.getQueryState(['dashboard']).isInvalidated, true)
  } finally { client.clear() }
})
