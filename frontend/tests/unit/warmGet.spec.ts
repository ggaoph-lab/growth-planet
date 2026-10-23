import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/shared/api/client'
import { clearWarmGets, peekWarmGet, warmGet } from '@/shared/api/warmGet'

vi.mock('@/shared/api/client', () => ({
  api: { get: vi.fn() },
}))

describe('warmGet', () => {
  beforeEach(() => {
    clearWarmGets()
    vi.mocked(api.get).mockReset()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('reuses one in-flight GET for the same path', async () => {
    let resolve!: (value: { ok: boolean }) => void
    vi.mocked(api.get).mockReturnValueOnce(
      new Promise((r) => {
        resolve = r
      }),
    )

    const a = warmGet<{ ok: boolean }>('/kid/wallet')
    const b = warmGet<{ ok: boolean }>('/kid/wallet')
    expect(api.get).toHaveBeenCalledTimes(1)
    resolve({ ok: true })
    await expect(a).resolves.toEqual({ ok: true })
    await expect(b).resolves.toEqual({ ok: true })
    expect(peekWarmGet('/kid/wallet')).toEqual({ ok: true })
  })

  it('force bypasses the short TTL cache', async () => {
    vi.mocked(api.get)
      .mockResolvedValueOnce({ n: 1 })
      .mockResolvedValueOnce({ n: 2 })

    await expect(warmGet('/kid/tasks')).resolves.toEqual({ n: 1 })
    await expect(warmGet('/kid/tasks')).resolves.toEqual({ n: 1 })
    await expect(warmGet('/kid/tasks', undefined, { force: true })).resolves.toEqual({ n: 2 })
    expect(api.get).toHaveBeenCalledTimes(2)
  })

  it('drops failed fetches so the next call retries', async () => {
    vi.mocked(api.get).mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce({ ok: true })

    await expect(warmGet('/kid/bag')).rejects.toThrow('boom')
    expect(peekWarmGet('/kid/bag')).toBeUndefined()
    await expect(warmGet('/kid/bag')).resolves.toEqual({ ok: true })
    expect(api.get).toHaveBeenCalledTimes(2)
  })
})
