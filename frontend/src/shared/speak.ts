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
/** Minimal valid silent WAV — must play under a gesture to unlock WebKit media. */
const SILENT_WAV =
  'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA'

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

function primeSpeechSynthesis(): void {
  try {
    if (!window.speechSynthesis) return
    window.speechSynthesis.resume()
    const utter = new SpeechSynthesisUtterance(' ')
    utter.volume = 0
    utter.rate = 10
    window.speechSynthesis.speak(utter)
    window.speechSynthesis.cancel()
  } catch {
    /* ignore */
  }
}

/**
 * Call during a user gesture (tap/click). Mobile Chrome and iPad Safari block
 * HTMLAudioElement.play() / speechSynthesis until media is unlocked this way.
 * After unlock, later async TTS (post-fetch / setTimeout) can play on the shared element.
 */
export function unlockSpeak(): Promise<boolean> {
  if (typeof window === 'undefined' || typeof Audio === 'undefined') return Promise.resolve(false)
  if (audioUnlocked && sharedAudio) return Promise.resolve(true)
  if (unlockInFlight) return unlockInFlight

  unlockInFlight = (async () => {
    try {
      const audio = getSharedAudio()
      primeSpeechSynthesis()
      // Also resume Web Audio — helps iPad when HTMLAudio alone is flaky.
      try {
        const AC =
          window.AudioContext ||
          (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
        if (AC) {
          const ctx = new AC()
          void ctx.resume()
          const buffer = ctx.createBuffer(1, 1, 22050)
          const source = ctx.createBufferSource()
          source.buffer = buffer
          source.connect(ctx.destination)
          source.start(0)
        }
      } catch {
        /* ignore */
      }
      // play() must be invoked synchronously inside the gesture (before first await yields).
      audio.muted = true
      audio.src = SILENT_WAV
      const playResult = audio.play()
      try {
        await playResult
      } catch {
        audio.muted = false
        return false
      }
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

/** Generate lines in the background so a tap can play without waiting on the voice service. */
export function warmPhrases(phrases: string[], lang = 'zh-CN'): void {
  const texts = [...new Set(phrases.map((phrase) => phrase.trim()).filter(Boolean))].filter(
    (phrase) => !preparedAudio.has(audioKey(phrase, lang)),
  )
  if (!texts.length) return

  for (let i = 0; i < texts.length; i += WARM_CHUNK) {
    const chunk = texts.slice(i, i + WARM_CHUNK)
    void api
      .post<{ items: Array<{ text: string; audio_base64: string | null; mime: string | null }> }>('/kid/tts/warm', {
        texts: chunk,
        lang,
      })
      .then((data) => {
        for (const item of data.items ?? []) {
          if (item.text && item.audio_base64 && item.mime) rememberAudio(audioKey(item.text, lang), item.audio_base64, item.mime)
        }
      })
      .catch(() => {
        /* a tap still calls /kid/tts */
      })
  }
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

function speakBrowser(text: string, lang: string, epoch: number): Promise<void> {
  return new Promise((resolve) => {
    if (!window.speechSynthesis || epoch !== speakEpoch) {
      resolve()
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
    const finish = () => {
      if (settled) return
      settled = true
      window.clearInterval(watch)
      resolve()
    }

    const watch = window.setInterval(() => {
      if (epoch !== speakEpoch) {
        window.speechSynthesis.cancel()
        finish()
      }
    }, 120)

    utter.onend = () => finish()
    utter.onerror = () => finish()
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

async function playBlob(blob: Blob, epoch: number): Promise<boolean> {
  if (epoch !== speakEpoch) return false
  haltPlayback()

  const objectUrl = URL.createObjectURL(blob)
  currentObjectUrl = objectUrl
  // Always reuse the DOM-mounted element so iPad/Chrome keep the gesture unlock.
  const audio = typeof document !== 'undefined' ? getSharedAudio() : new Audio()
  currentAudio = audio
  audio.muted = false
  audio.src = objectUrl

  try {
    await audio.play()
    audioUnlocked = true
  } catch (err) {
    const blocked = err instanceof DOMException && err.name === 'NotAllowedError'
    if (blocked) audioUnlocked = false
    if (currentAudio === audio) currentAudio = null
    revokeObjectUrl()
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
export function speakPhrase(text: string, lang = 'zh-CN'): Promise<void> {
  return speakPrepared(text.trim(), lang, lang)
}

export async function speakTask(title: string, description?: string | null, lang = 'zh-CN'): Promise<void> {
  const text = taskPhrase(title, description)
  if (!text) return
  // Task copy is Chinese even when the UI locale is English, so the neural voice stays zh-CN.
  await speakPrepared(text, lang, 'zh-CN')
}

async function speakPrepared(text: string, browserLang: string, apiLang: string): Promise<void> {
  if (!text) return

  speakEpoch += 1
  const epoch = speakEpoch
  haltPlayback()

  const ready = preparedAudio.get(audioKey(text, apiLang))
  if (ready) {
    const mime = ready.mime.split(';')[0]?.trim() || 'audio/mpeg'
    const ok = await playBlob(base64ToBlob(ready.base64, mime), epoch)
    if (ok || epoch !== speakEpoch) return
    // Autoplay blocked (common on first mobile load) — try browser voice as last resort.
    if (epoch !== speakEpoch) return
    await speakBrowser(text, browserLang, epoch)
    return
  }

  try {
    const result = await api.post<{
      available: boolean
      audio_base64: string | null
      mime: string | null
      provider: string | null
    }>('/kid/tts', { text, lang: apiLang })

    if (epoch !== speakEpoch) return

    if (result.available && result.audio_base64 && result.mime) {
      rememberAudio(audioKey(text, apiLang), result.audio_base64, result.mime)
      const mime = result.mime.split(';')[0]?.trim() || 'audio/mpeg'
      const blob = base64ToBlob(result.audio_base64, mime)
      const ok = await playBlob(blob, epoch)
      // Neural audio played, or a newer speak superseded us.
      if (ok || epoch !== speakEpoch) return
      // Play blocked after a successful TTS fetch — fall through to browser voice.
    }
  } catch {
    // fall through to browser only when API itself failed
  }

  if (epoch !== speakEpoch) return
  await speakBrowser(text, browserLang, epoch)
}
