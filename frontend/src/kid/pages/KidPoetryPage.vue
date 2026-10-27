<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import { prepareVoiceUpload } from '@/kid/englishBeta/audio'
import {
  chinesePinyinUnits,
  loadChinesePinyin,
  onChinesePinyinReady,
  type ChinesePinyinUnit,
} from '@/shared/chinesePinyin'
import { playTap } from '@/shared/sfx'
import { speakAudioUrl, speakPhrase, stopSpeaking, unlockSpeak } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

type ModeKey = 'required' | 'recommend' | 'challenge'
type PracticeKind = 'follow' | 'read' | 'recite'

type PoemCard = {
  id: number
  title: string
  author: string | null
  dynasty?: string | null
  preview?: string
  kind?: string
  due_date?: string | null
  reason?: string | null
  week_key?: string | null
  rec_status?: string | null
  suggested?: boolean
}

type HomeData = {
  date: string
  stats: { recited_pass: number; required: number; challenge_ready: number }
  modes: Array<{
    key: ModeKey
    count: number
    hint?: string
    week_key?: string
    status?: string
    poem?: PoemCard | null
  }>
}

type ModeData = {
  mode: ModeKey
  poems?: PoemCard[]
  current?: PoemCard | null
  history?: PoemCard[]
  suggested?: PoemCard | null
  week_key?: string
}

type PoemDetail = {
  poem: {
    id: number
    title: string
    author: string | null
    dynasty: string | null
    lines: string[]
    content: string
    grade_tag: string | null
  }
  story_zh: string
  author_zh: string
  author_learned_poems: Array<{ id: number; title: string }>
  line_meanings: string[]
  image: { url: string | null; provider: string; status: string; cached: boolean }
  voice_available: boolean
  follow_pause_ms: number
  progress: {
    read_aloud_done: boolean
    last_passed: boolean
    last_accuracy: number | null
    review_status: string | null
    next_review_date: string | null
    mastered: boolean
  }
}

type ScoreResult = {
  passed: boolean
  accuracy_percent: number
  feedback: string
  reward: { xp: number; points: number; capped: boolean } | null
  review: { status: string; next_review_date: string | null } | null
}

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const view = ref<'hub' | 'mode' | 'poem'>('hub')
const mode = ref<ModeKey | null>(null)
const home = ref<HomeData | null>(peekWarmGet<HomeData>('/kid/poetry') ?? null)
const modeData = ref<ModeData | null>(null)
const detail = ref<PoemDetail | null>(null)
const loading = ref(false)
const error = ref('')
const toast = ref('')
const challengeMode = ref(false)
const busy = ref(false)
const lastResult = ref<ScoreResult | null>(null)

const practice = ref<PracticeKind | null>(null)
const followIndex = ref(0)
const recording = ref(false)
const preparingVoice = ref(false)
const practiceHint = ref('')
const selectedLine = ref<number | null>(null)
const meaningText = ref('')
const poemBlurred = ref(false)
const mounted = ref(false)
/** Bumps when pinyin-pro finishes loading so ruby lines re-render. */
const pinyinTick = ref(0)
let offPinyinReady: (() => void) | null = null

let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let recordingTimer: ReturnType<typeof setTimeout> | null = null
let captureEpoch = 0
let discardRecording = false
let followAdvanceAfterStop = false
let scoreAfterStop: 'read_aloud' | 'recite' | 'challenge' | null = null
let speechSeq = 0

const recordingMime = typeof MediaRecorder !== 'undefined'
  ? ['audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type))
  : undefined
const micAvailable = typeof window !== 'undefined'
  && window.isSecureContext
  && Boolean(navigator.mediaDevices?.getUserMedia)
  && Boolean(recordingMime)

const metaLine = computed(() => {
  if (!detail.value) return ''
  const { author, dynasty } = detail.value.poem
  return [dynasty, author].filter(Boolean).join(' · ')
})

const pageTitle = computed(() => {
  if (view.value === 'poem' && detail.value) return detail.value.poem.title
  if (view.value === 'mode' && mode.value) return t(`kid.poetry.mode.${mode.value}.title`)
  return t('kid.poetry.title')
})

const practiceActive = computed(() => practice.value !== null)
const canUseVoice = computed(() => micAvailable && Boolean(detail.value?.voice_available))

function modeMeta(key: ModeKey) {
  return home.value?.modes.find((m) => m.key === key)
}

function modeBadge(key: ModeKey): string {
  const row = modeMeta(key)
  if (!row) return ''
  if (key === 'required') {
    return row.count > 0 ? t('kid.poetry.badgeCount', { n: row.count }) : t('kid.poetry.badgeEmpty')
  }
  if (key === 'recommend') {
    if (row.status === 'done') return t('kid.poetry.badgeDone')
    return row.poem ? t('kid.poetry.badgeThisWeek') : t('kid.poetry.badgeEmpty')
  }
  return row.count > 0 ? t('kid.poetry.badgeReady', { n: row.count }) : t('kid.poetry.badgeLocked')
}

async function loadHome(force = false): Promise<void> {
  const hadHome = home.value !== null
  if (!hadHome) loading.value = true
  error.value = ''
  try {
    home.value = await warmGet<HomeData>('/kid/poetry', undefined, { force })
    // Warm mode lists in the background so tapping an entry feels instant.
    void warmGet<ModeData>('/kid/poetry/required')
    void warmGet<ModeData>('/kid/poetry/recommend')
    void warmGet<ModeData>('/kid/poetry/challenge')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  } finally {
    loading.value = false
  }
}

async function openMode(key: ModeKey, force = false): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  mode.value = key
  const cached = force ? undefined : peekWarmGet<ModeData>(`/kid/poetry/${key}`)
  if (cached) {
    modeData.value = cached
    view.value = 'mode'
  } else {
    loading.value = true
  }
  error.value = ''
  try {
    modeData.value = await warmGet<ModeData>(`/kid/poetry/${key}`, undefined, { force })
    view.value = 'mode'
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  } finally {
    loading.value = false
  }
}

