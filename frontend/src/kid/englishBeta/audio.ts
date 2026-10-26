/** Qwen3 ASR accepts WAV and WebM; Safari commonly records MP4/M4A. */
export async function prepareVoiceUpload(audio: Blob, mime: string): Promise<{ blob: Blob; filename: string }> {
  if (mime !== 'audio/mp4') return { blob: audio, filename: 'speech.webm' }

  const context = new AudioContext()
  let decoded: AudioBuffer
  try {
    decoded = await context.decodeAudioData(await audio.arrayBuffer())
  } finally {
    await context.close()
  }
  const sampleRate = 16000
  const frames = Math.ceil(decoded.duration * sampleRate)
  const offline = new OfflineAudioContext(1, frames, sampleRate)
  const source = offline.createBufferSource()
  source.buffer = decoded
  source.connect(offline.destination)
  source.start()
  const samples = (await offline.startRendering()).getChannelData(0)
  const bytes = new ArrayBuffer(44 + samples.length * 2)
  const view = new DataView(bytes)
  const ascii = (offset: number, value: string) => {
    for (let i = 0; i < value.length; i++) view.setUint8(offset + i, value.charCodeAt(i))
  }
  ascii(0, 'RIFF'); view.setUint32(4, bytes.byteLength - 8, true); ascii(8, 'WAVEfmt ')
  view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true)
  view.setUint32(24, sampleRate, true); view.setUint32(28, sampleRate * 2, true)
  view.setUint16(32, 2, true); view.setUint16(34, 16, true)
  ascii(36, 'data'); view.setUint32(40, samples.length * 2, true)
  samples.forEach((sample, index) => view.setInt16(44 + index * 2, Math.round(Math.max(-1, Math.min(1, sample)) * 32767), true))
  return { blob: new Blob([bytes], { type: 'audio/wav' }), filename: 'speech.wav' }
}
