import { api } from '@/shared/api/client'

/** Kid-friendly read-aloud: backend neural TTS first, browser voice last. */

let currentAudio: HTMLAudioElement | null = null
let currentObjectUrl: string | null = null
let currentBufferSource: AudioBufferSourceNode | null = null
let rateWatch: ReturnType<typeof setInterval> | null = null
let speakEpoch = 0
/**
 * Shared element unlocked during a user gesture so async TTS can play on
 * mobile Chrome and iPad/iOS Safari (WebKit autoplay is stricter).
 */
let sharedAudio: HTMLAudioElement | null = null
let audioCtx: AudioContext | null = null
let audioUnlocked = false
let unlockInFlight: Promise<boolean> | null = null

function clampPlaybackRate(rate: number): number {
  if (!Number.isFinite(rate) || rate <= 0) return 1
  return Math.min(2, Math.max(0.5, rate))
}

function getAudioContext(): AudioContext | null {
  if (typeof window === 'undefined') return null
  const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Ctx) return null
  if (!audioCtx || audioCtx.state === 'closed') {
    audioCtx = new Ctx()
  }
  return audioCtx
}

async function resumeAudioContext(): Promise<void> {
  const ctx = getAudioContext()
  if (!ctx) return
  if (ctx.state === 'suspended') {
    try {
      await ctx.resume()
    } catch {
      /* ignore */
    }
  }
}
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
      await resumeAudioContext()
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

function clearRateWatch(): void {
  if (rateWatch) {
    clearInterval(rateWatch)
    rateWatch = null
  }
}