function resetPracticeState(): void {
  stopCapture()
  stopSpeaking()
  speechSeq++
  practice.value = null
  followIndex.value = 0
  practiceHint.value = ''
  poemBlurred.value = false
  followAdvanceAfterStop = false
  scoreAfterStop = null
}

async function openPoem(card: PoemCard, asChallenge = false): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  void unlockSpeak()
  challengeMode.value = asChallenge || mode.value === 'challenge'
  loading.value = true
  error.value = ''
  lastResult.value = null
  selectedLine.value = null
  meaningText.value = ''
  resetPracticeState()
  try {
    detail.value = await api.get<PoemDetail>(`/kid/poetry/poems/${card.id}`)
    view.value = 'poem'
    void loadChinesePinyin()
    // Safety-net prefetch; assign/recommend already warm the full static pack server-side.
    void warmPoemLibrary(detail.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  } finally {
    loading.value = false
  }
}

function poemLibraryClipsFor(d: PoemDetail): string[] {
  const clips = ['full', 'author']
  d.poem.lines.forEach((_, i) => {
    clips.push(`line-${i}`, `meaning-${i}`)
  })
  return clips
}

function warmPoemLibrary(d: PoemDetail): void {
  const clips = poemLibraryClipsFor(d)
  void api.post(`/kid/poetry/poems/${d.poem.id}/audio/warm`, { clips }).catch(() => null)
}

function back(): void {
  if (view.value === 'poem') {
    resetPracticeState()
    view.value = 'mode'
    detail.value = null
    // Force refresh — practice may have cleared an assignment / updated progress.
    if (mode.value) void openMode(mode.value, true)
    return
  }
  if (view.value === 'mode') {
    view.value = 'hub'
    mode.value = null
    modeData.value = null
    void loadHome(true)
    return
  }
  void router.push({ name: 'kid-home' })
}

type PoetryAudioClip = {
  clip: string
  url: string | null
  mime: string
  provider: string | null
  cached: boolean
  status: string
  script: string
}

async function fetchPoetryClip(clip: string): Promise<PoetryAudioClip | null> {
  if (!detail.value) return null
  try {
    return await api.get<PoetryAudioClip>(`/kid/poetry/poems/${detail.value.poem.id}/audio`, { clip })
  } catch {
    return null
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms))
}

/** Wait out a short busy lock so assign/open warm can finish writing ElevenLabs MP3s. */
async function fetchPoetryClipReady(clip: string, seq: number): Promise<PoetryAudioClip | null> {
  for (let attempt = 0; attempt < 4; attempt++) {
    if (seq !== speechSeq || !mounted.value) return null
    const audio = await fetchPoetryClip(clip)
    if (!audio) return null
    if (audio.status === 'ready' && audio.url) return audio
    if (audio.status !== 'busy') return audio
    await sleep(700)
  }
  return fetchPoetryClip(clip)
}

/** Play the configured poetry narrator consistently across all clips. */
async function playLibraryOrScript(
  seq: number,
  clip: string,
  fallbackText?: string,
): Promise<boolean> {
  if (seq !== speechSeq || !mounted.value) return false
  const audio = await fetchPoetryClipReady(clip, seq)
  if (seq !== speechSeq || !mounted.value) return false

  if (audio?.url && (audio.status === 'ready' || audio.cached)) {
    const ok = await speakAudioUrl(audio.url)
    if (ok && seq === speechSeq && mounted.value) return true
    if (seq === speechSeq && mounted.value) {
      toast.value = '音频已准备好，但浏览器未能播放。请再点一次朗读。'
    }
    return false
  }

  if (seq === speechSeq && mounted.value) {
    toast.value = audio?.status === 'busy'
      ? '诗词音频正在准备，请稍后再试。'
      : '诗词音频暂时无法生成，请让家长检查朗读设置。'
  }
  return false
}

async function playPoetryClip(clip: string, fallbackText?: string): Promise<boolean> {
  // Invalidate + halt before any await so a second tap never stacks audio.
  const seq = ++speechSeq
  stopSpeaking()
  await unlockSpeak()
  if (seq !== speechSeq || !mounted.value) return false
  return playLibraryOrScript(seq, clip, fallbackText)
}

async function speakText(text: string): Promise<boolean> {
  const line = text.trim()
  if (!line) return false
  const seq = ++speechSeq
  stopSpeaking()
  await unlockSpeak()
  if (seq !== speechSeq || !mounted.value) return false
  const ok = await speakPhrase(line, 'zh-CN')
  return seq === speechSeq && mounted.value && ok
}

function speakPoem(): void {
  if (!detail.value || practiceActive.value) return
  void playPoetryClip('full')
}

function speakLine(index: number): void {
  if (!detail.value || practiceActive.value) return
  const line = detail.value.poem.lines[index]
  if (!line) return
  void playPoetryClip(`line-${index}`, line)
}

function speakStory(): void {
  if (!detail.value || practiceActive.value) return
  void playPoetryClip('story', detail.value.story_zh)
}

function speakAuthor(): void {
  if (!detail.value?.author_zh || practiceActive.value) return
  void playPoetryClip('author', detail.value.author_zh)
}

function speakMeaning(): void {
  if (!detail.value || !meaningText.value || practiceActive.value) return
  const idx = selectedLine.value
  if (idx !== null) {
    void playPoetryClip(`meaning-${idx}`, meaningText.value)
    return
  }
  void speakText(meaningText.value)
}

