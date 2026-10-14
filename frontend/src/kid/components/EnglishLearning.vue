<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/shared/api/client'
import { canSpeak, speakPhrase, stopSpeaking, warmPhrases } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

type Item = {
  key: string
  theme: string
  kind: string
  text: string
  meaning: string
  emoji: string
  options: string[]
  review: boolean
  stage: number
  ipa?: string
  pos?: string
  probe?: boolean
  challenge?: boolean
  spelling?: boolean
  blank_hint?: string
  answer?: string
  prompt?: string
  question?: string
}
type Plan = {
  items: Item[]
  completed: number
  due: number
  mastered: number
  learned: number
  total: number
  next_review: string | null
  vocabulary?: { estimated_size: number; band: number; effective_band?: number; learning_words?: string[] }
  ai_status?: 'ok' | 'failed' | 'off'
  /** Sanitized backend code when ai_status is failed (e.g. http_429, timeout). */
  ai_error?: string | null
}
type Result = { correct: boolean; meaning: string; xp: number; coins: number; next_review: string; plan: Plan; coach_tip?: string; probe?: boolean; challenge?: boolean }
type SpeakAttempt = {
  id: number
  target_text: string
  target_meaning: string
  score: number | null
  feedback: string | null
  status: string
}
type SpeakPrompt = {
  available: boolean
  target: { key: string; text: string; meaning: string; kind: string } | null
  attempt: SpeakAttempt | null
  retention_days: number
}
const { locale } = useI18n()
const router = useRouter()
const session = useSessionStore()
const label = (zh: string, en: string) => locale.value === 'en' ? en : locale.value === 'zh-en' ? `${zh} / ${en}` : zh
const plan = ref<Plan | null>(null)
const current = ref<Item | null>(null)
const phase = ref<'learn' | 'quiz' | 'result'>('learn')
const result = ref<Result | null>(null)
const busy = ref(false)
const error = ref('')
const audioMessage = ref('')
const speaking = ref(false)
const speakingLang = ref<'en' | 'zh' | null>(null)
const speakingOption = ref<string | null>(null)
const choices = ref<string[]>([])
const spellingInput = ref('')
const wordSaveMessage = ref('')
const savedWords = ref<string[]>([])
const audioSupported = canSpeak()
const SPELL_SKIP = new Set(['a', 'an', 'the', 'is', 'am', 'are', 'was', 'were', 'be', 'to', 'of', 'and', 'or', 'in', 'on', 'at', 'my', 'your', 'i', 'you', 'we', 'he', 'she', 'it', 'they', 'this', 'that', 'do', 'does', 'did', 'can', 'will'])
const finished = computed(() => plan.value && !current.value)
const aiConnected = computed(() => plan.value?.ai_status === 'ok')
function aiErrorDetail(code: string | null | undefined): string {
  switch (code) {
    case 'no_key':
      return label('未配置密钥或引擎不可用', 'API key missing or engine unavailable')
    case 'timeout':
      return label('连接超时，稍后再试', 'Connection timed out — try again later')
    case 'unreachable':
      return label('网络连不上 AI 服务', 'Could not reach the AI service')
    case 'http_401':
      return label('密钥无效或模型无权访问', 'Invalid key or model not allowed')
    case 'http_429':
      return label('请求过多，请稍后再试', 'Too many requests — try again later')
    case 'http_4xx':
      return label('模型不可用或请求被拒绝', 'Model unavailable or request rejected')
    case 'http_5xx':
      return label('AI 服务暂时异常', 'AI service temporarily unavailable')
    case 'empty':
      return label('AI 返回空内容，已用本地题', 'AI returned empty content — using local cards')
    case 'unavailable':
      return label('今天暂未连上，稍后会自动重试', 'Not connected today — will retry automatically')
    default:
      return code ? label(`原因：${code}`, `Reason: ${code}`) : ''
  }
}
const aiStatusTitle = computed(() => {
  if (!plan.value) {
    return busy.value
      ? label('正在连接…', 'Connecting…')
      : label('准备中…', 'Getting ready…')
  }
  if (plan.value.ai_status === 'ok') return label('AI 已接入', 'AI connected')
  if (plan.value.ai_status === 'failed') {
    const detail = aiErrorDetail(plan.value.ai_error)
    const base = label('AI 接入失败，已用本地内容', 'AI unavailable — using local cards')
    return detail ? `${base} · ${detail}` : base
  }
  return label('未选择 AI 引擎', 'No AI engine selected')
})

const speakPrompt = ref<SpeakPrompt | null>(null)
const speakDone = computed(() => !!speakPrompt.value?.attempt)
function goHome() {
  void router.push({ name: 'kid-home' })
}
async function anotherRound() {
  if (busy.value) return
  // Skip must work even if a previous upload is still pending.
  micHeld = false
  speakBusy.value = false
  speakError.value = ''
  stopRecording({ upload: false })
  speakPrompt.value = null
  error.value = ''
  await load()
  // Backend should always refill; one quiet retry covers a race after the last answer.
  if (!current.value) {
    await load()
  }
  if (!current.value) {
    error.value = label('新题还在准备，请再点一次「再学一轮」。', 'New cards are still preparing — tap “Another round” once more.')
  }
}
const speakBusy = ref(false)
const speakError = ref('')
const recording = ref(false)
/** 0–1 live input level while the mic is open. */
const micLevel = ref(0)
/** False until Mac/browser mic permission has been granted once. */
const micReady = ref(false)
const micArming = ref(false)
const micSupported = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined'
let mediaRecorder: MediaRecorder | null = null
let mediaStream: MediaStream | null = null
let recordChunks: Blob[] = []
let recordMime = 'audio/webm'
let recordStartedAt = 0
let recordTimer: ReturnType<typeof setTimeout> | null = null
let advanceTimer: ReturnType<typeof setTimeout> | null = null
/** True while the finger/mouse is held on the mic (or Space is held). */
let micHeld = false
/** Bumps to cancel a start that finished after the user already released. */
let micPressToken = 0
let audioContext: AudioContext | null = null
let analyser: AnalyserNode | null = null
let levelRaf = 0
const CORRECT_ADVANCE_MS = 1000
const RECORD_MIME_CANDIDATES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/mp4',
  'audio/aac',
  'audio/ogg;codecs=opus',
]
const MIC_AUDIO_CONSTRAINTS: MediaStreamConstraints = {
  audio: {
    echoCancellation: true,
    noiseSuppression: true,
    autoGainControl: true,
  },
}

