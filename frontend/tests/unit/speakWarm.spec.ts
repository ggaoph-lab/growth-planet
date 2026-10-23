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

it('awaits an in-flight warm instead of opening a second synthesize', async () => {
  let finishWarm!: (value: unknown) => void
  vi.mocked(api.post).mockImplementation((path: string) => {
    if (path === '/kid/tts/warm') {
      return new Promise(resolve => { finishWarm = resolve })
    }
    return Promise.resolve({ available: true, audio_base64: 'Yg==', mime: 'audio/mpeg', provider: 'edge' })
  })
  const { warmPhrasesNow, speakPhrase, stopSpeaking } = await import('@/shared/speak')
  warmPhrasesNow(['apple'], 'en-US')
  expect(api.post).toHaveBeenCalledWith('/kid/tts/warm', { texts: ['apple'], lang: 'en-US' })
  void speakPhrase('apple', 'en-US')
  await flushPromises()
  // speakPrepared may re-queue the same line via warmPhrasesNow; never open /kid/tts while warm is live.
  expect(vi.mocked(api.post).mock.calls.every((call) => call[0] === '/kid/tts/warm')).toBe(true)
  finishWarm!({ items: [{ text: 'apple', audio_base64: 'YQ==', mime: 'audio/mpeg' }] })
  await flushPromises()
  stopSpeaking()
  expect(api.post).not.toHaveBeenCalledWith('/kid/tts', expect.anything())
})
