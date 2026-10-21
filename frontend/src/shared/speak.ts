import { api } from '@/shared/api/client'

/** Kid-friendly read-aloud: backend neural TTS first, browser voice last. */

let currentAudio: HTMLAudioElement | null = null
let currentObjectUrl: string | null = null
let speakEpoch = 0
/**
 * Shared element unlocked during a user gesture so async TTS can play on
 * mobile Chrome and iPad/iOS Safari (WebKit autoplay is stricter).
 */
let sharedAudio: HTMLAudioElement | null = null
let audioUnlocked = false
let unlockInFlight: Promise<boolean> | null = null
/** Short unmuted-policy-checked lead-in wakes the output before a brief first word. */
function silentLeadIn(): string {
  const samples = 4410 // 200ms at 22.05kHz, mono PCM16
  const bytes = new Uint8Array(44 + samples * 2)
  const view = new DataView(bytes.buffer)
  const ascii = (offset: number, value: string) => { for (let i = 0; i < value.length; i++) bytes[offset + i] = value.charCodeAt(i) }
  ascii(0, 'RIFF'); view.setUint32(4, bytes.length - 8, true); ascii(8, 'WAVEfmt ')
  view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true)
  view.setUint32(24, 22050, true); view.setUint32(28, 44100, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true)
  ascii(36, 'data'); view.setUint32(40, samples * 2, true)
  return 'data:audio/wav;base64,' + btoa(String.fromCharCode(...bytes))
}
const SILENT_WAV = silentLeadIn()

function ensureVoices(): void {
  if (typeof window === 'undefined' || !window.speechSynthesis) return
  const warm = () => {
    void window.speechSynthesis.getVoices()
  }
  warm()
  window.speechSynthesis.addEventListener('voiceschanged', warm, { once: true })
}

ensureVoices()

export function canSpeak(): boolean {
  return typeof window !== 'undefined' && (typeof window.speechSynthesis !== 'undefined' || typeof Audio !== 'undefined')
}

export function isSpeakUnlocked(): boolean {
  return audioUnlocked
}

function getSharedAudio(): HTMLAudioElement {
  if (!sharedAudio) {
    // iPad/iOS: keep the element in the DOM with playsinline so unlock survives async TTS.
    const audio = document.createElement('audio')
    audio.setAttribute('playsinline', 'true')
    audio.setAttribute('webkit-playsinline', 'true')
    audio.preload = 'auto'
    audio.setAttribute('aria-hidden', 'true')
    Object.assign(audio.style, {
      position: 'fixed',
      width: '1px',
      height: '1px',
      opacity: '0',
      pointerEvents: 'none',
      left: '0',
      bottom: '0',
    })
    document.body.appendChild(audio)
    sharedAudio = audio
  }
  return sharedAudio
}

/** Prepare the same output element without ever treating muted autoplay as permission. */
export function unlockSpeak(): Promise<boolean> {
  if (typeof window === 'undefined' || typeof Audio === 'undefined') return Promise.resolve(false)
  if (audioUnlocked && sharedAudio) return Promise.resolve(true)
  if (unlockInFlight) return unlockInFlight

  unlockInFlight = (async () => {
    try {
      const audio = getSharedAudio()
      audio.muted = false
      audio.defaultMuted = false
      audio.volume = 1
      audio.src = SILENT_WAV
      const playResult = audio.play()
      try {
        await playResult
      } catch {
        audio.muted = false
        return false
      }
      // Keep the output active briefly; otherwise a short first word can be lost as it opens.
      await new Promise<void>(resolve => window.setTimeout(resolve, 150))
      audio.pause()
      try {
        audio.currentTime = 0
      } catch {
        /* ignore */
      }
      audio.muted = false
      audioUnlocked = true
      return true
    } catch {
      return false
    } finally {
      unlockInFlight = null
    }
  })()

  return unlockInFlight
}

function revokeObjectUrl(): void {
  if (currentObjectUrl) {
    URL.revokeObjectURL(currentObjectUrl)
    currentObjectUrl = null
  }
}

function haltPlayback(): void {
  if (typeof window !== 'undefined' && window.speechSynthesis) {
    window.speechSynthesis.cancel()
  }
  if (currentAudio) {
    try {
      currentAudio.pause()
      // Keep the shared DOM element; clearing src + load() can drop iOS unlock.
      if (currentAudio !== sharedAudio) {
        currentAudio.removeAttribute('src')
        currentAudio.load()
      }
    } catch {
      /* ignore */
    }
    currentAudio = null
  }
  revokeObjectUrl()
}

