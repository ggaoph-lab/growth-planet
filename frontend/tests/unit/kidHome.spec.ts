import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/shared/api/client'
import { useKidHomeStore } from '@/stores/kidHome'

vi.mock('@/shared/api/client', () => ({
  api: { get: vi.fn() },
}))

describe('kidHome store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
  })

  it('reuses one in-flight /kid/home fetch', async () => {
    let resolve!: (value: { profile: { nickname: string }; planets: [] }) => void
    vi.mocked(api.get).mockReturnValueOnce(
      new Promise((r) => {
        resolve = r
      }),
    )
    const store = useKidHomeStore()
    const a = store.load()
    const b = store.load()
    expect(api.get).toHaveBeenCalledTimes(1)
    resolve({ profile: { nickname: 'Zachary' }, planets: [] })
    await expect(a).resolves.toMatchObject({ profile: { nickname: 'Zachary' } })
    await expect(b).resolves.toMatchObject({ profile: { nickname: 'Zachary' } })
    await store.load()
    expect(api.get).toHaveBeenCalledTimes(1)
  })
})