function clearAdvanceTimer() {
  if (advanceTimer) {
    clearTimeout(advanceTimer)
    advanceTimer = null
  }
}

function kindLabel(item: Item) {
  if (item.spelling || item.kind === 'spelling') return label('拼写填空', 'Spelling')
  if (item.probe && item.kind === 'sentence') return label('生活短句', 'Everyday phrase')
  if (item.probe) return label('词汇小测试', 'Vocabulary check')
  if (item.challenge && item.kind === 'story') return label('短故事', 'Short story')
  if (item.challenge && item.kind === 'grammar') return label('语法小练', 'Grammar play')
  return item.kind === 'word' ? label('推荐单词', 'Word') : label('生活短句', 'Everyday phrase')
}
function badgeLabel(item: Item) {
  if (item.spelling || item.kind === 'spelling') return label('拼写练习', 'Spelling practice')
  if (item.probe) return label('词汇小测试', 'Vocabulary check')
  if (item.challenge) return label('探索挑战', 'Lab challenge')
  return item.review ? label('记忆巩固', 'Review') : label('新发现', 'New discovery')
}
function wordMeta(item: Item | null) {
  if (!item || (item.kind !== 'word' && item.kind !== 'spelling')) return ''
  return [item.pos, item.ipa].filter(Boolean).join(' ')
}
function cardEmoji(item: Item | null) {
  const emoji = item?.emoji?.trim()
  if (emoji) return emoji
  if (item?.spelling || item?.kind === 'spelling') return '✍️'
  if (item?.kind === 'word') return '🔤'
  if (item?.challenge) return item.kind === 'story' ? '📖' : '🧩'
  return '💬'
}
function isSpellingCard(item: Item | null) {
  return !!item && (!!item.spelling || item.kind === 'spelling')
}
function sentenceTokens(text: string) {
  return text.split(/(\s+)/).filter(part => part.length > 0)
}
function clickableWord(token: string) {
  const clean = token.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '')
  if (clean.length < 2) return false
  if (SPELL_SKIP.has(clean.toLowerCase())) return false
  return /^[A-Za-z']+$/.test(clean)
}
function tokenWord(token: string) {
  return token.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '')
}
async function saveLearningWord(token: string) {
  const word = tokenWord(token).toLowerCase()
  if (!word || busy.value) return
  if (savedWords.value.includes(word)) {
    wordSaveMessage.value = label(`「${word}」已在学习词库里`, `“${word}” is already in your word list`)
    return
  }
  busy.value = true
  wordSaveMessage.value = ''
  try {
    await api.post<{ word: string; learning_words: string[] }>('/kid/english/learning/words', { word })
    savedWords.value = [...savedWords.value, word]
    wordSaveMessage.value = label(`已把「${word}」加入学习词库，稍后会练习拼写`, `Added “${word}” — spelling practice will follow`)
  } catch (e) {
    wordSaveMessage.value = e instanceof ApiError ? e.message : label('加入词库失败，请再试一次。', 'Could not save the word. Please retry.')
  } finally {
    busy.value = false
  }
}
function stars(score: number | null | undefined) {
  const n = Math.max(0, Math.min(5, score ?? 0))
  return '★'.repeat(n) + '☆'.repeat(5 - n)
}
function isChineseText(text: string) {
  return /[\u4e00-\u9fff]/.test(text)
}
function choose(item?: Item) {
  clearAdvanceTimer()
  stopSpeaking()
  speaking.value = false
  speakingLang.value = null
  speakingOption.value = null
  speakToken += 1
  current.value = item ?? null
  // Challenge first: every card opens as a quiz. Learn only after a miss.
  phase.value = 'quiz'
  result.value = null
  spellingInput.value = ''
  wordSaveMessage.value = ''
  choices.value = [...(item?.options ?? [])]
  for (let i = choices.value.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[choices.value[i], choices.value[j]] = [choices.value[j]!, choices.value[i]!]
  }
  audioMessage.value = ''
  if (item?.text) warmPhrases([item.text], 'en-US')
  if (item?.meaning && !item.challenge) warmPhrases([item.meaning], 'zh-CN')
  const chineseChoices = choices.value.filter(isChineseText)
  if (chineseChoices.length) warmPhrases(chineseChoices, 'zh-CN')
  // Auto-read English for normal cards and spelling (dictation). Skip review recall so answers stay hidden.
  if (item?.text && audioSupported && !item.review) void playCurrentText()
}
async function load() {
  busy.value = true
  error.value = ''
  try {
    plan.value = await api.get<Plan>('/kid/english/learning')
    choose(plan.value.items[0])
  } catch (e) { error.value = e instanceof ApiError ? e.message : label('学习任务加载失败，请重试。', 'Could not load your mission. Please retry.') }
  finally { busy.value = false }
}
async function loadSpeakPrompt() {
  try {
    speakPrompt.value = await api.get<SpeakPrompt>('/kid/english/learning/speak')
    if (speakPrompt.value.target?.text) warmPhrases([speakPrompt.value.target.text], 'en-US')
  } catch {
    speakPrompt.value = null
  }
}
watch(finished, (done) => {
  if (done) void loadSpeakPrompt()
})
let speakToken = 0
function haltSpeak() {
  stopSpeaking()
  speaking.value = false
  speakingLang.value = null
  speakingOption.value = null
  speakToken += 1
}
async function playCurrentText() {
  if (!current.value?.text || !audioSupported) return
  const token = ++speakToken
  speaking.value = true
  speakingLang.value = 'en'
  audioMessage.value = ''
  try {
    await speakPhrase(current.value.text, 'en-US')
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
  } finally {
    if (token === speakToken) {
      speaking.value = false
      speakingLang.value = null
    }
  }
}
async function playCurrentMeaning() {
  const meaning = current.value?.meaning?.trim()
  if (!meaning || !audioSupported) return
  const token = ++speakToken
  speaking.value = true
  speakingLang.value = 'zh'
  speakingOption.value = null
  audioMessage.value = ''
  try {
    await speakPhrase(meaning, 'zh-CN')
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
  } finally {
    if (token === speakToken) {
      speaking.value = false
      speakingLang.value = null
      speakingOption.value = null
    }
  }
}
async function speak() {
  if (!current.value) return
  if (speaking.value && speakingLang.value === 'en') {
    haltSpeak()
    return
  }
  if (speaking.value) haltSpeak()
  await playCurrentText()
}
async function speakChinese() {
  if (!current.value?.meaning?.trim()) return
  if (speaking.value && speakingLang.value === 'zh' && !speakingOption.value) {
    haltSpeak()
    return
  }
  if (speaking.value) haltSpeak()
  await playCurrentMeaning()
}
async function speakOption(option: string) {
  const phrase = option.trim()
  if (!phrase || !audioSupported || !isChineseText(phrase)) return
  if (speaking.value && speakingLang.value === 'zh' && speakingOption.value === phrase) {
    haltSpeak()
    return
  }
  if (speaking.value) haltSpeak()
  const token = ++speakToken
  speaking.value = true
  speakingLang.value = 'zh'
  speakingOption.value = phrase
  audioMessage.value = ''
  try {
    await speakPhrase(phrase, 'zh-CN')
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
  } finally {
    if (token === speakToken) {
      speaking.value = false
      speakingLang.value = null
      speakingOption.value = null
    }
  }
}
function optionSpeakLabel(option: string) {
  return speaking.value && speakingLang.value === 'zh' && speakingOption.value === option
    ? `⏹ ${label('停止朗读', 'Stop reading')}`
    : `🔊 ${label('朗读选项', 'Read option')}`
}
async function speakTarget() {
  const text = speakPrompt.value?.target?.text
  if (!text) return
  if (speaking.value && speakingLang.value === 'en') {
    haltSpeak()
    return
  }
  if (speaking.value) haltSpeak()
  const token = ++speakToken
  speaking.value = true
  speakingLang.value = 'en'
  try {
    await speakPhrase(text, 'en-US')
  } catch {
    speakError.value = label('暂时无法朗读范读。', 'Could not play the model reading.')
  } finally {
    if (token === speakToken) {
      speaking.value = false
      speakingLang.value = null
    }
  }
}
function listenText(idle: string, idleEn: string, lang: 'en' | 'zh' = 'en') {
  return speaking.value && speakingLang.value === lang
    ? `⏹ ${label('停止', 'Stop')}`
    : `🔊 ${label(idle, idleEn)}`
}
const canListenChinese = computed(() => {
  const item = current.value
  return !!item?.meaning?.trim() && !item.challenge
})
async function answer(value: string) {
  if (!current.value || busy.value || phase.value !== 'quiz') return
  const trimmed = value.trim()
  if (!trimmed) return
  busy.value = true
  error.value = ''
  try {
    result.value = await api.postIdempotent<Result>('/kid/english/learning/answer', { key: current.value.key, answer: trimmed })
    plan.value = result.value.plan
    phase.value = 'result'
    // A balance refresh should not hide a successfully saved answer.
    if (result.value.correct) {
      void session.load(true).catch(() => {})
      // Warm the next card's audio during the brief success pause.
      const upcoming = plan.value?.items.find(item => item.key !== current.value?.key) ?? plan.value?.items[0]
      if (upcoming?.text) warmPhrases([upcoming.text], 'en-US')
      if (upcoming?.meaning && !upcoming.challenge) warmPhrases([upcoming.meaning], 'zh-CN')
      clearAdvanceTimer()
      advanceTimer = setTimeout(() => {
        advanceTimer = null
        next()
      }, CORRECT_ADVANCE_MS)
    }
  } catch (e) { error.value = e instanceof ApiError ? e.message : label('提交失败，请重试。', 'Could not save your answer. Please retry.') }
  finally { busy.value = false }
}
function next() {
  clearAdvanceTimer()
  const sameText = (item: Item) => {
    const a = current.value?.text?.trim().toLowerCase() ?? ''
    const b = item.text?.trim().toLowerCase() ?? ''
    return a !== '' && a === b
  }
  if (!result.value?.correct) {
    // Interleave a different expression before returning to the missed item, when possible.
    const other = plan.value?.items.find(item => item.key !== current.value?.key && !sameText(item))
    if (other) choose(other)
    else {
      const missed = plan.value?.items.find(item => item.key === current.value?.key)
      if (!missed) {
        choose(plan.value?.items.find(item => !sameText(item)) ?? plan.value?.items[0])
        return
      }
      choose(missed)
      phase.value = 'learn'
      // After a miss, study the card (with audio) before challenging again.
      if (missed.text && audioSupported) void playCurrentText()
    }
  } else {
    const upcoming = plan.value?.items.find(item => item.key !== current.value?.key && !sameText(item))
      ?? plan.value?.items.find(item => item.key !== current.value?.key)
      ?? plan.value?.items[0]
    choose(upcoming)
  }
}
function stopMicTracks() {
  stopLevelMeter()
  mediaStream?.getTracks().forEach(track => track.stop())
  mediaStream = null
  mediaRecorder = null
}
function pickRecordMime(): string | undefined {
  if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') return undefined
  return RECORD_MIME_CANDIDATES.find(type => MediaRecorder.isTypeSupported(type))
}
function recordFilename(mime: string): string {
  if (mime.includes('mp4') || mime.includes('aac') || mime.includes('m4a')) return 'speak.m4a'
  if (mime.includes('ogg')) return 'speak.ogg'
  if (mime.includes('wav')) return 'speak.wav'
  return 'speak.webm'
}
async function refreshMicPermission(): Promise<void> {
  if (!micSupported || typeof navigator.permissions?.query !== 'function') return
  try {
    const status = await navigator.permissions.query({ name: 'microphone' as PermissionName })
    micReady.value = status.state === 'granted'
    status.onchange = () => { micReady.value = status.state === 'granted' }
  } catch {
    /* Safari may not support microphone permission query. */
  }
}
/** First click opens the real Mac/browser mic permission (hold alone often cancels during the system dialog). */
async function enableMicrophone(): Promise<void> {
  if (!micSupported || speakBusy.value || micArming.value) return
  speakError.value = ''
  micArming.value = true
  try {
    const stream = await navigator.mediaDevices.getUserMedia(MIC_AUDIO_CONSTRAINTS)
    stream.getTracks().forEach(track => track.stop())
    micReady.value = true
  } catch {
    micReady.value = false
    speakError.value = label('需要麦克风权限才能跟读。请在浏览器/系统设置里允许本站使用麦克风。', 'Microphone permission is needed. Allow the mic for this site in the browser or system settings.')
  } finally {
    micArming.value = false
  }
}
function startLevelMeter(stream: MediaStream) {
  stopLevelMeter()
  const AC = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!AC) return
  try {
    audioContext = new AC()
    const source = audioContext.createMediaStreamSource(stream)
    analyser = audioContext.createAnalyser()
    analyser.fftSize = 256
    analyser.smoothingTimeConstant = 0.7
    source.connect(analyser)
    const data = new Uint8Array(analyser.fftSize)
    const tick = () => {
      if (!analyser) return
      analyser.getByteTimeDomainData(data)
      let sum = 0
      for (let i = 0; i < data.length; i++) {
        const v = ((data[i] ?? 128) - 128) / 128
        sum += v * v
      }
      const rms = Math.sqrt(sum / data.length)
      // Boost so quiet kid speech still moves the bars.
      micLevel.value = Math.max(0, Math.min(1, rms * 4.2))
      levelRaf = requestAnimationFrame(tick)
    }
    void audioContext.resume().then(() => { levelRaf = requestAnimationFrame(tick) })
  } catch {
    /* Level meter is optional. */
  }
}
function stopLevelMeter() {
  if (levelRaf) cancelAnimationFrame(levelRaf)
  levelRaf = 0
  micLevel.value = 0
  analyser = null
  if (audioContext) {
    const ctx = audioContext
    audioContext = null
    void ctx.close().catch(() => {})
  }
}
function onMicPointerDown(event: PointerEvent) {
  if (event.button !== 0) return
  if (!micReady.value) return
  event.preventDefault()
  const target = event.currentTarget as HTMLElement | null
  try { target?.setPointerCapture(event.pointerId) } catch { /* ignore */ }
  void beginMicHold()
}
function onMicPointerUp() {
  // System mic permission dialog often fires pointercancel — ignore while arming.
  if (micArming.value) return
  endMicHold()
}
function onMicKeyDown(event: KeyboardEvent) {
  if (event.repeat || (event.key !== ' ' && event.key !== 'Enter')) return
  if (!micReady.value) return
  event.preventDefault()
  void beginMicHold()
}
function onMicKeyUp(event: KeyboardEvent) {
  if (event.key !== ' ' && event.key !== 'Enter') return
  event.preventDefault()
  endMicHold()
}
async function beginMicHold() {
  if (!micSupported || !micReady.value || speakBusy.value || recording.value || micArming.value || !speakPrompt.value?.available) return
  micHeld = true
  const token = ++micPressToken
  stopSpeaking()
  speaking.value = false
  speakingLang.value = null
  await startRecording()
  // User released before getUserMedia / MediaRecorder was ready — discard.
  if (!micHeld || token !== micPressToken) {
    stopRecording({ upload: false })
  }
}
function endMicHold() {
  micHeld = false
  micPressToken += 1
  if (recording.value) stopRecording({ upload: true })
}
async function startRecording() {
  if (!micSupported || !micReady.value || speakBusy.value || recording.value || !speakPrompt.value?.available) return
  speakError.value = ''
  micArming.value = true
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia(MIC_AUDIO_CONSTRAINTS)
  } catch {
    speakError.value = label('打不开麦克风。请检查浏览器和 Mac 的麦克风权限。', 'Could not open the microphone. Check browser and Mac mic permissions.')
    micReady.value = false
    micHeld = false
    micArming.value = false
    return
  }
  micArming.value = false
  mediaStream.getAudioTracks().forEach(track => { track.enabled = true })
  if (!micHeld) {
    stopMicTracks()
    return
  }
  recordChunks = []
  const mime = pickRecordMime()
  recordMime = mime?.split(';')[0] || 'audio/webm'
  try {
    mediaRecorder = mime ? new MediaRecorder(mediaStream, { mimeType: mime }) : new MediaRecorder(mediaStream)
  } catch {
    try {
      mediaRecorder = new MediaRecorder(mediaStream)
      recordMime = mediaRecorder.mimeType?.split(';')[0] || recordMime
    } catch {
      speakError.value = label('这个浏览器不能录音，可以请家长陪你口头跟读。', 'Recording is unavailable here. Ask a grown-up to practice with you.')
      stopMicTracks()
      micHeld = false
      return
    }
  }
  mediaRecorder.ondataavailable = (event) => {
    if (event.data.size > 0) recordChunks.push(event.data)
  }
  mediaRecorder.onstop = () => { void finishRecording() }
  recordStartedAt = Date.now()
  recording.value = true
  startLevelMeter(mediaStream)
  // Timeslice so short holds still flush audio chunks before stop.
  try {
    mediaRecorder.start(200)
  } catch {
    mediaRecorder.start()
  }
  recordTimer = setTimeout(() => stopRecording({ upload: true }), 8000)
}
function stopRecording(options: { upload?: boolean } = {}) {
  const shouldUpload = options.upload !== false
  micArming.value = false
  if (recordTimer) {
    clearTimeout(recordTimer)
    recordTimer = null
  }
  if (!shouldUpload) {
    if (mediaRecorder) mediaRecorder.onstop = null
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      try { mediaRecorder.stop() } catch { /* ignore */ }
    }
    recording.value = false
    recordChunks = []
    stopMicTracks()
    return
  }
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    try {
      if (mediaRecorder.state === 'recording') mediaRecorder.requestData()
    } catch { /* ignore */ }
    mediaRecorder.stop()
  } else {
    recording.value = false
    stopMicTracks()
  }
}
async function finishRecording() {
  recording.value = false
  stopLevelMeter()
  const duration = Date.now() - recordStartedAt
  const mime = recordMime || 'audio/webm'
  const blob = new Blob(recordChunks, { type: mime })
  stopMicTracks()
  if (duration < 280 || blob.size < 200) {
    speakError.value = label('按住说话键，说完再松开。太短了，再试一次吧。', 'Hold the mic, speak, then release. That was too short — try again.')
    return
  }
  speakBusy.value = true
  speakError.value = ''
  try {
    const form = new FormData()
    form.append('audio', blob, recordFilename(mime))
    form.append('duration_ms', String(Math.min(8000, duration)))
    const attempt = await api.upload<SpeakAttempt>('/kid/english/learning/speak', form, { idempotent: true })
    if (speakPrompt.value) speakPrompt.value = { ...speakPrompt.value, attempt }
    await nextTick()
    document.querySelector('.speak-result')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  } catch (e) {
    if (e instanceof ApiError) {
      const fieldMsg = Object.values(e.fieldErrors).flat()[0]
      speakError.value = fieldMsg || e.message
    } else {
      speakError.value = label('上传失败，请再试一次。', 'Upload failed. Please try again.')
    }
  } finally {
    speakBusy.value = false
  }
}
watch(speakPrompt, (prompt) => {
  if (prompt?.available) void refreshMicPermission()
})
onMounted(load)
onUnmounted(() => {
  clearAdvanceTimer()
  micHeld = false
  stopSpeaking()
  stopRecording({ upload: false })
  stopMicTracks()
})
</script>