/** Stop current/queued speech. Invalidates any in-flight speakTask. */
export function stopSpeaking(): void {
  speakEpoch += 1
  haltPlayback()
}

export function taskPhrase(title: string, description?: string | null): string {
  const parts = [title.trim()]
  const detail = typeof description === 'string' ? description.trim() : ''
  if (detail) parts.push(detail)
  return parts.filter(Boolean).join('。')
}

const preparedAudio = new Map<string, { base64: string; mime: string }>()

function rememberAudio(text: string, base64: string, mime: string): void {
  if (!text || !base64) return
  preparedAudio.set(text, { base64, mime })
}

function audioKey(text: string, lang: string): string {
  return `${lang}\0${text}`
}

const WARM_CHUNK = 8
const pendingWarm = new Set<string>()
const warmQueue: Array<{ texts: string[]; lang: string }> = []
let warming = 0

function drainWarmQueue(): void {
  while (warming < 2 && warmQueue.length) {
    const job = warmQueue.shift()!
    warming += 1
    void api.post<{ items: Array<{ text: string; audio_base64: string | null; mime: string | null }> }>('/kid/tts/warm', {
      texts: job.texts, lang: job.lang,
    }).then((data) => {
      for (const item of data.items ?? []) {
        if (item.text && item.audio_base64 && item.mime) rememberAudio(audioKey(item.text, job.lang), item.audio_base64, item.mime)
      }
    }).catch(() => {
      /* A tap can still request audio if background warming fails. */
    }).finally(() => {
      for (const text of job.texts) pendingWarm.delete(audioKey(text, job.lang))
      warming -= 1
      drainWarmQueue()
    })
  }
}

/** Deduplicate queued/in-flight lines and leave room for interactive playback requests. */
export function warmPhrases(phrases: string[], lang = 'zh-CN'): void {
  const texts = [...new Set(phrases.map((phrase) => phrase.trim()).filter(Boolean))].filter(
    (phrase) => !preparedAudio.has(audioKey(phrase, lang)) && !pendingWarm.has(audioKey(phrase, lang)),
  )
  for (let i = 0; i < texts.length; i += WARM_CHUNK) {
    const chunk = texts.slice(i, i + WARM_CHUNK)
    for (const text of chunk) pendingWarm.add(audioKey(text, lang))
    warmQueue.push({ texts: chunk, lang })
  }
  drainWarmQueue()
}

function pickVoice(lang: string): SpeechSynthesisVoice | null {
  if (!window.speechSynthesis) return null
  const voices = window.speechSynthesis.getVoices()
  if (!voices.length) return null

  const langBase = lang.slice(0, 2).toLowerCase()
  const pool = voices.filter(
    (v) => v.lang.toLowerCase().startsWith(langBase) || v.lang.toLowerCase().startsWith(lang.toLowerCase()),
  )
  if (!pool.length) return null
  const candidates = pool

  const score = (v: SpeechSynthesisVoice): number => {
    const n = `${v.name} ${v.voiceURI}`.toLowerCase()
    let s = 0
    if (/neural|natural|online|premium|enhanced|wavenet|studio|google|microsoft/.test(n)) s += 50
    if (/xiaoxiao|xiaoyi|yunxi|yunyang|yunjian|yunxia|xiaochen|xiaomo|huihui|tingting|meijia|sinji|hanhan|cherry|serena/.test(n)) {
      s += 40
    }
    if (v.localService === false) s += 20
    if (v.lang.toLowerCase() === lang.toLowerCase()) s += 15
    if (v.lang.toLowerCase().startsWith(langBase)) s += 8
    if (/compact|eloquence|novelty|whisper|zarvox|bad|robot/.test(n)) s -= 30
    return s
  }

  return [...candidates].sort((a, b) => score(b) - score(a))[0] ?? null
}

function speakBrowser(text: string, lang: string, epoch: number, onStarted?: () => void): Promise<boolean> {
  return new Promise((resolve) => {
    if (!window.speechSynthesis || epoch !== speakEpoch) {
      resolve(false)
      return
    }
    haltPlayback()
    const utter = new SpeechSynthesisUtterance(text)
    utter.lang = lang
    utter.rate = 0.88
    utter.pitch = 1.0
    const voice = pickVoice(lang)
    if (voice) {
      utter.voice = voice
      utter.lang = voice.lang || lang
    }

    let settled = false
    const finish = (played: boolean) => {
      if (settled) return
      settled = true
      window.clearInterval(watch)
      resolve(played)
    }

    const watch = window.setInterval(() => {
      if (epoch !== speakEpoch) {
        window.speechSynthesis.cancel()
        finish(false)
      }
    }, 120)

    utter.onstart = () => { if (epoch === speakEpoch) onStarted?.() }
    utter.onend = () => finish(true)
    utter.onerror = () => finish(false)
    window.speechSynthesis.speak(utter)
  })
}