async function selectLine(index: number): Promise<void> {
  if (!detail.value || practiceActive.value) return
  if (session.me?.child?.preferences.sounds) playTap()
  const line = detail.value.poem.lines[index]
  if (!line) return
  selectedLine.value = index
  const meaning = detail.value.line_meanings[index]
    || t('kid.poetry.meaningFallback', { line })
  meaningText.value = meaning

  const seq = ++speechSeq
  stopSpeaking()
  await unlockSpeak()
  if (seq !== speechSeq || !mounted.value) return

  // Always attempt meaning after the line — do not abort when the line clip misses.
  await playLibraryOrScript(seq, `line-${index}`, line)
  if (seq !== speechSeq || !mounted.value) return
  await playLibraryOrScript(seq, `meaning-${index}`, meaning)
}

function linePinyinUnits(text: string): ChinesePinyinUnit[] {
  void pinyinTick.value
  return chinesePinyinUnits(text)
}

function releaseMicrophone(): void {
  if (recordingTimer) clearTimeout(recordingTimer)
  recordingTimer = null
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
  recording.value = false
}

function stopCapture(): void {
  captureEpoch++
  preparingVoice.value = false
  discardRecording = true
  followAdvanceAfterStop = false
  scoreAfterStop = null
  if (recorder?.state === 'recording') recorder.stop()
  releaseMicrophone()
}

async function startRecording(opts: {
  maxMs: number
  onBlob: (blob: Blob, mime: string, durationMs: number) => Promise<void>
}): Promise<boolean> {
  if (!canUseVoice.value || recording.value || preparingVoice.value || busy.value) return false
  const epoch = ++captureEpoch
  preparingVoice.value = true
  discardRecording = false
  stopSpeaking()
  void unlockSpeak()
  try {
    const acquired = await navigator.mediaDevices.getUserMedia({ audio: true })
    if (!mounted.value || epoch !== captureEpoch) {
      acquired.getTracks().forEach((track) => track.stop())
      return false
    }
    preparingVoice.value = false
    stream = acquired
    const mimeType = recordingMime
    if (!mimeType) throw new Error('Unsupported audio format')
    const chunks: Blob[] = []
    recorder = new MediaRecorder(stream, { mimeType })
    const startedAt = Date.now()
    recorder.ondataavailable = (event) => {
      if (event.data.size) chunks.push(event.data)
    }
    recorder.onstop = async () => {
      releaseMicrophone()
      if (discardRecording || !mounted.value || epoch !== captureEpoch) return
      const audio = new Blob(chunks, { type: mimeType })
      const durationMs = Date.now() - startedAt
      if (durationMs < 400 || audio.size < 32) {
        toast.value = t('kid.poetry.recordTooShort')
        return
      }
      try {
        await opts.onBlob(audio, mimeType, durationMs)
      } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('kid.poetry.recordFail')
      }
    }
    recorder.onerror = () => {
      stopCapture()
      error.value = t('kid.poetry.recordFail')
    }
    recorder.start()
    recording.value = true
    recordingTimer = setTimeout(() => {
      if (recorder?.state === 'recording') recorder.stop()
    }, opts.maxMs)
    return true
  } catch {
    if (epoch !== captureEpoch) return false
    preparingVoice.value = false
    releaseMicrophone()
    error.value = t('kid.poetry.micDenied')
    return false
  }
}

function stopRecording(): void {
  if (recorder?.state === 'recording') recorder.stop()
}

async function transcribeAudio(audio: Blob, mime: string, durationMs: number): Promise<string> {
  if (!detail.value) throw new Error('no poem')
  const upload = await prepareVoiceUpload(audio, mime)
  const form = new FormData()
  form.append('audio', upload.blob, upload.filename)
  form.append('duration_ms', String(Math.min(60000, Math.max(300, durationMs))) )
  const result = await api.upload<{ text: string }>(
    `/kid/poetry/poems/${detail.value.poem.id}/transcribe`,
    form,
  )
  return result.text.trim()
}