<template>
  <section class="learning" :aria-label="label('每日英语任务', 'Daily English mission')" :aria-busy="busy">
    <div class="intro">
      <span class="eyebrow">
        ENGLISH LAB · {{ label('每天一小步', 'One small step a day') }}
        <span class="ai-status-chip" :title="aiStatusTitle" :aria-label="aiStatusTitle">
          <span class="ai-dot" :class="aiConnected ? 'on' : 'off'" role="img" />
          <span class="ai-status-text">{{ aiStatusTitle }}</span>
        </span>
      </span>
      <h2>🚀 {{ label('今天，用英语探索世界', 'Explore your world in English') }}</h2>
      <p>{{ label('每次大约 10 题，做完还能继续 · 最多 3 个新内容 · 其余用来认识你的词汇与短句', 'About 10 cards per round — keep going after that · Up to 3 new cards · The rest checks words and short phrases') }}</p>
    </div>
    <div v-if="plan" class="stats">
      <span>✨ {{ label('今日完成', 'Done today') }} <b>{{ plan.completed }}/{{ plan.completed + plan.items.length }}</b></span>
      <span>🌱 {{ label('已接触', 'Discovered') }} <b>{{ plan.learned }}/{{ plan.total }}</b></span>
      <span>🌟 {{ label('熟悉内容', 'Familiar cards') }} <b>{{ plan.mastered }}</b></span>
      <span>📖 {{ label('大约认识', 'Expressions you know') }} <b>{{ plan.vocabulary?.estimated_size ?? 0 }}</b></span>
    </div>
    <p v-if="error" role="alert" class="error">{{ error }} <button :disabled="busy" @click="load">{{ label('重新加载', 'Reload') }}</button></p>
    <p v-if="!plan && busy">{{ label('正在准备探索任务…', 'Preparing your mission…') }}</p>
    <article v-if="current" class="lesson">
      <div class="card-top"><span>{{ current.theme }} · {{ kindLabel(current) }}</span><span class="badge">{{ badgeLabel(current) }}</span></div>
      <template v-if="phase === 'learn'">
        <div class="emoji" aria-hidden="true">{{ cardEmoji(current) }}</div>
        <h3 lang="en" class="sentence-line">
          <template v-if="current.kind === 'sentence' || (current.probe && current.kind === 'sentence')">
            <template v-for="(token, idx) in sentenceTokens(current.text)" :key="`${idx}-${token}`">
              <button
                v-if="clickableWord(token)"
                type="button"
                class="word-chip"
                :disabled="busy"
                @click="saveLearningWord(token)"
              >{{ token }}</button>
              <span v-else>{{ token }}</span>
            </template>
          </template>
          <template v-else>{{ current.challenge ? (current.prompt || current.text) : current.text }}</template>
        </h3>
        <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <p v-if="!current.challenge" class="meaning">{{ current.meaning }}</p>
        <p v-else class="meaning">{{ current.question || current.meaning }}</p>
        <p>{{ label('刚才没答对。听一听、看一看，再挑战一次。', 'That was a miss. Listen and look, then try again.') }}</p>
        <p v-if="current.kind === 'sentence'" class="hint">{{ label('不认识的词可以点一下，加入学习词库。', 'Tap a word you don’t know to add it to your list.') }}</p>
        <p v-if="wordSaveMessage" class="hint" role="status">{{ wordSaveMessage }}</p>
        <div class="listen-row">
          <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
          <button v-if="canListenChinese" class="secondary" :disabled="!audioSupported" @click="speakChinese">{{ listenText('听中文', 'Listen in Chinese', 'zh') }}</button>
        </div>
        <button class="primary" @click="phase = 'quiz'">{{ label('再挑战一次', 'Try again') }} →</button>
      </template>
      <template v-else-if="phase === 'quiz'">
        <template v-if="isSpellingCard(current)">
          <p>{{ label('根据意思，把单词拼写出来', 'Spell the word from the meaning') }}</p>
          <h3>
            <span class="word-emoji" aria-hidden="true">{{ cardEmoji(current) }}</span>
            {{ current.meaning }}
          </h3>
          <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
          <p class="blank-hint" lang="en" aria-label="spelling hint">{{ current.blank_hint }}</p>
          <form class="spelling-form" @submit.prevent="answer(spellingInput)">
            <input
              v-model="spellingInput"
              type="text"
              class="spelling-input"
              lang="en"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              :placeholder="label('在这里拼写…', 'Type the spelling…')"
              :disabled="busy"
            >
            <button class="primary" type="submit" :disabled="busy || !spellingInput.trim()">{{ label('提交拼写', 'Check spelling') }}</button>
          </form>
          <div class="listen-row">
            <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
            <button class="secondary" :disabled="!audioSupported" @click="speakChinese">{{ listenText('听中文意思', 'Hear meaning', 'zh') }}</button>
          </div>
        </template>
        <template v-else>
          <p v-if="current.challenge || current.review">
            {{ current.challenge ? (current.question || label('选出正确答案', 'Choose the best answer')) : label('这句话或单词，用英语怎么说？', 'How do you say this in English?') }}
          </p>
          <h3 :lang="current.review ? 'zh' : 'en'" class="sentence-line">
            <span v-if="!current.review" class="word-emoji" aria-hidden="true">{{ cardEmoji(current) }}</span>
            <template v-if="!current.review && !current.challenge && current.kind === 'sentence'">
              <template v-for="(token, idx) in sentenceTokens(current.text)" :key="`q-${idx}-${token}`">
                <button
                  v-if="clickableWord(token)"
                  type="button"
                  class="word-chip"
                  :disabled="busy"
                  @click="saveLearningWord(token)"
                >{{ token }}</button>
                <span v-else>{{ token }}</span>
              </template>
            </template>
            <template v-else>
              {{ current.challenge ? (current.prompt || current.text) : current.review ? current.meaning : current.text }}
            </template>
          </h3>
          <p v-if="!current.review && current.kind === 'sentence'" class="hint">{{ label('不认识的词可以点一下，加入学习词库。', 'Tap a word you don’t know to add it to your list.') }}</p>
          <p v-if="wordSaveMessage" class="hint" role="status">{{ wordSaveMessage }}</p>
          <p v-if="!current.review && wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
          <button v-if="!current.review" class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('再听一次', 'Listen again') }}</button>
          <div class="answers">
            <div v-for="option in choices" :key="option" class="answer-row">
              <button type="button" class="answer" :disabled="busy" @click="answer(option)">{{ option }}</button>
              <button
                v-if="isChineseText(option) && audioSupported"
                type="button"
                class="option-speak"
                :aria-label="optionSpeakLabel(option)"
                :title="optionSpeakLabel(option)"
                :disabled="busy"
                @click.stop="speakOption(option)"
              >{{ speaking.value && speakingLang === 'zh' && speakingOption === option ? '⏹' : '🔊' }}</button>
            </div>
          </div>
        </template>
      </template>
      <div v-else-if="result" class="feedback" aria-live="polite">
        <div class="emoji">{{ result.correct ? '🎉' : '🌱' }}</div>
        <h3>{{ result.correct ? label('答对了！', 'You got it!') : label('没关系，再认识它一次', 'Let’s learn this one again') }}</h3>
        <p>
          <span class="word-emoji" aria-hidden="true">{{ cardEmoji(current) }}</span>
          <strong lang="en">{{ current.text }}</strong> · {{ result.meaning }}
        </p>
        <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <p v-if="result.coach_tip" class="coach">{{ result.coach_tip }}</p>
        <p v-if="result.correct && !current.probe && !current.challenge">+{{ result.xp }} XP · +{{ result.coins }} {{ label('金币', 'coins') }}<br>{{ label('下次复习', 'Next review') }}：{{ result.next_review }}</p>
        <p v-else-if="isSpellingCard(current)">{{ label('拼写练习不发金币，练会了会从学习词库里拿掉。', 'Spelling practice awards no coins. Mastered words leave your learning list.') }}</p>
        <p v-else-if="current.probe">{{ label('这题用来认识你的词汇，不发金币。估计会留在家里，换一个 AI 也接着用。', 'This check estimates your vocabulary and does not award coins. The estimate stays with your family if the AI provider changes.') }}</p>
        <p v-else-if="current.challenge">{{ label('探索挑战由家长发布，不发金币。', 'Lab challenges are published by a parent and do not award coins.') }}</p>
        <p v-else>{{ label('答错不扣分。看看正确表达，稍后再试一次。', 'No points lost. Read the expression and try again shortly.') }}</p>
        <div class="listen-row">
          <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
          <button v-if="canListenChinese" class="secondary" :disabled="!audioSupported" @click="speakChinese">{{ listenText('听中文', 'Listen in Chinese', 'zh') }}</button>
        </div>
        <button class="primary" @click="next">{{ label('继续探索', 'Continue') }} →</button>
      </div>
      <p v-if="!audioSupported">{{ label('当前浏览器不支持朗读，可以和家长一起读。', 'Read together with a grown-up; audio is not supported here.') }}</p>
      <p v-if="audioMessage" role="status">{{ audioMessage }}</p>
    </article>
    <div v-else-if="finished" class="lesson done">
      <div class="emoji">🪐</div>
      <h3>{{ label('这一轮完成啦', 'This round is done') }}</h3>
      <p>{{ label('想开口的话，可以用小话筒跟读一句。练开口，不是正式考试。也可以马上再学一轮。', 'Want to speak? Try the mic and read a line aloud — practice only, not a real exam. Or start another round anytime.') }}</p>
      <div v-if="speakPrompt?.available && speakPrompt.target" class="speak-box">
        <p class="speak-label">🎤 {{ label('跟读今天一句', 'Read today’s line') }}</p>
        <h3 lang="en" class="speak-text">{{ speakPrompt.target.text }}</h3>
        <p class="meaning">{{ speakPrompt.target.meaning }}</p>
        <button class="secondary" :disabled="!audioSupported || speakBusy" @click="speakTarget">{{ listenText('先听范读', 'Hear model') }}</button>
        <button
          v-if="!micReady"
          type="button"
          class="mic"
          :disabled="speakBusy || !micSupported || micArming"
          @click="enableMicrophone"
        >
          {{ micArming ? label('正在打开麦克风…', 'Opening microphone…') : label('🎙️ 先允许使用麦克风', '🎙️ Allow microphone') }}
        </button>
        <button
          v-else
          type="button"
          class="mic"
          :class="{ recording, live: recording && micLevel > 0.08 }"
          :disabled="speakBusy || !micSupported"
          :aria-pressed="recording"
          :aria-label="recording ? label('松开结束录音', 'Release to finish') : label('按住说话', 'Hold to speak')"
          :style="recording ? { transform: `scale(${1 + micLevel * 0.08})` } : undefined"
          @pointerdown="onMicPointerDown"
          @pointerup="onMicPointerUp"
          @pointercancel="onMicPointerUp"
          @lostpointercapture="onMicPointerUp"
          @keydown="onMicKeyDown"
          @keyup="onMicKeyUp"
          @contextmenu.prevent
        >
          {{ recording ? label('🔴 松手结束', '🔴 Release to finish') : speakBusy ? label('正在听…', 'Listening…') : micArming ? label('正在打开麦克风…', 'Opening microphone…') : label('🎙️ 按住说话', '🎙️ Hold to speak') }}
        </button>
        <div v-if="micReady" class="mic-meter" :class="{ active: recording }" aria-hidden="true">
          <span
            v-for="i in 5"
            :key="i"
            class="mic-bar"
            :style="{ height: `${10 + (recording ? Math.max(4, micLevel * 32 - (i - 1) * 5) : 4)}px`, opacity: recording && micLevel > (i - 1) * 0.12 ? 1 : 0.22 }"
          />
        </div>
        <p class="hint">
          {{ micReady
            ? label('按住按钮说话，音量条会跟着动；说完松开。大约 8 秒内。', 'Hold to speak — the bars move with your voice. Release when done. About 8 seconds max.')
            : label('第一次请先点「允许使用麦克风」，Mac 会弹出权限；允许后再按住说话。', 'First tap “Allow microphone” so Mac can ask for permission. Then hold to speak.') }}
        </p>
        <p v-if="!micSupported" class="hint">{{ label('这个浏览器不能录音，可以请家长陪你口头跟读。', 'Recording is unavailable here. Ask a grown-up to practice with you.') }}</p>
        <p v-if="speakError" class="error" role="alert">{{ speakError }}</p>
        <div v-if="speakPrompt.attempt" class="speak-result" aria-live="polite">
          <p class="stars">{{ stars(speakPrompt.attempt.score) }}</p>
          <p>{{ speakPrompt.attempt.feedback }}</p>
          <p class="hint">{{ label('想再练，还可以再按住说话键。', 'Want another try? Hold the mic again.') }}</p>
          <button type="button" class="primary" :disabled="busy" @click="anotherRound">
            {{ busy ? label('正在准备…', 'Preparing…') : label('完成了，再学一轮', 'Done — another round') }} →
          </button>
          <button type="button" class="secondary done-home" @click="goHome">
            {{ label('回星球', 'Back to planet') }}
          </button>
        </div>
        <p class="hint">{{ label('录音会留给家长听几天，到期自动删除，不发金币。', 'Parents can hear this for a few days, then it deletes. No coins.') }}</p>
      </div>
      <p v-else-if="speakPrompt && !speakPrompt.available" class="hint">{{ label('还没有答对的句子可跟读，先再学一轮也可以。', 'No correct phrase to read yet — you can start another round anytime.') }}</p>
      <p>{{ label('一天可以一直学。想休息就回星球；想继续就再学一轮。', 'You can keep learning all day. Head back to the planet when you want a break, or start another round.') }}</p>
      <p v-if="plan?.next_review">{{ label('下次复习', 'Next review') }}：{{ plan.next_review }}</p>
      <p v-if="plan && plan.due > 0">{{ label('还有复习可以继续，点再学一轮吧。', 'More reviews are waiting — start another round.') }}</p>
      <div class="done-actions">
        <button type="button" class="primary" :disabled="busy" @click="anotherRound">
          {{ busy ? label('正在准备…', 'Preparing…') : speakDone ? label('再学一轮', 'Another round') : label('先跳过跟读，再学一轮', 'Skip speak — another round') }} →
        </button>
        <button type="button" class="secondary" @click="goHome">
          {{ label('回星球', 'Back to planet') }}
        </button>
      </div>
    </div>
    <details class="rules"><summary>{{ label('学习与奖励规则', 'Learning and rewards') }}</summary><p>{{ label('一天可以无限量学习。课程卡每天首次答对获得奖励，默认 2 XP + 1 金币；每天课程奖励最多 10 项，之后继续学不再发奖。词汇小测试、探索挑战与跟读打分不发金币。实际奖励以家长设置为准。答错不扣分。', 'You can learn without a daily card limit. Course cards earn a reward once per day: normally 2 XP + 1 coin, up to 10 rewarded course cards; keep practicing after that without more rewards. Vocabulary checks, lab challenges, and speak-along scores do not award coins. Parent settings apply. Mistakes cost nothing.') }}</p><p>{{ label('答对后间隔 1、3、7、14 天复习；答错则缩短间隔，重新巩固。熟悉内容仍会继续复习。', 'Correct answers schedule reviews after 1, 3, 7, then 14 days. Mistakes restart the review sequence. Familiar cards continue to return.') }}</p></details>
  </section>