function base64ToBlob(base64: string, mime: string): Blob {
  const binary = atob(base64)
  const len = binary.length
  const bytes = new Uint8Array(len)
  for (let i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i)
  return new Blob([bytes], { type: mime })
}

class AutoplayBlockedError extends Error {}

async function playBlob(blob: Blob, epoch: number, onStarted?: () => void): Promise<boolean> {
  if (epoch !== speakEpoch) return false
  haltPlayback()

  const objectUrl = URL.createObjectURL(blob)
  currentObjectUrl = objectUrl
  // Always reuse the DOM-mounted element so iPad/Chrome keep the gesture unlock.
  const audio = typeof document !== 'undefined' ? getSharedAudio() : new Audio()
  currentAudio = audio
  audio.muted = false
  audio.defaultMuted = false
  audio.volume = 1
  audio.onplaying = () => {
    if (epoch === speakEpoch && !audio.muted && audio.volume > 0) onStarted?.()
  }
  audio.src = objectUrl

  try {
    await audio.play()
    audioUnlocked = true
  } catch (err) {
    const blocked = err instanceof DOMException && err.name === 'NotAllowedError'
    if (blocked) audioUnlocked = false
    if (currentAudio === audio) currentAudio = null
    revokeObjectUrl()
    if (blocked) throw new AutoplayBlockedError()
    return false
  }

  if (epoch !== speakEpoch) {
    haltPlayback()
    return false
  }

  let endedClean = false
  await new Promise<void>((resolve) => {
    let settled = false
    const finish = (ok: boolean) => {
      if (settled) return
      settled = true
      endedClean = ok
      window.clearInterval(watch)
      resolve()
    }
    const watch = window.setInterval(() => {
      if (epoch !== speakEpoch) {
        haltPlayback()
        finish(false)
      }
    }, 120)
    audio.onended = () => finish(true)
    audio.onerror = () => finish(false)
  })

  if (currentAudio === audio) currentAudio = null
  revokeObjectUrl()
  return endedClean && epoch === speakEpoch
}

/** Speak one line. `lang` selects the neural voice; browser speech is only the fallback. */
export function speakPhrase(text: string, lang = 'zh-CN', onStarted?: () => void): Promise<boolean> {
  return speakPrepared(text.trim(), lang, lang, onStarted)
}

export async function speakTask(title: string, description?: string | null, lang = 'zh-CN'): Promise<void> {
  const text = taskPhrase(title, description)
  if (!text) return
  await speakPrepared(text, lang, 'zh-CN')
}

async function speakPrepared(text: string, browserLang: string, apiLang: string, onStarted?: () => void): Promise<boolean> {
  if (!text) return false
  // Silent media priming must finish before the shared element receives real audio.
  // Otherwise its delayed pause/reset can stop the first audible word.
  const epoch = ++speakEpoch
  if (unlockInFlight) await unlockInFlight
  if (epoch !== speakEpoch) return false
  haltPlayback()

  try {
    let ready = preparedAudio.get(audioKey(text, apiLang))
    if (!ready) {
      const result = await api.post<{
        available: boolean; audio_base64: string | null; mime: string | null; provider: string | null
      }>('/kid/tts', { text, lang: apiLang })
      if (epoch !== speakEpoch) return false
      if (result.available && result.audio_base64 && result.mime) {
        rememberAudio(audioKey(text, apiLang), result.audio_base64, result.mime)
        ready = preparedAudio.get(audioKey(text, apiLang))
      }
    }
    if (ready) {
      if (!audioUnlocked && !await unlockSpeak()) return false
      if (epoch !== speakEpoch) return false
      const mime = ready.mime.split(';')[0]?.trim() || 'audio/mpeg'
      const played = await playBlob(base64ToBlob(ready.base64, mime), epoch, onStarted)
      if (played || epoch !== speakEpoch) return played
    }
  } catch (error) {
    // Browser autoplay denial is actionable; don't silently queue another blocked voice.
    if (error instanceof AutoplayBlockedError) return false
  }
  if (epoch !== speakEpoch) return false
  return speakBrowser(text, browserLang, epoch, onStarted)
}