async function submitScore(transcript: string, scoreMode: 'read_aloud' | 'recite' | 'challenge'): Promise<void> {
  if (!detail.value) return
  const text = transcript.trim()
  if (!text) {
    toast.value = t('kid.poetry.needTranscript')
    return
  }
  busy.value = true
  error.value = ''
  practiceHint.value = t('kid.poetry.scoring')
  try {
    lastResult.value = await api.post<ScoreResult>(`/kid/poetry/poems/${detail.value.poem.id}/recite`, {
      transcript: text,
      mode: scoreMode,
    })
    if (lastResult.value.passed) {
      if (scoreMode === 'read_aloud') detail.value.progress.read_aloud_done = true
      if (scoreMode === 'recite' || scoreMode === 'challenge') {
        detail.value.progress.last_passed = true
        detail.value.progress.last_accuracy = lastResult.value.accuracy_percent
        if (scoreMode === 'recite') detail.value.progress.mastered = true
        if (lastResult.value.review) {
          detail.value.progress.review_status = lastResult.value.review.status
          detail.value.progress.next_review_date = lastResult.value.review.next_review_date
        }
      }
      if (lastResult.value.reward && !lastResult.value.reward.capped) {
        toast.value = t('kid.poetry.reward', {
          xp: lastResult.value.reward.xp,
          points: lastResult.value.reward.points,
        })
      }
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  } finally {
    busy.value = false
    practice.value = null
    poemBlurred.value = false
    practiceHint.value = ''
  }
}

async function finishFollowLine(): Promise<void> {
  if (practice.value !== 'follow' || !detail.value) return
  if (recording.value) {
    followAdvanceAfterStop = true
    stopRecording()
    return
  }
  await advanceFollow()
}

async function advanceFollow(): Promise<void> {
  if (!detail.value || practice.value !== 'follow') return
  const next = followIndex.value + 1
  if (next >= detail.value.poem.lines.length) {
    busy.value = true
    try {
      const data = await api.postIdempotent<{
        already: boolean
        reward: { xp: number; points: number; capped: boolean } | null
      }>(`/kid/poetry/poems/${detail.value.poem.id}/read-aloud`, {})
      detail.value.progress.read_aloud_done = true
      if (data.reward && !data.reward.capped) {
        toast.value = t('kid.poetry.reward', { xp: data.reward.xp, points: data.reward.points })
      } else {
        toast.value = t('kid.poetry.followDone')
      }
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
    } finally {
      busy.value = false
      practice.value = null
      practiceHint.value = ''
      followIndex.value = 0
    }
    return
  }
  followIndex.value = next
  await runFollowStep()
}

async function runFollowStep(): Promise<void> {
  if (!detail.value || practice.value !== 'follow') return
  const line = detail.value.poem.lines[followIndex.value]
  practiceHint.value = t('kid.poetry.followListen', { n: followIndex.value + 1 })
  selectedLine.value = followIndex.value
  const ok = await playPoetryClip(`line-${followIndex.value}`, line)
  if (!ok || practice.value !== 'follow' || !mounted.value) return
  await sleep(Math.max(0, Math.min(5000, detail.value.follow_pause_ms || 0)))
  if (practice.value !== 'follow' || !mounted.value) return
  practiceHint.value = t('kid.poetry.followRecord')
  const started = await startRecording({
    maxMs: 20000,
    onBlob: async (blob, mime, durationMs) => {
      if (!followAdvanceAfterStop) {
        // Auto-stop: still advance so kids aren't stuck.
      }
      followAdvanceAfterStop = false
      if (canUseVoice.value) {
        try {
          await transcribeAudio(blob, mime, durationMs)
        } catch {
          /* follow-along is practice; continue even if STT fails */
        }
      }
      await advanceFollow()
    },
  })
  if (!started) {
    practiceHint.value = t('kid.poetry.followTapDone')
  }
}

function ensureVoiceReady(): boolean {
  if (canUseVoice.value) return true
  toast.value = t('kid.poetry.voiceNeeded')
  return false
}

async function startFollow(): Promise<void> {
  if (!detail.value || busy.value || practiceActive.value || challengeMode.value) return
  if (!ensureVoiceReady()) return
  if (session.me?.child?.preferences.sounds) playTap()
  lastResult.value = null
  practice.value = 'follow'
  followIndex.value = 0
  poemBlurred.value = false
  error.value = ''
  await runFollowStep()
}

async function startRead(): Promise<void> {
  if (!detail.value || busy.value || practiceActive.value || challengeMode.value) return
  if (!ensureVoiceReady()) return
  if (session.me?.child?.preferences.sounds) playTap()
  lastResult.value = null
  practice.value = 'read'
  poemBlurred.value = false
  error.value = ''
  practiceHint.value = t('kid.poetry.readListen')
  await playPoetryClip('full')
  if (practice.value !== 'read' || !mounted.value) return
  practiceHint.value = t('kid.poetry.readRecord')
  scoreAfterStop = 'read_aloud'
  const started = await startRecording({
    maxMs: 60000,
    onBlob: async (blob, mime, durationMs) => {
      const mode = scoreAfterStop
      scoreAfterStop = null
      if (!mode) return
      busy.value = true
      try {
        const text = await transcribeAudio(blob, mime, durationMs)
        await submitScore(text, mode)
      } catch (e) {
        busy.value = false
        practice.value = null
        practiceHint.value = ''
        error.value = e instanceof ApiError ? e.message : t('kid.poetry.recordFail')
      }
    },
  })
  if (!started) {
    practice.value = null
    practiceHint.value = ''
  }
}

async function startRecite(): Promise<void> {
  if (!detail.value || busy.value || practiceActive.value) return
  if (!ensureVoiceReady()) return
  if (session.me?.child?.preferences.sounds) playTap()
  lastResult.value = null
  practice.value = 'recite'
  poemBlurred.value = true
  error.value = ''
  practiceHint.value = t('kid.poetry.reciteRecord')
  await nextTick()
  scoreAfterStop = challengeMode.value ? 'challenge' : 'recite'
  const started = await startRecording({
    maxMs: 60000,
    onBlob: async (blob, mime, durationMs) => {
      const mode = scoreAfterStop
      scoreAfterStop = null
      if (!mode) return
      busy.value = true
      try {
        const text = await transcribeAudio(blob, mime, durationMs)
        await submitScore(text, mode)
      } catch (e) {
        busy.value = false
        practice.value = null
        poemBlurred.value = false
        practiceHint.value = ''
        error.value = e instanceof ApiError ? e.message : t('kid.poetry.recordFail')
      }
    },
  })
  if (!started) {
    practice.value = null
    poemBlurred.value = false
    practiceHint.value = ''
  }
}

function finishPracticeRecording(): void {
  if (practice.value === 'follow') {
    void finishFollowLine()
    return
  }
  if ((practice.value === 'read' || practice.value === 'recite') && recording.value) {
    practiceHint.value = t('kid.poetry.scoring')
    stopRecording()
  }
}

function cancelPractice(): void {
  resetPracticeState()
  practiceHint.value = ''
  toast.value = t('kid.poetry.practiceCancelled')
}

onMounted(() => {
  mounted.value = true
  offPinyinReady = onChinesePinyinReady(() => { pinyinTick.value += 1 })
  void loadChinesePinyin()
  void loadHome()
})

onBeforeUnmount(() => {
  mounted.value = false
  offPinyinReady?.()
  offPinyinReady = null
  resetPracticeState()
})
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="back">
        {{ view === 'hub' ? t('kid.backPlanet') : t('kid.poetry.back') }}
      </button>
      <h1>{{ pageTitle }}</h1>
      <span class="spacer" />
    </header>

    <p v-if="error" class="err" role="alert">{{ error }}</p>
    <p v-if="toast" class="toast">{{ toast }}</p>
    <p v-if="loading && !home && !detail && !modeData" class="hint">{{ t('common.loading') }}</p>

    <!-- Hub: three entries -->
    <section v-else-if="view === 'hub' && home" class="hub">
      <p class="lead">{{ t('kid.poetry.lead') }}</p>
      <p class="stats">{{ t('kid.poetry.statRecited', { n: home.stats.recited_pass }) }}</p>

      <div class="mode-grid">
        <button type="button" class="mode-card" @click="openMode('required')">
          <span class="emoji" aria-hidden="true">🏫</span>
          <span class="copy">
            <span class="name">{{ t('kid.poetry.mode.required.title') }}</span>
            <span class="blurb">{{ t('kid.poetry.mode.required.blurb') }}</span>
          </span>
          <span class="badge">{{ modeBadge('required') }}</span>
        </button>

        <button type="button" class="mode-card" @click="openMode('recommend')">
          <span class="emoji" aria-hidden="true">✨</span>
          <span class="copy">
            <span class="name">{{ t('kid.poetry.mode.recommend.title') }}</span>
            <span class="blurb">{{ t('kid.poetry.mode.recommend.blurb') }}</span>
          </span>
          <span class="badge">{{ modeBadge('recommend') }}</span>
        </button>

        <button
          type="button"
          class="mode-card"
          :class="{ dim: (modeMeta('challenge')?.count ?? 0) === 0 }"
          @click="openMode('challenge')"
        >
          <span class="emoji" aria-hidden="true">🏆</span>
          <span class="copy">
            <span class="name">{{ t('kid.poetry.mode.challenge.title') }}</span>
            <span class="blurb">{{ t('kid.poetry.mode.challenge.blurb') }}</span>
          </span>
          <span class="badge">{{ modeBadge('challenge') }}</span>
        </button>
      </div>
    </section>

    <!-- Mode lists -->
    <section v-else-if="view === 'mode' && modeData && mode === 'required'" class="list">
      <p class="lead">{{ t('kid.poetry.mode.required.lead') }}</p>
      <p v-if="!modeData.poems?.length" class="empty">{{ t('kid.poetry.mode.required.empty') }}</p>
      <button
        v-for="row in modeData.poems"
        :key="row.id"
        type="button"
        class="card"
        @click="openPoem(row)"
      >
        <span class="title">{{ row.title }}</span>
        <span class="sub">
          {{ row.author }}
          <template v-if="row.due_date"> · {{ t('kid.poetry.due', { date: row.due_date }) }}</template>
        </span>
        <span class="preview">{{ row.preview }}</span>
      </button>
    </section>

    <section v-else-if="view === 'mode' && modeData && mode === 'recommend'" class="list">
      <p class="lead">{{ t('kid.poetry.mode.recommend.lead') }}</p>
      <template v-if="modeData.current">
        <h2>{{ t('kid.poetry.thisWeek') }}</h2>
        <button type="button" class="card featured" @click="openPoem(modeData.current)">
          <span class="title">{{ modeData.current.title }}</span>
          <span class="sub">{{ modeData.current.author }} · {{ modeData.current.week_key }}</span>
          <span v-if="modeData.current.reason" class="reason">{{ modeData.current.reason }}</span>
          <span v-if="modeData.current.rec_status === 'done'" class="done">{{ t('kid.poetry.badgeDone') }}</span>
        </button>
      </template>
      <p v-else class="empty">{{ t('kid.poetry.mode.recommend.empty') }}</p>

      <template v-if="modeData.history?.length">
        <h2>{{ t('kid.poetry.history') }}</h2>
        <button
          v-for="row in modeData.history"
          :key="`${row.week_key}-${row.id}`"
          type="button"
          class="card"
          @click="openPoem(row)"
        >
          <span class="title">{{ row.title }}</span>
          <span class="sub">{{ row.week_key }} · {{ row.rec_status === 'done' ? t('kid.poetry.badgeDone') : t('kid.poetry.badgeThisWeek') }}</span>
        </button>
      </template>
    </section>

    <section v-else-if="view === 'mode' && modeData && mode === 'challenge'" class="list">
      <p class="lead">{{ t('kid.poetry.mode.challenge.lead') }}</p>
      <p v-if="!modeData.poems?.length" class="empty">{{ t('kid.poetry.mode.challenge.empty') }}</p>
      <template v-else>
        <button
          v-if="modeData.suggested"
          type="button"
          class="card featured"
          @click="openPoem(modeData.suggested, true)"
        >
          <span class="pill">{{ t('kid.poetry.todayChallenge') }}</span>
          <span class="title">{{ modeData.suggested.title }}</span>
          <span class="sub">{{ modeData.suggested.author }}</span>
        </button>
        <h2>{{ t('kid.poetry.pickChallenge') }}</h2>
        <button
          v-for="row in modeData.poems"
          :key="row.id"
          type="button"
          class="card"
          @click="openPoem(row, true)"
        >
          <span class="title">{{ row.title }}</span>
          <span class="sub">{{ row.author }}</span>
        </button>
      </template>
    </section>

    <!-- Poem studio layout -->
    <section v-else-if="view === 'poem' && detail" class="studio">
      <div class="studio-grid">
        <div class="panel poem-panel">
          <div class="poem-center">
            <div class="poem-stack" :class="{ blurred: poemBlurred }">
              <div class="poem-row title-row">
                <div class="title-cell">
                  <h2 class="poem-title" lang="zh">
                    <span
                      v-for="(unit, uIdx) in linePinyinUnits(detail.poem.title)"
                      :key="`title-${uIdx}`"
                      class="py-syllable"
                      :class="{ punct: !unit.pinyin }"
                    >
                      <span v-if="unit.pinyin" class="py-mark" lang="zh-Latn">{{ unit.pinyin }}</span>
                      <span class="py-char">{{ unit.char }}</span>
                    </span>
                  </h2>
                  <p v-if="metaLine" class="poem-meta">{{ metaLine }}</p>
                </div>
                <button
                  type="button"
                  class="spk"
                  :disabled="practiceActive"
                  :aria-label="t('kid.poetry.speakPoem')"
                  @click="speakPoem"
                >
                  <span aria-hidden="true">🔊</span>
                </button>
              </div>

              <div class="lines" aria-label="poem">
                <div
                  v-for="(line, i) in detail.poem.lines"
                  :key="i"
                  class="poem-row line-row"
                  :class="{
                    active: selectedLine === i,
                    current: practice === 'follow' && followIndex === i,
                    locked: practiceActive,
                  }"
                >
                  <button
                    type="button"
                    class="line-text"
                    lang="zh"
                    :disabled="practiceActive"
                    @click="selectLine(i)"
                  >
                    <span
                      v-for="(unit, uIdx) in linePinyinUnits(line)"
                      :key="`l${i}-${uIdx}`"
                      class="py-syllable"
                      :class="{ punct: !unit.pinyin }"
                    >
                      <span v-if="unit.pinyin" class="py-mark" lang="zh-Latn">{{ unit.pinyin }}</span>
                      <span class="py-char">{{ unit.char }}</span>
                    </span>
                  </button>
                  <button
                    type="button"
                    class="spk"
                    :disabled="practiceActive"
                    :aria-label="t('kid.poetry.speakLine')"
                    @click="speakLine(i)"
                  >
                    <span aria-hidden="true">🔊</span>
                  </button>
                </div>
              </div>
            </div>

            <div class="practice-modes">
              <button
                v-if="!challengeMode"
                type="button"
                class="mode-btn"
                :class="{ on: practice === 'follow' }"
                :disabled="busy || (practiceActive && practice !== 'follow')"
                @click="startFollow"
              >
                {{ t('kid.poetry.practice.follow') }}
              </button>
              <button
                v-if="!challengeMode"
                type="button"
                class="mode-btn"
                :class="{ on: practice === 'read' }"
                :disabled="busy || (practiceActive && practice !== 'read')"
                @click="startRead"
              >
                {{ t('kid.poetry.practice.read') }}
              </button>
              <button
                type="button"
                class="mode-btn"
                :class="{ on: practice === 'recite' }"
                :disabled="busy || (practiceActive && practice !== 'recite')"
                @click="startRecite"
              >
                {{ challengeMode ? t('kid.poetry.practice.challenge') : t('kid.poetry.practice.recite') }}
              </button>
            </div>

            <div v-if="practiceActive" class="practice-bar">
              <p class="practice-hint">{{ practiceHint }}</p>
              <div class="practice-actions">
                <button
                  v-if="recording || preparingVoice"
                  type="button"
                  class="btn-primary"
                  :disabled="preparingVoice || busy"
                  @click="finishPracticeRecording"
                >
                  {{ practice === 'follow' ? t('kid.poetry.doneLine') : t('kid.poetry.endRecord') }}
                </button>
                <button type="button" class="btn-ghost" :disabled="busy" @click="cancelPractice">
                  {{ t('kid.poetry.cancelPractice') }}
                </button>
              </div>
              <p v-if="recording" class="rec-dot" aria-live="polite">{{ t('kid.poetry.recording') }}</p>
            </div>

            <div v-if="lastResult" class="result" :class="{ pass: lastResult.passed }">
              <p class="score">
                {{ lastResult.passed ? t('kid.poetry.passed') : t('kid.poetry.tryAgain') }}
                · {{ lastResult.accuracy_percent }}%
              </p>
              <p>{{ lastResult.feedback }}</p>
            </div>
          </div>
        </div>

        <div class="side">
          <div class="panel art-panel">
            <p class="panel-label">{{ t('kid.poetry.illustration') }}</p>
            <div class="art" :class="{ empty: !detail.image.url }">
              <img
                v-if="detail.image.url"
                :src="detail.image.url"
                :alt="detail.poem.title"
              >
              <div v-else class="art-fallback" role="img" :aria-label="detail.poem.title">
                <span class="art-title">{{ detail.poem.title }}</span>
                <span class="art-hint">{{ metaLine || t('kid.poetry.title') }}</span>
              </div>
            </div>
          </div>

          <div v-if="!challengeMode" class="panel story-panel">
            <div class="panel-head">
              <p class="panel-label">{{ t('kid.poetry.story') }}</p>
              <button
                type="button"
                class="spk"
                :disabled="practiceActive"
                :aria-label="t('kid.poetry.listenStory')"
                @click="speakStory"
              >
                <span aria-hidden="true">🔊</span>
              </button>
            </div>
            <p class="story">{{ detail.story_zh }}</p>
          </div>

          <div v-if="!challengeMode && detail.author_zh" class="panel author-panel">
            <div class="panel-head">
              <p class="panel-label">{{ t('kid.poetry.author') }}</p>
              <button
                type="button"
                class="spk"
                :disabled="practiceActive"
                :aria-label="t('kid.poetry.listenAuthor')"
                @click="speakAuthor"
              >
                <span aria-hidden="true">🔊</span>
              </button>
            </div>
            <p class="author">{{ detail.author_zh }}</p>
            <div
              v-if="detail.author_learned_poems?.length"
              class="author-learned"
            >
              <p class="author-learned-label">{{ t('kid.poetry.authorLearned') }}</p>
              <ul class="author-learned-list">
                <li v-for="row in detail.author_learned_poems" :key="row.id">
                  <button
                    type="button"
                    class="author-learned-btn"
                    :disabled="practiceActive || busy"
                    @click="openPoem({ id: row.id, title: row.title, author: detail.poem.author })"
                  >
                    《{{ row.title }}》
                  </button>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <div class="panel meaning-panel">
          <div class="panel-head">
            <p class="panel-label">{{ t('kid.poetry.meaning') }}</p>
            <button
              type="button"
              class="spk"
              :disabled="practiceActive || !meaningText"
              :aria-label="t('kid.poetry.speakMeaning')"
              @click="speakMeaning"
            >
              <span aria-hidden="true">🔊</span>
            </button>
          </div>
          <p class="meaning">
            {{ meaningText || t('kid.poetry.meaningHint') }}
          </p>
        </div>
      </div>
    </section>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 40px;
  max-width: 1100px;
  margin: 0 auto;
  width: 100%;
  box-sizing: border-box;
}
.head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.head h1 {
  flex: 1;
  text-align: center;
  margin: 0;
  font-size: clamp(18px, 2.4vw, 24px);
  min-width: 0;
}
.spacer {
  flex: 0 0 auto;
  width: min(72px, 18vw);
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
  min-height: 44px;
  white-space: nowrap;
}
.lead, .hint, .err, .toast, .stats, .empty { text-align: center; }
.err { color: #ffb4b4; }
.toast {
  background: rgba(255, 255, 255, 0.12);
  padding: 8px 12px;
  border-radius: 12px;
}
.stats { opacity: 0.85; margin: 0 0 16px; }
.hub { display: flex; flex-direction: column; gap: 4px; }
.mode-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
.mode-card {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  text-align: left;
  border: none;
  border-radius: 18px;
  padding: 16px;
  background: rgba(255, 255, 255, 0.12);
  color: inherit;
  cursor: pointer;
  min-height: 88px;
  box-sizing: border-box;
}
.mode-card.dim { opacity: 0.72; }
.mode-card .emoji { font-size: 32px; flex-shrink: 0; }
.mode-card .copy { flex: 1; min-width: 0; }
.mode-card .name { display: block; font-size: 20px; font-weight: 700; }
.mode-card .blurb { display: block; margin-top: 4px; font-size: 13px; opacity: 0.8; line-height: 1.4; }
.mode-card .badge {
  flex-shrink: 0;
  font-size: 12px;
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(246, 210, 122, 0.28);
}
.list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
}
.list > .lead,
.list > .empty,
.list > h2 {
  grid-column: 1 / -1;
}
h2 {
  font-size: 16px;
  margin: 18px 0 10px;
  opacity: 0.9;
}
.card {
  width: 100%;
  text-align: left;
  border: none;
  border-radius: 16px;
  padding: 14px 16px;
  background: rgba(255, 255, 255, 0.1);
  color: inherit;
  cursor: pointer;
  margin: 0;
  box-sizing: border-box;
  min-width: 0;
}
.card.featured {
  border: 1px solid rgba(255, 214, 120, 0.45);
  background: rgba(246, 210, 122, 0.14);
  grid-column: 1 / -1;
}
.card .title { display: block; font-size: 18px; font-weight: 700; }
.card .sub, .card .preview, .card .reason { display: block; opacity: 0.75; font-size: 13px; margin-top: 4px; }
.card .reason { opacity: 0.9; line-height: 1.45; }
.card .pill {
  display: inline-block;
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(246, 210, 122, 0.35);
  margin-bottom: 6px;
}
.card .done { color: #9dffa8; font-size: 13px; margin-top: 6px; display: block; }

.studio-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
.panel {
  border: 1px solid rgba(255, 180, 120, 0.35);
  border-radius: 16px;
  background: rgba(12, 24, 56, 0.35);
  padding: 14px 16px;
  box-sizing: border-box;
  min-width: 0;
}
.poem-panel {
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  min-height: 280px;
}
.poem-center {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  width: 100%;
  min-height: 0;
}
/* Balance space above the poem with the button zone below, so title stays centered. */
.poem-center::before {
  content: '';
  flex: 1 1 0;
  min-height: 0;
}
.panel-label {
  margin: 0;
  font-size: 13px;
  letter-spacing: 0.08em;
  color: #ffb07a;
  font-weight: 600;
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}
.poem-stack {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin: 0 auto;
  width: min(100%, 22em);
  flex: 0 0 auto;
  transition: filter 0.25s ease;
}
.poem-stack.blurred {
  filter: blur(7px);
  user-select: none;
  pointer-events: none;
}
.poem-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) 40px;
  align-items: center;
  column-gap: 10px;
  width: 100%;
  box-sizing: border-box;
}
.title-row {
  padding: 2px 8px 4px;
}
.title-cell {
  grid-column: 2;
  justify-self: center;
  text-align: center;
  min-width: 0;
}
.poem-meta {
  margin: 4px 0 0;
  font-size: 14px;
  letter-spacing: 0.08em;
  opacity: 0.78;
}
.spk {
  border: none;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 999px;
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 18px;
  flex-shrink: 0;
  padding: 0;
  color: inherit;
  grid-column: 3;
  justify-self: center;
  align-self: center;
  line-height: 1;
}
.spk > span {
  display: block;
  line-height: 1;
  translate: 0 0.02em;
}
.spk:disabled { opacity: 0.4; cursor: default; }
.lines {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
  width: 100%;
}
.line-row {
  border: 1px solid transparent;
  border-radius: 12px;
  padding: 8px;
  min-height: 56px;
}
.line-row:not(.locked):hover,
.line-row.active {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 180, 120, 0.35);
}
.line-row.current {
  background: rgba(246, 210, 122, 0.16);
  border-color: rgba(246, 210, 122, 0.45);
}
.line-text {
  grid-column: 2;
  justify-self: center;
  align-self: center;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: flex-end;
  gap: 0.08em;
  width: max-content;
  max-width: 100%;
  text-align: center;
  border: none;
  background: transparent;
  color: inherit;
  padding: 0;
  margin: 0;
  cursor: pointer;
  font: inherit;
  font-size: clamp(18px, 2.8vw, 24px);
  line-height: 1;
  letter-spacing: 0;
}
.line-text:disabled { cursor: default; }
.poem-title {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: flex-end;
  gap: 0.08em;
  margin: 0;
  font-size: clamp(22px, 3.2vw, 30px);
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0;
}
.py-syllable {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  box-sizing: border-box;
  width: 1.72em;
}
.py-syllable.punct {
  width: 0.72em;
  /* Sit on the character baseline under the pinyin row. */
  padding-bottom: 0.08em;
}
.py-mark {
  display: block;
  width: 100%;
  margin: 0 0 0.08em;
  font-size: 0.48em;
  line-height: 1;
  letter-spacing: 0;
  text-align: center;
  white-space: nowrap;
  color: #ffd7b0;
  font-weight: 500;
  opacity: 0.92;
}
.py-char {
  display: block;
  width: 100%;
  line-height: 1;
  text-align: center;
  letter-spacing: 0.02em;
}
.practice-modes {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  align-content: center;
  column-gap: 12px;
  width: 100%;
  flex: 1 1 0;
  min-height: 56px;
}
.practice-modes .mode-btn:nth-child(1) { justify-self: end; }
.practice-modes .mode-btn:nth-child(2) { justify-self: center; }
.practice-modes .mode-btn:nth-child(3) { justify-self: start; }
.practice-modes .mode-btn:only-child {
  grid-column: 1 / -1;
  justify-self: center;
}
.mode-btn {
  border: 1px solid rgba(255, 180, 120, 0.45);
  background: rgba(255, 255, 255, 0.06);
  color: #ffd7b0;
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  min-height: 40px;
  min-width: 4.5em;
  white-space: nowrap;
}
.mode-btn.on {
  background: rgba(246, 210, 122, 0.22);
  border-color: rgba(246, 210, 122, 0.7);
  color: #fff;
}
.mode-btn:disabled { opacity: 0.45; cursor: default; }
.practice-bar {
  flex: 0 0 auto;
  margin-top: 4px;
  padding-top: 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.12);
}
.practice-hint {
  margin: 0 0 8px;
  font-size: 14px;
  opacity: 0.92;
}
.practice-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.btn-primary {
  border: none;
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 14px;
  font-weight: 700;
  min-height: 40px;
  cursor: pointer;
  color: #1a1a1a;
  background: #f6d27a;
}
.btn-primary:disabled { opacity: 0.55; cursor: default; }
.btn-ghost {
  border: 1px solid rgba(255, 255, 255, 0.25);
  background: transparent;
  color: inherit;
  border-radius: 10px;
  padding: 8px 12px;
  font-size: 13px;
  min-height: 40px;
  cursor: pointer;
}
.rec-dot {
  margin: 8px 0 0;
  font-size: 13px;
  color: #ff8f8f;
}
.result {
  flex: 0 0 auto;
  margin-top: 12px;
  padding: 12px;
  border-radius: 12px;
  background: rgba(255, 120, 120, 0.16);
  font-size: 14px;
}
.result.pass { background: rgba(90, 200, 140, 0.2); }
.score { font-weight: 700; margin: 0 0 6px; }
.result p { margin: 0; }