function haltPlayback(): void {
  if (typeof window !== 'undefined' && window.speechSynthesis) {
    window.speechSynthesis.cancel()
  }
  clearRateWatch()
  if (currentBufferSource) {
    try {
      currentBufferSource.onended = null
      currentBufferSource.stop()
    } catch {
      /* ignore */
    }
    try {
      currentBufferSource.disconnect()
    } catch {
      /* ignore */
    }
    currentBufferSource = null
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
/** Keep one speak engine per language so partner lines don't switch "people" mid-session. */
const engineLock = new Map<string, 'neural' | 'browser'>()
const stickyBrowserVoice = new Map<string, SpeechSynthesisVoice>()

function rememberAudio(text: string, base64: string, mime: string): void {
  if (!text || !base64) return
  preparedAudio.set(text, { base64, mime })
}

function forgetPreparedForLang(lang: string): void {
  const prefix = `${lang}\0`
  for (const key of [...preparedAudio.keys()]) {
    if (key.startsWith(prefix)) preparedAudio.delete(key)
  }
}

function lockBrowserEngine(lang: string): void {
  if (engineLock.get(lang) === 'browser') return
  engineLock.set(lang, 'browser')
  // Drop neural clips so a cached greeting doesn't keep a different voice.
  forgetPreparedForLang(lang)
}

/** Drop in-memory TTS blobs (settings “clear cache”). Playback unlock is kept. */
export function clearSpeakCache(): void {
  stopSpeaking()
  preparedAudio.clear()
  engineLock.clear()
  stickyBrowserVoice.clear()
  pendingWarm.clear()
  warmInFlight.clear()
  warmQueue.length = 0
  warming = 0
}

/**
 * Decide neural vs browser once for a language before the child hears mixed voices.
 * Call with a short sample (e.g. scene greeting) when a kid page opens.
 */
export async function probeSpeakEngine(sampleText: string, lang = 'en-US'): Promise<'neural' | 'browser'> {
  const text = sampleText.trim()
  const locked = engineLock.get(lang)
  if (locked) return locked
  if (!text) {
    lockBrowserEngine(lang)
    return 'browser'
  }
  try {
    const audio = await fetchNeuralAudio(text, lang)
    if (audio) {
      engineLock.set(lang, 'neural')
      return 'neural'
    }
  } catch {
    /* fall through */
  }
  lockBrowserEngine(lang)
  return 'browser'
}

function audioKey(text: string, lang: string): string {
  return `${lang}\0${text}`
}

const WARM_CHUNK = 8
const pendingWarm = new Set<string>()
/** Queued/in-flight warm work so speak can await a single-line warm instead of racing /kid/tts. */
const warmInFlight = new Map<string, { promise: Promise<void>; size: number }>()
type WarmJob = { texts: string[]; lang: string; settle: () => void }
const warmQueue: WarmJob[] = []
let warming = 0

function drainWarmQueue(): void {
  while (warming < 2 && warmQueue.length) {
    const job = warmQueue.shift()!
    warming += 1
    const keys = job.texts.map((text) => audioKey(text, job.lang))
    void api.post<{ items: Array<{ text: string; audio_base64: string | null; mime: string | null }> }>('/kid/tts/warm', {
      texts: job.texts, lang: job.lang,
    }).then((data) => {
      for (const item of data.items ?? []) {
        if (item.text && item.audio_base64 && item.mime) rememberAudio(audioKey(item.text, job.lang), item.audio_base64, item.mime)
      }
    }).catch(() => {
      /* A tap can still request audio if background warming fails. */
    }).finally(() => {
      for (const key of keys) {
        pendingWarm.delete(key)
        warmInFlight.delete(key)
      }
      job.settle()
      warming -= 1
      drainWarmQueue()
    })
  }
}

function enqueueWarm(phrases: string[], lang: string, priority: boolean): void {
  const texts = [...new Set(phrases.map((phrase) => phrase.trim()).filter(Boolean))].filter(
    (phrase) => !preparedAudio.has(audioKey(phrase, lang)) && !pendingWarm.has(audioKey(phrase, lang)),
  )
  // Priority chunks should stay in call order at the front of the queue.
  const chunks: string[][] = []
  for (let i = 0; i < texts.length; i += WARM_CHUNK) {
    chunks.push(texts.slice(i, i + WARM_CHUNK))
  }
  if (priority) chunks.reverse()
  for (const chunk of chunks) {
    let settle!: () => void
    const done = new Promise<void>((resolve) => { settle = resolve })
    for (const text of chunk) {
      const key = audioKey(text, lang)
      pendingWarm.add(key)
      warmInFlight.set(key, { promise: done, size: chunk.length })
    }
    if (priority) warmQueue.unshift({ texts: chunk, lang, settle })
    else warmQueue.push({ texts: chunk, lang, settle })
  }
  drainWarmQueue()
}

/** Deduplicate queued/in-flight lines and leave room for interactive playback requests. */
export function warmPhrases(phrases: string[], lang = 'zh-CN'): void {
  enqueueWarm(phrases, lang, false)
}

/** Prefetch the card the child is looking at first (ahead of the rest of the daily queue). */
export function warmPhrasesNow(phrases: string[], lang = 'zh-CN'): void {
  enqueueWarm(phrases, lang, true)
}

/**
 * Wait for a warm hit only when this line is alone in its job.
 * Multi-phrase batches hold the TTS lock too long — interactive taps must not sit behind them.
 */
async function awaitWarmIfPending(text: string, lang: string): Promise<void> {
  const pending = warmInFlight.get(audioKey(text, lang))
  if (!pending) return
  const maxMs = pending.size <= 1 ? 2_500 : 120
  await Promise.race([
    pending.promise,
    new Promise<void>((resolve) => { window.setTimeout(resolve, maxMs) }),
  ])
}

function pickVoice(lang: string): SpeechSynthesisVoice | null {
  if (!window.speechSynthesis) return null
  const voices = window.speechSynthesis.getVoices()
  if (!voices.length) return null

  const langBase = lang.slice(0, 2).toLowerCase()
  const sticky = stickyBrowserVoice.get(langBase)
  if (sticky && voices.some(v => v.voiceURI === sticky.voiceURI)) return sticky

  const pool = voices.filter(
    (v) => v.lang.toLowerCase().startsWith(langBase) || v.lang.toLowerCase().startsWith(lang.toLowerCase()),
  )
  if (!pool.length) return null
  const exact = pool.filter(v => v.lang.toLowerCase().replace('_', '-') === lang.toLowerCase())
  const candidates = lang.toLowerCase() === 'en-us' && exact.length ? exact : pool

  const score = (v: SpeechSynthesisVoice): number => {
    const n = `${v.name} ${v.voiceURI}`.toLowerCase()
    let s = 0
    if (/neural|natural|online|premium|enhanced|wavenet|studio|google|microsoft/.test(n)) s += 50
    if (/xiaoxiao|xiaoyi|yunxi|yunyang|yunjian|yunxia|xiaochen|xiaomo|huihui|tingting|meijia|sinji|hanhan|cherry|serena|samantha|karen|moira|tessa|veena|fiona/.test(n)) {
      s += 40
    }
    if (v.localService === false) s += 20
    if (v.lang.toLowerCase() === lang.toLowerCase()) s += 15
    if (v.lang.toLowerCase().startsWith(langBase)) s += 8
    if (/compact|eloquence|novelty|whisper|zarvox|bad|robot/.test(n)) s -= 30
    return s
  }

  const picked = [...candidates].sort((a, b) => score(b) - score(a))[0] ?? null
  if (picked) stickyBrowserVoice.set(langBase, picked)
  return picked
}

function speakBrowser(text: string, lang: string, epoch: number, onStarted?: () => void, rate = 1): Promise<boolean> {
  return new Promise((resolve) => {
    if (!window.speechSynthesis || epoch !== speakEpoch) {
      resolve(false)
      return
    }
    haltPlayback()
    const utter = new SpeechSynthesisUtterance(text)
    utter.lang = lang
    // Keep a gentle base so “正常” stays kid-friendly; slow/fast scale from the control.
    const clamped = clampPlaybackRate(rate)
    utter.rate = clampPlaybackRate(0.88 * clamped)
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

/** Prefer Web Audio — HTMLAudioElement.playbackRate is unreliable on the shared unlock element. */
async function playBlobViaWebAudio(blob: Blob, epoch: number, onStarted?: () => void, rate = 1): Promise<boolean | null> {
  const ctx = getAudioContext()
  if (!ctx) return null
  await resumeAudioContext()
  if (epoch !== speakEpoch) return false
  let audioBuffer: AudioBuffer
  try {
    const bytes = await blob.arrayBuffer()
    if (epoch !== speakEpoch) return false
    audioBuffer = await ctx.decodeAudioData(bytes.slice(0))
  } catch {
    return null
  }
  if (epoch !== speakEpoch) return false

  const clampedRate = clampPlaybackRate(rate)
  const source = ctx.createBufferSource()
  currentBufferSource = source
  source.buffer = audioBuffer
  source.playbackRate.value = clampedRate
  source.connect(ctx.destination)

  let endedClean = false
  const finished = new Promise<void>((resolve) => {
    let settled = false
    const finish = (ok: boolean) => {
      if (settled) return
      settled = true
      endedClean = ok
      window.clearInterval(watch)
      if (currentBufferSource === source) currentBufferSource = null
      resolve()
    }
    const watch = window.setInterval(() => {
      if (epoch !== speakEpoch) {
        try {
          source.stop()
        } catch {
          /* ignore */
        }
        finish(false)
      }
    }, 120)
    source.onended = () => finish(true)
  })

  try {
    onStarted?.()
    source.start(0)
    audioUnlocked = true
  } catch {
    if (currentBufferSource === source) currentBufferSource = null
    return null
  }

  await finished
  return endedClean && epoch === speakEpoch
}

async function playBlobViaElement(blob: Blob, epoch: number, onStarted?: () => void, rate = 1): Promise<boolean> {
  const objectUrl = URL.createObjectURL(blob)
  currentObjectUrl = objectUrl
  // Always reuse the DOM-mounted element so iPad/Chrome keep the gesture unlock.
  const audio = typeof document !== 'undefined' ? getSharedAudio() : new Audio()
  currentAudio = audio
  audio.muted = false
  audio.defaultMuted = false
  audio.volume = 1
  const clampedRate = clampPlaybackRate(rate)
  const applyRate = () => {
    try {
      audio.defaultPlaybackRate = clampedRate
      audio.playbackRate = clampedRate
      if ('preservesPitch' in audio) (audio as HTMLMediaElement & { preservesPitch: boolean }).preservesPitch = true
    } catch {
      /* ignore */
    }
  }
  audio.onplaying = () => {
    applyRate()
    if (epoch === speakEpoch && !audio.muted && audio.volume > 0) onStarted?.()
  }
  audio.src = objectUrl
  applyRate()
  clearRateWatch()
  rateWatch = setInterval(() => {
    if (epoch !== speakEpoch || currentAudio !== audio) {
      clearRateWatch()
      return
    }
    applyRate()
  }, 80)

  try {
    await audio.play()
    applyRate()
    audioUnlocked = true
  } catch (err) {
    const blocked = err instanceof DOMException && err.name === 'NotAllowedError'
    if (blocked) audioUnlocked = false
    if (currentAudio === audio) currentAudio = null
    clearRateWatch()
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
      clearRateWatch()
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

async function playBlob(blob: Blob, epoch: number, onStarted?: () => void, rate = 1): Promise<boolean> {
  if (epoch !== speakEpoch) return false
  haltPlayback()

  const viaCtx = await playBlobViaWebAudio(blob, epoch, onStarted, rate)
  if (viaCtx !== null) return viaCtx
  if (epoch !== speakEpoch) return false
  return playBlobViaElement(blob, epoch, onStarted, rate)
}

/** Speak one line. `lang` selects the neural voice; browser speech is only the fallback. */
export function speakPhrase(text: string, lang = 'zh-CN', onStarted?: () => void, rate = 1): Promise<boolean> {
  return speakPrepared(text.trim(), lang, lang, onStarted, rate)
}

export async function speakTask(title: string, description?: string | null, lang = 'zh-CN'): Promise<void> {
  const text = taskPhrase(title, description)
  if (!text) return
  await speakPrepared(text, lang, 'zh-CN')
}

/** Interactive taps should not wait on a slow upstream cascade (quota → Edge → Google). */
const NEURAL_SPEAK_BUDGET_MS = 1600
/** After neural already played once, wait longer so the next line keeps the same voice. */
const NEURAL_LOCKED_BUDGET_MS = 4500

async function fetchNeuralAudio(text: string, apiLang: string): Promise<{ base64: string; mime: string } | null> {
  const result = await api.post<{
    available: boolean; audio_base64: string | null; mime: string | null; provider: string | null
  }>('/kid/tts', { text, lang: apiLang })
  if (!result.available || !result.audio_base64 || !result.mime) return null
  if (engineLock.get(apiLang) === 'browser') return null
  rememberAudio(audioKey(text, apiLang), result.audio_base64, result.mime)
  return preparedAudio.get(audioKey(text, apiLang)) ?? null
}

async function speakPrepared(text: string, browserLang: string, apiLang: string, onStarted?: () => void, rate = 1): Promise<boolean> {
  if (!text) return false
  // Silent media priming must finish before the shared element receives real audio.
  // Otherwise its delayed pause/reset can stop the first audible word.
  const epoch = ++speakEpoch
  if (unlockInFlight) await unlockInFlight
  if (epoch !== speakEpoch) return false
  haltPlayback()

  if (engineLock.get(apiLang) === 'browser') {
    return speakBrowser(text, browserLang, epoch, onStarted, rate)
  }

  try {
    let ready = preparedAudio.get(audioKey(text, apiLang))
    if (!ready) {
      // Reuse a single-line warm already in flight; never enqueue a competing warm from a tap.
      await awaitWarmIfPending(text, apiLang)
      if (epoch !== speakEpoch) return false
      ready = preparedAudio.get(audioKey(text, apiLang))
    }
    if (!ready) {
      const budget = engineLock.get(apiLang) === 'neural' ? NEURAL_LOCKED_BUDGET_MS : NEURAL_SPEAK_BUDGET_MS
      const neural = fetchNeuralAudio(text, apiLang)
      const raced = await Promise.race([
        neural.then((audio) => ({ kind: 'ready' as const, audio })),
        new Promise<{ kind: 'timeout' }>((resolve) => {
          window.setTimeout(() => resolve({ kind: 'timeout' }), budget)
        }),
      ])
      if (epoch !== speakEpoch) return false
      if (raced.kind === 'ready' && raced.audio) {
        ready = raced.audio
      } else {
        void neural.catch(() => null)
        lockBrowserEngine(apiLang)
      }
    }
    if (ready) {
      if (!engineLock.has(apiLang)) engineLock.set(apiLang, 'neural')
      if (!audioUnlocked && !await unlockSpeak()) return false
      if (epoch !== speakEpoch) return false
      const mime = ready.mime.split(';')[0]?.trim() || 'audio/mpeg'
      const played = await playBlob(base64ToBlob(ready.base64, mime), epoch, onStarted, rate)
      if (played || epoch !== speakEpoch) return played
      // Neural playback failed after a prior neural success — stay consistent with browser.
      lockBrowserEngine(apiLang)
    }
  } catch (error) {
    // Browser autoplay denial is actionable; don't silently queue another blocked voice.
    if (error instanceof AutoplayBlockedError) return false
    lockBrowserEngine(apiLang)
  }
  if (epoch !== speakEpoch) return false
  lockBrowserEngine(apiLang)
  return speakBrowser(text, browserLang, epoch, onStarted, rate)
}
