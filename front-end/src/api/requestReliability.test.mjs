import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import axios from 'axios'
import { onlineManager, QueryObserver } from '@tanstack/react-query'
import { createAppQueryClient } from './queryClient.js'
import { installSessionInterceptors } from './sessionTransport.js'
import { installNotificationInterceptors } from './notificationTransport.js'
import { getApiErrorMessage } from './getApiErrorMessage.js'
import { clearToasts, getToasts } from '../notifications/toastStore.js'

async function httpClient() {
  const source = (await readFile(new URL('./axiosClient.js', import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '').replace('import.meta.env.VITE_API_BASE_URL', "'http://127.0.0.1'")
    .replace('export default client', 'return client')
  return new Function('axios', 'installSessionInterceptors', 'installNotificationInterceptors', source)(axios, installSessionInterceptors, installNotificationInterceptors)
}

async function serverFixture(handler) {
  const server = createServer(handler)
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  return { url: `http://127.0.0.1:${server.address().port}`, close: async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)) } }
}

test('browser offline hint cannot silently pause or replay a user mutation', async () => {
  clearToasts()
  const client = createAppQueryClient()
  const wasOnline = onlineManager.isOnline()
  let calls = 0
  const failure = new axios.AxiosError('Network Error', 'ERR_NETWORK', { method: 'post' })
  const mutation = client.getMutationCache().build(client, { mutationFn: async () => { calls++; throw failure } })
  try {
    onlineManager.setOnline(false)
    await assert.rejects(mutation.execute({}), error => error === failure)
    assert.equal(calls, 1)
    assert.equal(mutation.state.status, 'error')
    assert.equal(mutation.state.isPaused, false)
    onlineManager.setOnline(true)
    assert.equal(calls, 1, 'Reconnection must not replay a failed write')
  } finally { onlineManager.setOnline(wasOnline); client.clear(); clearToasts() }
})

test('actual Axios timeout settles a stalled write once and emits one actionable error', async () => {
  clearToasts()
  const api = await serverFixture(() => {})
  const http = await httpClient()
  try {
    assert.equal(http.defaults.timeout, 30000)
    await assert.rejects(http.post(api.url, {}, { timeout: 40 }), error => {
      assert.ok(['ECONNABORTED', 'ETIMEDOUT'].includes(error.code))
      assert.match(getApiErrorMessage(error), /kiểm tra dữ liệu trước khi thử lại/)
      return true
    })
    assert.equal(getToasts().length, 1)
    assert.equal(getToasts()[0].type, 'error')
  } finally { await api.close(); clearToasts() }
})

test('refetch awaited by mutation success cannot stay paused offline or pending indefinitely', async () => {
  let calls = 0
  const api = await serverFixture((_request, response) => {
    calls++
    if (calls === 1) { response.setHeader('Content-Type', 'application/json'); response.end('{"data":"saved"}') }
  })
  const http = await httpClient()
  const client = createAppQueryClient()
  const wasOnline = onlineManager.isOnline()
  let unsubscribe
  try {
    const options = { queryKey: ['reliability-detail'], queryFn: () => http.get(api.url, { timeout: 40 }), staleTime: Infinity }
    await client.fetchQuery(options)
    const observer = new QueryObserver(client, options)
    unsubscribe = observer.subscribe(() => {})
    onlineManager.setOnline(false)
    const mutation = client.getMutationCache().build(client, { mutationFn: async () => 'saved', onSuccess: () => client.invalidateQueries({ queryKey: options.queryKey }) })
    await mutation.execute({})
    assert.equal(mutation.state.status, 'success')
    assert.equal(client.getQueryState(options.queryKey).fetchStatus, 'idle')
    assert.equal(client.getQueryState(options.queryKey).status, 'error')
    assert.equal(calls, 2)
    assert.equal(getToasts().length, 0, 'A background GET must not claim the successful write failed')
  } finally { unsubscribe?.(); onlineManager.setOnline(wasOnline); client.clear(); await api.close(); clearToasts() }
})