.side {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}
.side .panel {
  display: flex;
  flex-direction: column;
  flex: 0 0 auto;
}
.art {
  width: 100%;
  flex: 0 0 auto;
  aspect-ratio: 4 / 3;
  border-radius: 12px;
  overflow: hidden;
  margin-top: 8px;
  background: linear-gradient(160deg, rgba(255, 244, 214, 0.2), rgba(120, 160, 200, 0.18));
}
.art img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center;
}
.art-fallback {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 16px;
  text-align: center;
}
.art-title {
  font-size: clamp(20px, 3vw, 26px);
  font-weight: 700;
  letter-spacing: 0.2em;
}
.art-hint { font-size: 13px; opacity: 0.7; }
.story,
.author {
  margin: 0;
  line-height: 1.65;
  font-size: 14px;
  white-space: pre-wrap;
}
.author-learned {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.12);
}
.author-learned-label {
  margin: 0 0 6px;
  font-size: 12px;
  letter-spacing: 0.06em;
  color: #ffb07a;
  font-weight: 600;
}
.author-learned-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.author-learned-btn {
  border: 1px solid rgba(255, 180, 120, 0.4);
  background: rgba(255, 180, 120, 0.12);
  color: inherit;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 13px;
  cursor: pointer;
  min-height: 32px;
}
.author-learned-btn:disabled {
  opacity: 0.45;
  cursor: default;
}
.meaning {
  margin: 0;
  line-height: 1.7;
  font-size: 18px;
  white-space: pre-wrap;
  min-height: 2.8em;
  opacity: 0.95;
}

