import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, setSudoHandler } from '@/shared/api/client'

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('api client', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=token%3D123; path=/'
  })

  afterEach(() => {
    fetchMock.mockReset()
    setSudoHandler(null)
    vi.unstubAllGlobals()
  })

  it('unwraps data and sends the decoded XSRF token on writes', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { data: { ok: true } }))

    const result = await api.post<{ ok: boolean }>('/parent/devices', { name: 'iPad' })

    expect(result).toEqual({ ok: true })
    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(url).toBe('/api/v1/parent/devices')
    expect((init?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('token=123')
    expect(init?.credentials).toBe('same-origin')
  })

  it('throws ApiError with the server error envelope', async () => {
    fetchMock.mockResolvedValueOnce(json(422, { error: { code: 'VALIDATION_FAILED', message: '提交的内容有误', details: { fields: { pin: ['PIN 格式不正确'] } } } }))

    const error = await api.put('/parent/children/1/pin', { pin: '12' }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).code).toBe('VALIDATION_FAILED')
    expect((error as ApiError).fieldErrors.pin).toEqual(['PIN 格式不正确'])
  })

  it('asks for sudo on 423 and retries once confirmed', async () => {
    const handler = vi.fn().mockResolvedValue(true)
    setSudoHandler(handler)
    fetchMock
      .mockResolvedValueOnce(json(423, { error: { code: 'SUDO_REQUIRED', message: '请再次输入家长密码' } }))
      .mockResolvedValueOnce(json(200, { data: { id: 1 } }))

    const result = await api.delete<{ id: number }>('/parent/devices/1')

    expect(handler).toHaveBeenCalledOnce()
    expect(result).toEqual({ id: 1 })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('surfaces SUDO_REQUIRED when the parent cancels', async () => {
    setSudoHandler(vi.fn().mockResolvedValue(false))
    fetchMock.mockResolvedValueOnce(json(423, { error: { code: 'SUDO_REQUIRED', message: '请再次输入家长密码' } }))

    await expect(api.delete('/parent/devices/1')).rejects.toMatchObject({ code: 'SUDO_REQUIRED', status: 423 })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('maps network failures to a friendly error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))

    await expect(api.get('/kid/home')).rejects.toMatchObject({ code: 'NETWORK_ERROR', status: 0 })
  })

  it('sends Idempotency-Key on postIdempotent', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { data: { ok: true } }))

    await api.postIdempotent('/kid/task-instances/1/submit')

    const [, init] = fetchMock.mock.calls[0] ?? []
    const headers = init?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/i)
  })

  it('generates Idempotency-Key when crypto.randomUUID is unavailable (LAN HTTP)', async () => {
    const original = crypto.randomUUID
    // @ts-expect-error simulate non-secure context (e.g. http://192.168.x.x)
    delete crypto.randomUUID
    fetchMock.mockResolvedValueOnce(json(200, { data: { ok: true } }))

    try {
      await api.postIdempotent('/kid/english/learning/answer', { key: 'word:1', answer: 'apple' })
      const [, init] = fetchMock.mock.calls[0] ?? []
      const headers = init?.headers as Record<string, string>
      expect(headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/i)
    } finally {
      crypto.randomUUID = original
    }
  })

  it('reuses Idempotency-Key when retrying after 419', async () => {
    fetchMock
      .mockResolvedValueOnce(json(419, {}))
      .mockResolvedValueOnce(new Response('', { status: 204 }))
      .mockResolvedValueOnce(json(200, { data: { ok: true } }))

    await api.postIdempotent('/parent/approvals/1/approve', { lock_version: 0 })

    const apiCalls = fetchMock.mock.calls.filter(([url]) => String(url).startsWith('/api/v1'))
    const key1 = (apiCalls[0]?.[1]?.headers as Record<string, string>)['Idempotency-Key']
    const key2 = (apiCalls[1]?.[1]?.headers as Record<string, string>)['Idempotency-Key']
    expect(key1).toBeTruthy()
    expect(key2).toBe(key1)
  })
})
