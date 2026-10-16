import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAppQueryClient } from './queryClient.js'

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
