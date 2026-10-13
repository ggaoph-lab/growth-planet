import { api } from '@/shared/api/client'

/** Kid-friendly read-aloud: backend neural TTS first, browser voice last. */

let currentAudio: HTMLAudioElement | null = null
let currentObjectUrl: string | null = null
let speakEpoch = 0

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
      currentAudio.removeAttribute('src')
      currentAudio.load()
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

/** Generate lines in the background so a tap can play without waiting on the voice service. */
export function warmPhrases(phrases: string[], lang = 'zh-CN'): void {
  const texts = [...new Set(phrases.map((phrase) => phrase.trim()).filter(Boolean))].filter((phrase) => !preparedAudio.has(audioKey(phrase, lang))).slice(0, 8)
  if (!texts.length) return
  void api
    .post<{ items: Array<{ text: string; audio_base64: string | null; mime: string | null }> }>('/kid/tts/warm', { texts, lang })
    .then((data) => {
      for (const item of data.items ?? []) {
        if (item.text && item.audio_base64 && item.mime) rememberAudio(audioKey(item.text, lang), item.audio_base64, item.mime)
      }
    })
    .catch(() => {
      /* a tap still calls /kid/tts */
    })
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
  const audio = new Audio()
  currentAudio = audio
  audio.preload = 'auto'
  audio.src = objectUrl

  try {
    await audio.play()
  } catch {
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
    await playBlob(base64ToBlob(ready.base64, mime), epoch)
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
      // Got neural audio — do not fall back to robotic system voice on play glitches.
      if (ok || epoch !== speakEpoch) return
      return
    }
  } catch {
    // fall through to browser only when API itself failed
  }

  if (epoch !== speakEpoch) return
  await speakBrowser(text, browserLang, epoch)
}