@media (min-width: 780px) {
  .page { padding: 0 24px 48px; }
  .mode-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
  }
  .mode-card {
    flex-direction: column;
    align-items: flex-start;
    min-height: 148px;
    padding: 18px;
  }
  .mode-card .badge { align-self: flex-start; }
  .list {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }
  .studio-grid {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
    grid-template-areas:
      "poem side"
      "meaning meaning";
    gap: 14px 16px;
    align-items: stretch;
  }
  .poem-panel {
    grid-area: poem;
    height: 100%;
  }
  .side {
    grid-area: side;
    align-self: stretch;
  }
  .meaning-panel { grid-area: meaning; }
}

@media (min-width: 1024px) {
  .page { max-width: 1120px; }
  .line-text { font-size: 24px; letter-spacing: 0.14em; }
}

@media (orientation: landscape) and (max-width: 899px) {
  .page { padding: 0 12px 24px; }
  .mode-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
  .mode-card {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    min-height: 0;
    padding: 12px;
  }
  .studio-grid {
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
    grid-template-areas:
      "poem side"
      "meaning meaning";
    gap: 10px;
    align-items: stretch;
  }
  .side {
    align-self: stretch;
  }
  .line-text { font-size: 17px; line-height: 1.55; }
  .story,
  .author { font-size: 13px; }
  .meaning { font-size: 17px; }
}
</style>
