import { afterEach, expect, it, vi } from 'vitest'
import { prepareVoiceUpload } from '@/kid/englishBeta/audio'

afterEach(() => vi.unstubAllGlobals())

it('keeps WebM and converts Safari MP4 audio to 16 kHz mono WAV', async () => {
  const webm = new Blob(['webm'], { type: 'audio/webm' })
  expect(await prepareVoiceUpload(webm, 'audio/webm')).toEqual({ blob: webm, filename: 'speech.webm' })

  vi.stubGlobal('AudioContext', class {
    async decodeAudioData() { return { duration: 1 } }
    async close() { return undefined }
  })
  vi.stubGlobal('OfflineAudioContext', class {
    destination = {}
    createBufferSource() { return { buffer: null, connect: vi.fn(), start: vi.fn() } }
    async startRendering() { return { getChannelData: () => new Float32Array([0, 1, -1]) } }
  })
  const result = await prepareVoiceUpload(new Blob(['mp4'], { type: 'audio/mp4' }), 'audio/mp4')
  expect(result.filename).toBe('speech.wav')
  expect(result.blob.type).toBe('audio/wav')
  const view = new DataView(await result.blob.arrayBuffer())
  expect(view.getUint32(24, true)).toBe(16000)
  expect(view.getUint16(22, true)).toBe(1)
  expect(view.getUint32(40, true)).toBe(6)
})