</template>

<style scoped>
.learning { margin: 24px 0 32px; padding: 24px; border: 1px solid #77b7d944; border-radius: 24px; background: linear-gradient(135deg, #23577933, #34523c22); }
.eyebrow { color: #8cdbc8; font-size: 12px; letter-spacing: 1px; display: inline-flex; align-items: center; gap: 8px; }
.ai-status-chip { display: inline-flex; align-items: center; gap: 6px; margin-left: 4px; }
.ai-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ai-dot.on { background: #3dd68c; box-shadow: 0 0 0 3px #3dd68c33; }
.ai-dot.off { background: #8b93a1; box-shadow: 0 0 0 3px #8b93a133; }
.ai-status-text { color: #a8b0bd; font-size: 11px; letter-spacing: 0; text-transform: none; }
h2 { margin: 10px 0; font-size: clamp(21px, 3vw, 28px); } p { line-height: 1.7; }
.intro p, .rules, .hint { opacity: .8; font-size: 14px; }
.stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 20px 0; }.stats span { background: #ffffff0a; padding: 10px 14px; border-radius: 12px; font-size: 14px; }.stats b { margin-left: 6px; }
.lesson { background: #071e3280; border: 1px solid #ffffff1a; border-radius: 20px; padding: 24px; text-align: center; }
.card-top { display: flex; gap: 10px; justify-content: space-between; align-items: center; font-size: 13px; text-align: left; }.badge { background: #8cdbc81c; color: #9fe4d3; border-radius: 20px; padding: 5px 12px; white-space: nowrap; }
.emoji { font-size: 52px; margin-top: 20px; line-height: 1; }
.word-emoji { margin-right: 0.28em; font-size: 0.9em; }
h3 { font-size: clamp(24px, 4vw, 36px); margin: 18px 0 8px; overflow-wrap: anywhere; }
.meaning { font-size: 20px; }
.meta { margin: 0 0 10px; font-size: 14px; color: #9fe4d3; letter-spacing: .02em; opacity: .95; }
.coach { margin: 12px auto 0; max-width: 420px; padding: 10px 14px; border-radius: 12px; background: #8cdbc814; color: #c9f3e8; font-size: 15px; }
.speak-box { margin: 20px auto 0; max-width: 480px; padding: 16px; border-radius: 16px; border: 1px solid #8cdbc844; background: #8cdbc812; }
.speak-label { margin: 0; font-size: 14px; color: #9fe4d3; }
.speak-text { margin-top: 8px; }
.mic {
  display: block;
  margin: 14px auto 0;
  min-width: 220px;
  background: #235779;
  border-color: #8cdbc8;
  font-weight: 700;
  touch-action: none;
  user-select: none;
  -webkit-user-select: none;
  transition: transform 60ms linear, background 160ms ease, border-color 160ms ease;
}
.mic.recording { background: #c45c5c; border-color: #ffb4b4; }
.mic.live { box-shadow: 0 0 0 3px #ffb4b455; }
.mic-meter {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 6px;
  height: 44px;
  margin: 12px auto 0;
  opacity: 0.35;
  transition: opacity 160ms ease;
}
.mic-meter.active { opacity: 1; }
.mic-bar {
  width: 8px;
  min-height: 10px;
  border-radius: 999px;
  background: linear-gradient(180deg, #9fe4d3, #8cdbc8 40%, #3dd68c);
  transition: height 60ms linear, opacity 80ms linear;
}
.speak-result { margin-top: 14px; }
.stars { font-size: 22px; letter-spacing: 2px; color: #f5d76e; margin: 0 0 6px; }
.done-actions { display: grid; gap: 10px; max-width: 320px; margin: 18px auto 0; }
.done-home { display: block; margin: 10px auto 0; }
button { font: inherit; cursor: pointer; border-radius: 12px; min-height: 46px; padding: 10px 18px; color: inherit; border: 1px solid #ffffff35; background: #ffffff0a; }button:disabled { opacity: .5; cursor: wait; }button:focus-visible { outline: 3px solid #8cdbc8; outline-offset: 3px; }
.listen-row { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 8px; }
.primary { display: block; margin: 18px auto 0; background: #8cdbc8; color: #102c31; font-weight: 700; border: 0; }.secondary { font-size: 14px; }
.answers { display: grid; gap: 12px; max-width: 480px; margin: 24px auto 0; }
.answer-row { display: flex; gap: 8px; align-items: stretch; }
.answer-row .answer { flex: 1; min-width: 0; }
.option-speak { flex: 0 0 46px; width: 46px; min-width: 46px; padding: 0; font-size: 18px; line-height: 1; }
.answers .answer:hover:not(:disabled), .answers .option-speak:hover:not(:disabled) { border-color: #8cdbc8; background: #8cdbc81c; }
.sentence-line { display: flex; flex-wrap: wrap; justify-content: center; align-items: baseline; gap: 0.12em 0.18em; }
.word-chip {
  display: inline;
  min-height: 0;
  padding: 0 2px;
  margin: 0;
  border: 0;
  border-bottom: 2px dotted #8cdbc888;
  border-radius: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: inherit;
  line-height: inherit;
}
.word-chip:hover:not(:disabled) { color: #9fe4d3; border-bottom-color: #9fe4d3; background: #8cdbc814; }
.blank-hint {
  margin: 12px auto;
  max-width: 100%;
  padding: 0 8px;
  font-size: clamp(22px, 4vw, 32px);
  letter-spacing: 0.12em;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: #9fe4d3;
  white-space: nowrap;
  overflow-x: auto;
  line-height: 1.4;
}
.spelling-form { display: grid; gap: 12px; max-width: 360px; margin: 18px auto 0; }
.spelling-input {
  width: 100%;
  min-height: 48px;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1px solid #ffffff35;
  background: #ffffff0f;
  color: inherit;
  font: inherit;
  font-size: 22px;
  letter-spacing: 0.06em;
  text-align: center;
}
.spelling-input:focus { outline: 3px solid #8cdbc8; outline-offset: 2px; }
.rules { margin-top: 20px; }.rules summary { cursor: pointer; }.error { color: #ffb4b4; }
@media(max-width: 520px) { .learning { padding: 16px; }.lesson { padding: 18px 14px; }.card-top { flex-wrap: wrap; }.stats { gap: 6px; }.stats span { padding: 8px; } }
</style>
