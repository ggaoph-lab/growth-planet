import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { api } from '@/shared/api/client'

vi.mock('@/shared/api/client', () => ({ api: { post: vi.fn() } }))
beforeEach(() => {
  vi.resetModules()
  document.querySelectorAll('audio').forEach(el => el.remove())
  vi.mocked(api.post).mockReset().mockResolvedValue({ available: true, audio_base64: 'YQ==', mime: 'audio/mpeg', provider: 'edge_tts' })
  vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test-audio')
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
})
afterEach(() => { vi.restoreAllMocks() })

it('uses an unmuted lead-in before the first word and signals actual playback separately', async () => {
  let finishUnlock!: () => void
  const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) {
    return this.src.startsWith('data:') ? new Promise<void>(resolve => { finishUnlock = resolve }) : Promise.resolve()
  })
  const { unlockSpeak, speakPhrase } = await import('@/shared/speak')
  const unlocking = unlockSpeak()
  const started = vi.fn()
  const speech = speakPhrase('apple', 'en-US', started)
  await flushPromises()
  expect(started).not.toHaveBeenCalled()
  expect(document.querySelector('audio')!.muted).toBe(false)
  expect(api.post).not.toHaveBeenCalled()
  finishUnlock()
  await unlocking
  await flushPromises()
  expect(play).toHaveBeenCalledTimes(2)
  const audio = document.querySelector('audio')!
  expect(audio.muted).toBe(false)
  expect(audio.src).toBe('blob:test-audio')
  audio.dispatchEvent(new Event('playing'))
  expect(started).toHaveBeenCalledOnce()
  audio.dispatchEvent(new Event('ended'))
  expect(await speech).toBe(true)
})

it('reports blocked autoplay and plays the cached first word after one unlock', async () => {
  vi.spyOn(HTMLMediaElement.prototype, 'play').mockRejectedValueOnce(new DOMException('gesture required', 'NotAllowedError')).mockResolvedValue(undefined)
  const { speakPhrase, unlockSpeak, isSpeakUnlocked } = await import('@/shared/speak')
  expect(await speakPhrase('apple', 'en-US')).toBe(false)
  expect(isSpeakUnlocked()).toBe(false)
  expect(await unlockSpeak()).toBe(true)
  const speech = speakPhrase('apple', 'en-US')
  await flushPromises()
  document.querySelector('audio')!.dispatchEvent(new Event('ended'))
  expect(await speech).toBe(true)
  expect(api.post).toHaveBeenCalledTimes(1)
})

it('does not revive a cancelled word when an in-flight unlock finishes', async () => {
  let finishUnlock!: () => void
  vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(() => new Promise<void>(resolve => { finishUnlock = resolve }))
  const { unlockSpeak, speakPhrase, stopSpeaking } = await import('@/shared/speak')
  const unlocking = unlockSpeak()
  const speech = speakPhrase('apple', 'en-US')
  stopSpeaking()
  finishUnlock()
  await unlocking
  expect(await speech).toBe(false)
  expect(api.post).not.toHaveBeenCalled()
})
