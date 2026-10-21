import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { api } from '@/shared/api/client'

vi.mock('@/shared/api/client', () => ({ api: { post: vi.fn() } }))
beforeEach(() => { vi.resetModules(); vi.mocked(api.post).mockReset() })

it('deduplicates pending phrases and limits background requests to two', async () => {
  const finishes: Array<(value: unknown) => void> = []
  vi.mocked(api.post).mockImplementation(() => new Promise(resolve => { finishes.push(resolve) }))
  const { warmPhrases } = await import('@/shared/speak')
  const texts = Array.from({ length: 24 }, (_, i) => `phrase ${i}`)
  warmPhrases(texts, 'en-US')
  warmPhrases(texts, 'en-US')
  expect(api.post).toHaveBeenCalledTimes(2)
  finishes[0]!({ items: [] })
  await flushPromises()
  expect(api.post).toHaveBeenCalledTimes(3)
  finishes[1]!({ items: [] })
  finishes[2]!({ items: [] })
  await flushPromises()
})

it('keeps cached phrases and allows retry after a failed prefetch', async () => {
  vi.mocked(api.post).mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ items: [{ text: 'hello', audio_base64: 'YQ==', mime: 'audio/mpeg' }] })
  const { warmPhrases } = await import('@/shared/speak')
  warmPhrases(['hello'], 'en-US')
  await flushPromises()
  warmPhrases(['hello'], 'en-US')
  await flushPromises()
  warmPhrases(['hello'], 'en-US')
  expect(api.post).toHaveBeenCalledTimes(2)
})
