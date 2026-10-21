<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/shared/api/client'
import { chinesePinyin, chinesePinyinUnits } from '@/shared/chinesePinyin'
import { canSpeak, isSpeakUnlocked, speakPhrase, stopSpeaking, unlockSpeak, warmPhrases } from '@/shared/speak'
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
  daily_word?: boolean
  collected?: boolean
  blank_hint?: string
  answer?: string
  prompt?: string
  question?: string
  usage?: {
    phrase: { text: string; meaning: string }
    sentence: { text: string; meaning: string }
  } | null
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
  new_words_goal?: number
  new_words_completed?: number
  fairy_tale_unlocked?: boolean
  fairy_tale_heard_today?: boolean
  review_unlocked?: boolean
  fairy_tales_heard?: number
}
type FairyTaleBody = {
  id: string
  title_zh: string
  title_en: string
  source: string
  source_en?: string
  zh_lines: string[]
  en_lines: string[]
}
type FairyTalePayload = {
  available: boolean
  reason?: string | null
  new_words_completed: number
  new_words_goal: number
  heard: boolean
  tale: FairyTaleBody | null
  date?: string
  pack_words?: string[]
}
type RecordWord = {
  key: string
  text: string
  meaning: string
  emoji: string
  ipa?: string
  pos?: string
  status?: 'done' | 'learning' | 'pending'
  collected?: boolean
  kind?: string
  daily_word?: boolean
  stage?: number
}
type FairyTaleHistoryItem = {
  date: string
  id: string
  title_zh: string
  title_en: string
  source: string
  source_en?: string
  heard: boolean
}
type LearningRecords = {
  as_of: string
  completed_today: RecordWord[]
  new_words_today: RecordWord[]
  learned: RecordWord[]
  mastered: RecordWord[]
  known_words: RecordWord[]
  fairy_tales: FairyTaleHistoryItem[]
  new_words_goal: number
  new_words_completed: number
  fairy_tales_heard: number
  estimated_size?: number
}
type RecordsView = 'completed' | 'new_words' | 'fairy_tales' | 'learned' | 'mastered' | 'known' | null
type Result = {
  correct: boolean
  meaning: string
  xp: number
  coins: number
  next_review: string
  plan: Plan
  coach_tip?: string
  probe?: boolean
  challenge?: boolean
  spelling?: boolean
  skipped?: boolean
  probe_corrects?: number | null
  probe_goal?: number | null
  promoted_to_spelling?: boolean
  mastery_phase?: string | null
}
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
const wrongPicks = ref<string[]>([])
const quizLocked = ref(false)
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
const fairyTale = ref<FairyTalePayload | null>(null)
const fairyBusy = ref(false)
const fairyReading = ref<'zh' | 'en' | null>(null)
const recordsView = ref<RecordsView>(null)
const records = ref<LearningRecords | null>(null)
const recordsBusy = ref(false)
const historyTale = ref<FairyTalePayload | null>(null)
const historyTaleDate = ref<string | null>(null)
const taleAddedWords = ref<string[]>([])
const taleWordBusy = ref(false)
const taleWordMessage = ref('')
const newWordsGoal = computed(() => plan.value?.new_words_goal ?? 10)
const pendingNewWords = computed(() => {
  const pending = (records.value?.new_words_today ?? []).filter(item => item.status !== 'done')
  // Collected words are appended in tap order. Continue from the one just added.
  const collected = pending.filter(item => item.collected).reverse()
  const scheduled = pending.filter(item => !item.collected)
  return [...collected, ...scheduled]
})
const newWordsCompleted = computed(() => plan.value?.new_words_completed ?? 0)
const fairyTalesHeard = computed(() => plan.value?.fairy_tales_heard ?? records.value?.fairy_tales_heard ?? 0)
const activeFairyPayload = computed(() => historyTale.value ?? fairyTale.value)
const activeFairyTale = computed(() => activeFairyPayload.value?.tale ?? null)
const canMarkFairyHeard = computed(() => {
  if (!activeFairyPayload.value?.available || !activeFairyTale.value) return false
  if (historyTale.value) {
    const date = historyTale.value.date || historyTaleDate.value
    const today = records.value?.as_of
    return !!date && !!today && date === today
  }
  return true
})
function hasHeardFairyTale(): boolean {
  return !!fairyTale.value?.heard
}
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
  fairyTale.value = null
  fairyReading.value = null
  error.value = ''
  await load()
  // Backend should always refill; one quiet retry covers a race after the last answer.
  if (!current.value) {
    await load()
  }
  if (!current.value) {
    const needWords = (plan.value?.new_words_completed ?? 0) < (plan.value?.new_words_goal ?? 10)
    const needFairy = !!plan.value?.fairy_tale_unlocked && !(plan.value.fairy_tale_heard_today || hasHeardFairyTale())
    if (needFairy) {
      error.value = label('先听完今天的童话，再继续复习。', 'Listen to today’s fairy tale first, then keep reviewing.')
      void loadFairyTale()
    } else if (needWords) {
      error.value = label('新词还在准备，请再点一次「再学一轮」；若仍没有题，点重新加载。', 'New words are still preparing — tap “Another round” again, or Reload if it stays empty.')
    } else {
      error.value = label('新题还在准备，请再点一次「再学一轮」。', 'New cards are still preparing — tap “Another round” once more.')
    }
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
  if (item.challenge && item.kind === 'story') return label('短故事', 'Short story')
  if (item.challenge && item.kind === 'grammar') return label('语法小练', 'Grammar play')
  if (item.kind === 'phrase') return label('词组', 'Phrase')
  return item.kind === 'word' ? label('单词', 'Word') : label('句子', 'Sentence')
}
function isNewWord(item: Item) {
  return item.kind === 'word' && !item.review && !item.probe && !item.challenge
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
function taleWord(token: string) {
  return tokenWord(token).toLowerCase().replace(/'s$/, '')
}
function wordEdge(token: string) {
  const match = /^([^A-Za-z]*)([A-Za-z']*)(.*)$/.exec(token)
  return {
    lead: match?.[1] ?? '',
    word: match?.[2] ?? '',
    tail: match?.[3] ?? '',
  }
}
function taleWordAdded(token: string) {
  const word = taleWord(token)
  return word !== '' && taleAddedWords.value.includes(word)
}
function rememberPackWords(words?: string[]) {
  if (!words?.length) return
  const merged = new Set(taleAddedWords.value)
  for (const word of words) {
    const clean = word.trim().toLowerCase()
    if (clean) merged.add(clean)
  }
  taleAddedWords.value = [...merged]
}
async function addTaleWord(token: string, date?: string | null) {
  const word = taleWord(token)
  if (!word || taleWordBusy.value) return
  if (taleWordAdded(token)) {
    taleWordMessage.value = label(`「${word}」已经在今日新词里`, `“${word}” is already in today’s new words`)
    return
  }
  taleWordBusy.value = true
  taleWordMessage.value = ''
  try {
    const data = await api.post<{
      word: string
      added: boolean
      already: boolean
      learned: boolean
      pack_words: string[]
    }>('/kid/english/learning/fairy-tale/words', date ? { word, date } : { word })
    rememberPackWords(data.pack_words?.length ? data.pack_words : [word])
    taleWordMessage.value = data.learned
      ? label(`「${word}」今天已经学过啦`, `You already learned “${word}” today`)
      : data.added
        ? label(`已把「${word}」加入今日新词，点上方「今日新词」可以接着学`, `Added “${word}” to today’s new words. Tap “New words today” to practice it.`)
        : label(`「${word}」已经在今日新词里`, `“${word}” is already in today’s new words`)
  } catch (e) {
    taleWordMessage.value = e instanceof ApiError ? e.message : label('加入今日新词失败，请再试一次。', 'Could not add this word. Please retry.')
  } finally {
    taleWordBusy.value = false
  }
}
async function continueNewWords(key?: string) {
  const target = key ?? pendingNewWords.value[0]?.key
  closeRecords()
  busy.value = true
  error.value = ''
  try {
    plan.value = await api.get<Plan>('/kid/english/learning', target ? { prefer: target } : undefined)
    warmPlanAudio(plan.value.items)
    const item = (target ? plan.value.items.find(entry => entry.key === target) : undefined)
      ?? plan.value.items.find(entry => entry.collected)
      ?? plan.value.items.find(entry => entry.daily_word || entry.key.startsWith('word:'))
      ?? plan.value.items[0]
    if (item) choose(item)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : label('学习任务加载失败，请重试。', 'Could not load your mission. Please retry.')
  } finally {
    busy.value = false
  }
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
/** Kid cannot spell this word — record a miss, reveal the answer, keep it for later practice. */
async function skipSpelling() {
  if (!current.value || !isSpellingCard(current.value) || busy.value || phase.value !== 'quiz') return
  const word = current.value.text.trim()
  if (!word) return
  busy.value = true
  error.value = ''
  wordSaveMessage.value = ''
  try {
    // A deliberate miss: observe() keeps the word in learning_words for another round.
    result.value = await api.postIdempotent<Result>('/kid/english/learning/answer', {
      key: current.value.key,
      answer: '__skip__',
    })
    plan.value = result.value.plan
    warmPlanAudio(plan.value.items)
    phase.value = 'result'
    wordSaveMessage.value = label(
      `已记下「${word}」，接下来会在词汇小测试里再见面`,
      `Saved “${word}”. You’ll see it again in a vocabulary check.`,
    )
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : label('记录失败，请再试一次。', 'Could not save this word. Please retry.')
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
function optionPinyin(text: string) {
  return isChineseText(text) ? chinesePinyin(text) : ''
}
/** Prefetch neural audio for the whole daily queue so later cards play with less delay. */
function warmPlanAudio(items: Item[]) {
  const english: string[] = []
  const chinese: string[] = []
  for (const item of items) {
    if (item.text) english.push(item.text)
    if (item.meaning && !item.challenge) chinese.push(item.meaning)
    if (item.question && isChineseText(item.question)) chinese.push(item.question)
    for (const example of [item.usage?.phrase, item.usage?.sentence]) {
      if (example?.text) english.push(example.text)
      if (example?.meaning) chinese.push(example.meaning)
    }
    for (const option of item.options ?? []) {
      if (isChineseText(option)) chinese.push(option)
      else if (option.trim()) english.push(option)
    }
  }
  if (english.length) warmPhrases(english, 'en-US')
  if (chinese.length) warmPhrases(chinese, 'zh-CN')
}
/** True when the current card should auto-read but media may still be locked (Chrome / iPad). */
let pendingAutoRead = false
let autoReadAttempt = 0
const loadingAudio = ref(false)

function scheduleAutoRead(item: Item) {
  if (!audioSupported || item.challenge) return
  pendingAutoRead = true
  const key = item.key
  const attempt = ++autoReadAttempt
  const playback = item.review && item.meaning ? playCurrentMeaning() : playCurrentText()
  void playback.then((played) => {
    if (current.value?.key !== key || attempt !== autoReadAttempt) return
    pendingAutoRead = !played
  })
}

async function startAutoRead() {
  const key = current.value?.key
  // Stop an earlier denied attempt before priming the shared audio element.
  haltSpeak()
  const unlocked = await unlockSpeak()
  if (!unlocked || !current.value || current.value.key !== key) return
  scheduleAutoRead(current.value)
}

/** A gesture on the card can resume the first word; buttons own their own playback. */
function onLearningGesture(event: Event) {
  const interactive = event.target instanceof Element && event.target.closest('button, input, select, textarea, a, summary')
  if (interactive) {
    void unlockSpeak()
    return
  }
  if (pendingAutoRead && !isSpeakUnlocked()) void startAutoRead()
  else void unlockSpeak()
}

function choose(item?: Item) {
  clearAdvanceTimer()
  stopSpeaking()
  speaking.value = false
  loadingAudio.value = false
  speakingLang.value = null
  speakingOption.value = null
  speakToken += 1
  pendingAutoRead = false
  autoReadAttempt += 1
  current.value = item ?? null
  // Challenge first: every card opens as a quiz. Learn only after a miss.
  phase.value = 'quiz'
  result.value = null
  wrongPicks.value = []
  quizLocked.value = false
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
  if (!item || !audioSupported) return
  scheduleAutoRead(item)
}
async function load() {
  busy.value = true
  error.value = ''
  try {
    plan.value = await api.get<Plan>('/kid/english/learning')
    warmPlanAudio(plan.value.items)
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
async function loadFairyTale() {
  if (!plan.value?.fairy_tale_unlocked && (plan.value?.new_words_completed ?? 0) < (plan.value?.new_words_goal ?? 10)) {
    fairyTale.value = null
    return
  }
  fairyBusy.value = true
  try {
    fairyTale.value = await api.get<FairyTalePayload>('/kid/english/learning/fairy-tale')
    rememberPackWords(fairyTale.value?.pack_words)
    const tale = fairyTale.value?.tale
    if (tale) {
      if (tale.zh_lines.length) warmPhrases(tale.zh_lines, 'zh-CN')
      if (tale.en_lines.length) warmPhrases(tale.en_lines, 'en-US')
    }
  } catch {
    fairyTale.value = null
  } finally {
    fairyBusy.value = false
  }
}
async function openRecords(view: Exclude<RecordsView, null>) {
  if (recordsView.value === view) {
    closeRecords()
    return
  }
  recordsView.value = view
  historyTale.value = null
  historyTaleDate.value = null
  recordsBusy.value = true
  try {
    records.value = await api.get<LearningRecords>('/kid/english/learning/records')
    if (plan.value) {
      plan.value = {
        ...plan.value,
        new_words_completed: records.value.new_words_completed,
        new_words_goal: records.value.new_words_goal,
        fairy_tales_heard: records.value.fairy_tales_heard,
        fairy_tale_unlocked: records.value.new_words_completed >= records.value.new_words_goal,
      }
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : label('记录加载失败，请重试。', 'Could not load records. Please retry.')
    recordsView.value = null
  } finally {
    recordsBusy.value = false
  }
}
function closeRecords() {
  haltFairy()
  recordsView.value = null
  historyTale.value = null
  historyTaleDate.value = null
}
async function openHistoryTale(date: string) {
  historyTaleDate.value = date
  fairyBusy.value = true
  haltFairy()
  try {
    historyTale.value = await api.get<FairyTalePayload>(`/kid/english/learning/fairy-tale?date=${encodeURIComponent(date)}`)
    rememberPackWords(historyTale.value?.pack_words)
    const tale = historyTale.value?.tale
    if (tale) {
      if (tale.zh_lines.length) warmPhrases(tale.zh_lines, 'zh-CN')
      if (tale.en_lines.length) warmPhrases(tale.en_lines, 'en-US')
    }
  } catch (e) {
    historyTale.value = null
    error.value = e instanceof ApiError ? e.message : label('童话加载失败，请重试。', 'Could not load the fairy tale. Please retry.')
  } finally {
    fairyBusy.value = false
  }
}
function backToFairyList() {
  haltFairy()
  historyTale.value = null
  historyTaleDate.value = null
}
function wordStatusLabel(status?: string) {
  if (status === 'done') return label('已学会', 'Done')
  if (status === 'learning') return label('学习中', 'Learning')
  return label('待学习', 'To learn')
}
async function markFairyHeard() {
  if (!canMarkFairyHeard.value) return
  try {
    const payload = await api.postIdempotent<FairyTalePayload>('/kid/english/learning/fairy-tale/heard', {})
    fairyTale.value = payload
    if (historyTale.value) {
      historyTale.value = { ...payload, date: historyTale.value.date || historyTaleDate.value || undefined }
    }
    if (recordsView.value) {
      records.value = await api.get<LearningRecords>('/kid/english/learning/records')
      if (plan.value && records.value) {
        plan.value = {
          ...plan.value,
          fairy_tales_heard: records.value.fairy_tales_heard,
          fairy_tale_heard_today: true,
          review_unlocked: true,
        }
      }
    } else if (plan.value && payload.heard) {
      plan.value = {
        ...plan.value,
        fairy_tales_heard: Math.max(plan.value.fairy_tales_heard ?? 0, 1),
        fairy_tale_heard_today: true,
        review_unlocked: true,
      }
    }
  } catch {
    // Listening still works if the mark fails.
  }
}
function haltFairy() {
  haltSpeak()
  fairyReading.value = null
}
async function playFairyLines(lang: 'zh' | 'en') {
  const tale = activeFairyTale.value
  if (!tale || !audioSupported) return
  if (fairyReading.value === lang) {
    haltFairy()
    return
  }
  unlockSpeak()
  if (speaking.value || fairyReading.value) haltFairy()
  const lines = lang === 'zh' ? tale.zh_lines : tale.en_lines
  const locale = lang === 'zh' ? 'zh-CN' : 'en-US'
  const token = ++speakToken
  fairyReading.value = lang
  speaking.value = true
  speakingLang.value = lang
  audioMessage.value = ''
  try {
    for (const line of lines) {
      if (token !== speakToken) return
      await speakPhrase(line, locale)
    }
    if (lang === 'en' && token === speakToken) {
      await markFairyHeard()
    }
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    return false
  } finally {
    if (token === speakToken) {
      loadingAudio.value = false
      speaking.value = false
      speakingLang.value = null
      fairyReading.value = null
    }
  }
}
function fairyPairedLines(tale: FairyTaleBody) {
  const count = Math.max(tale.zh_lines.length, tale.en_lines.length)
  return Array.from({ length: count }, (_, index) => ({
    zh: tale.zh_lines[index] ?? '',
    en: tale.en_lines[index] ?? '',
    units: chinesePinyinUnits(tale.zh_lines[index] ?? ''),
  }))
}
watch(finished, (done) => {
  if (done) {
    void loadSpeakPrompt()
    void loadFairyTale()
  }
})
watch(
  () => plan.value?.fairy_tale_unlocked,
  (unlocked) => {
    if (unlocked && finished.value) void loadFairyTale()
  },
)
let speakToken = 0
function haltSpeak() {
  stopSpeaking()
  speaking.value = false
  loadingAudio.value = false
  speakingLang.value = null
  speakingOption.value = null
  speakToken += 1
}
async function playCurrentText(): Promise<boolean> {
  if (!current.value?.text || !audioSupported) return false
  const token = ++speakToken
  speaking.value = false
  loadingAudio.value = true
  speakingLang.value = 'en'
  audioMessage.value = ''
  try {
    return await speakPhrase(current.value.text, 'en-US', () => {
      if (token === speakToken) { loadingAudio.value = false; speaking.value = true }
    })
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    return false
  } finally {
    if (token === speakToken) {
      loadingAudio.value = false
      speaking.value = false
      speakingLang.value = null
    }
  }
}
async function playCurrentMeaning(): Promise<boolean> {
  const meaning = current.value?.meaning?.trim()
  if (!meaning || !audioSupported) return false
  const token = ++speakToken
  speaking.value = false
  loadingAudio.value = true
  speakingLang.value = 'zh'
  speakingOption.value = null
  audioMessage.value = ''
  try {
    return await speakPhrase(meaning, 'zh-CN', () => {
      if (token === speakToken) { loadingAudio.value = false; speaking.value = true }
    })
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    return false
  } finally {
    if (token === speakToken) {
      loadingAudio.value = false
      speaking.value = false
      speakingLang.value = null
      speakingOption.value = null
    }
  }
}
async function speak() {
  if (!current.value) return
  const wasSpeaking = speaking.value && isSpeakUnlocked()
  await unlockSpeak()
  pendingAutoRead = false
  if (wasSpeaking && speaking.value && speakingLang.value === 'en') {
    haltSpeak()
    return
  }
  if (speaking.value) haltSpeak()
  await playCurrentText()
}
async function speakChinese() {
  if (!current.value?.meaning?.trim()) return
  unlockSpeak()
  pendingAutoRead = false
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
  unlockSpeak()
  pendingAutoRead = false
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
    return false
  } finally {
    if (token === speakToken) {
      loadingAudio.value = false
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
  unlockSpeak()
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
      loadingAudio.value = false
      speaking.value = false
      speakingLang.value = null
    }
  }
}
function listenText(idle: string, idleEn: string, lang: 'en' | 'zh' = 'en') {
  if (loadingAudio.value && speakingLang.value === lang) return label('准备语音…', 'Loading audio…')
  return speaking.value && speakingLang.value === lang
    ? `⏹ ${label('停止', 'Stop')}`
    : `🔊 ${label(idle, idleEn)}`
}
const canListenChinese = computed(() => {
  const item = current.value
  return !!item?.meaning?.trim() && !item.challenge
})
async function answer(value: string) {
  if (!current.value || busy.value || phase.value !== 'quiz' || quizLocked.value) return
  const trimmed = value.trim()
  if (!trimmed || wrongPicks.value.includes(trimmed)) return
  unlockSpeak()
  pendingAutoRead = false
  busy.value = true
  error.value = ''
  try {
    result.value = await api.postIdempotent<Result>('/kid/english/learning/answer', { key: current.value.key, answer: trimmed })
    plan.value = result.value.plan
    // Keep remaining daily audio warm after the plan refreshes.
    warmPlanAudio(plan.value.items)
    const multiChoice = choices.value.length > 0 && !isSpellingCard(current.value)
    if (result.value.correct) {
      phase.value = 'result'
      void session.load(true).catch(() => {})
      clearAdvanceTimer()
      // New words pause for the companion phrase and sentence before continuing.
      if (!isNewWord(current.value)) {
        advanceTimer = setTimeout(() => {
          advanceTimer = null
          next()
        }, CORRECT_ADVANCE_MS)
      }
    } else if (multiChoice) {
      // Stay on the quiz: mark the miss red and let the child try another option.
      wrongPicks.value = [...wrongPicks.value, trimmed]
      if (result.value.probe || result.value.challenge) {
        quizLocked.value = true
      }
    } else {
      phase.value = 'result'
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
  const items = plan.value?.items ?? []
  if (!result.value?.correct) {
    // Interleave a different expression before returning to the missed item, when possible.
    const other = items.find(item => item.key !== current.value?.key && !sameText(item))
    if (other) choose(other)
    else {
      const missed = items.find(item => item.key === current.value?.key)
      if (!missed) {
        choose(items.find(item => !sameText(item)) ?? items[0])
        return
      }
      choose(missed)
      phase.value = 'learn'
      // After a miss, study the card (with audio) before challenging again.
      if (missed.text && audioSupported) void playCurrentText()
    }
  } else {
    // Follow server plan order. Skipping "same text" here used to ping-pong
    // yellow ↔ cloud when a rematch of the just-answered word sat at the front.
    const upcoming = items.find(item => item.key !== current.value?.key) ?? items[0]
    choose(upcoming)
  }
}
const usageAudioPending = ref('')
async function speakUsage(text: string, lang: 'en-US' | 'zh-CN') {
  if (!audioSupported) return
  unlockSpeak()
  audioMessage.value = ''
  usageAudioPending.value = text
  try {
    await speakPhrase(text, lang)
  } catch {
    audioMessage.value = label('暂时无法朗读，请再试一次。', 'Could not play audio. Please try again.')
  } finally {
    if (usageAudioPending.value === text) usageAudioPending.value = ''
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
  loadingAudio.value = false
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
  <section
    class="learning"
    :aria-label="label('每日英语任务', 'Daily English mission')"
    :aria-busy="busy"
    @click.capture="onLearningGesture"
  >
    <div class="intro">
      <span class="eyebrow">
        ENGLISH LAB · {{ label('每天一小步', 'One small step a day') }}
        <span class="ai-status-chip" :title="aiStatusTitle" :aria-label="aiStatusTitle">
          <span class="ai-dot" :class="aiConnected ? 'on' : 'off'" role="img" />
          <span class="ai-status-text">{{ aiStatusTitle }}</span>
        </span>
      </span>
      <h2>🚀 {{ label('今天，用英语探索世界', 'Explore your world in English') }}</h2>
      <p>{{ label('先学新词（每轮最多 5 个，每天 10 个）→ 读完童话 → 再复习旧词和短句（每天最多 10 个旧词）', 'New words first (up to 5 per round, 10 a day) → fairy tale → then reviews and short phrases (up to 10 old words a day)') }}</p>
    </div>
    <div v-if="plan" class="stats">
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'completed' }" @click="openRecords('completed')">
        <span class="stat-label">✨ {{ label('今日完成', 'Done today') }}</span>
        <b>{{ plan.completed }}/{{ plan.completed + plan.items.length }}</b>
      </button>
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'new_words' }" @click="openRecords('new_words')">
        <span class="stat-label">🆕 {{ label('今日新词', 'New words today') }}</span>
        <b>{{ newWordsCompleted }}/{{ newWordsGoal }}</b>
      </button>
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'fairy_tales' }" @click="openRecords('fairy_tales')">
        <span class="stat-label">📚 {{ label('已读童话', 'Fairy tales read') }}</span>
        <b>{{ fairyTalesHeard }}</b>
      </button>
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'learned' }" @click="openRecords('learned')">
        <span class="stat-label">🌱 {{ label('已接触', 'Discovered') }}</span>
        <b>{{ plan.learned }}/{{ plan.total }}</b>
      </button>
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'mastered' }" @click="openRecords('mastered')">
        <span class="stat-label">🌟 {{ label('熟悉内容', 'Familiar cards') }}</span>
        <b>{{ plan.mastered }}</b>
      </button>
      <button type="button" class="stat-chip" :class="{ open: recordsView === 'known' }" @click="openRecords('known')">
        <span class="stat-label">📖 {{ label('大约认识', 'Expressions you know') }}</span>
        <b>{{ plan.vocabulary?.estimated_size ?? 0 }}</b>
      </button>
    </div>
    <div v-if="recordsView" class="records-panel" role="dialog" :aria-label="label('学习记录', 'Learning records')">
      <div class="records-head">
        <button v-if="historyTale" type="button" class="secondary" @click="backToFairyList">← {{ label('返回列表', 'Back to list') }}</button>
        <h3>
          <template v-if="historyTale">{{ label('再读一遍', 'Read again') }}</template>
          <template v-else-if="recordsView === 'completed'">{{ label('今日完成记录', 'Done today') }}</template>
          <template v-else-if="recordsView === 'new_words'">{{ label('今日新词记录', 'New words today') }}</template>
          <template v-else-if="recordsView === 'learned'">{{ label('已接触记录', 'Discovered cards') }}</template>
          <template v-else-if="recordsView === 'mastered'">{{ label('熟悉内容记录', 'Familiar cards') }}</template>
          <template v-else-if="recordsView === 'known'">{{ label('大约认识的表达', 'Expressions you know') }}</template>
          <template v-else>{{ label('已读童话', 'Fairy tales read') }}</template>
        </h3>
        <button type="button" class="secondary" @click="closeRecords">{{ label('关闭', 'Close') }}</button>
      </div>
      <p v-if="recordsBusy" class="hint">{{ label('正在加载记录…', 'Loading records…') }}</p>
      <template v-else-if="historyTale?.tale">
        <div class="fairy-box records-fairy" aria-label="fairy tale reread">
          <div class="fairy-head">
            <div class="fairy-title fairy-line" role="heading" aria-level="3">
              <p class="fairy-zh-text" lang="zh">
                <span
                  v-for="(unit, uIdx) in chinesePinyinUnits(historyTale.tale.title_zh)"
                  :key="`ht-${uIdx}`"
                  class="fairy-syllable"
                  :class="{ punct: !unit.pinyin }"
                >
                  <span v-if="unit.pinyin" class="fairy-pinyin" lang="zh-Latn">{{ unit.pinyin }}</span>
                  <span class="fairy-char">{{ unit.char }}</span>
                </span>
              </p>
              <div v-if="historyTale.tale.title_en" class="fairy-en-text" lang="en">
                <template v-for="(token, tIdx) in sentenceTokens(historyTale.tale.title_en)" :key="`ht-en-${tIdx}`">
                  <template v-if="clickableWord(token)">
                    <span v-if="wordEdge(token).lead">{{ wordEdge(token).lead }}</span>
                    <button
                      type="button"
                      class="word-chip"
                      :class="{ added: taleWordAdded(token) }"
                      :disabled="taleWordBusy"
                      @click="addTaleWord(token, historyTaleDate)"
                    >{{ wordEdge(token).word }}</button>
                    <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                  </template>
                  <span v-else>{{ token }}</span>
                </template>
              </div>
            </div>
            <div class="fairy-actions">
              <button type="button" class="secondary" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('zh')">
                {{ fairyReading === 'zh' ? label('⏹ 停止', '⏹ Stop') : label('听中文', 'Listen in Chinese') }}
              </button>
              <button type="button" class="secondary" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('en')">
                {{ fairyReading === 'en' ? label('⏹ 停止', '⏹ Stop') : label('听英文', 'Listen in English') }}
              </button>
            </div>
          </div>
          <p v-if="historyTale.tale.source" class="fairy-source">{{ historyTale.tale.source }}<template v-if="historyTale.tale.source_en"> · {{ historyTale.tale.source_en }}</template></p>
          <div class="fairy-body">
            <div v-for="(line, idx) in fairyPairedLines(historyTale.tale)" :key="`hz-${idx}`" class="fairy-line">
              <p class="fairy-zh-text" lang="zh">
                <span
                  v-for="(unit, uIdx) in line.units"
                  :key="`hzu-${idx}-${uIdx}`"
                  class="fairy-syllable"
                  :class="{ punct: !unit.pinyin }"
                >
                  <span v-if="unit.pinyin" class="fairy-pinyin" lang="zh-Latn">{{ unit.pinyin }}</span>
                  <span class="fairy-char">{{ unit.char }}</span>
                </span>
              </p>
              <div v-if="line.en" class="fairy-en-text" lang="en">
                <template v-for="(token, tIdx) in sentenceTokens(line.en)" :key="`hen-${idx}-${tIdx}`">
                  <template v-if="clickableWord(token)">
                    <span v-if="wordEdge(token).lead">{{ wordEdge(token).lead }}</span>
                    <button
                      type="button"
                      class="word-chip"
                      :class="{ added: taleWordAdded(token) }"
                      :disabled="taleWordBusy"
                      @click="addTaleWord(token, historyTaleDate)"
                    >{{ wordEdge(token).word }}</button>
                    <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                  </template>
                  <span v-else>{{ token }}</span>
                </template>
              </div>
            </div>
          </div>
          <p class="hint">{{ label('点不认识的英文单词，加入今日新词。', 'Tap an English word you don’t know to add it to today’s new words.') }}</p>
          <p v-if="taleWordMessage" class="hint" role="status">{{ taleWordMessage }}</p>
        </div>
      </template>
      <template v-else-if="recordsView === 'completed'">
        <ul v-if="records?.completed_today?.length" class="record-list">
          <li v-for="item in records.completed_today" :key="item.key" class="record-item">
            <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
            <div>
              <p class="record-main" lang="en">{{ item.text }}</p>
              <p class="record-sub">{{ item.meaning }}</p>
            </div>
            <span v-if="item.daily_word" class="record-tag">{{ label('新词', 'New') }}</span>
          </li>
        </ul>
        <p v-else class="hint">{{ label('今天还没有完成的课程卡。', 'No completed course cards yet today.') }}</p>
      </template>
      <template v-else-if="recordsView === 'new_words'">
        <p class="hint">{{ label(`今日目标 ${newWordsCompleted}/${newWordsGoal}。点还没学会的词，或点「继续学习」，就能接着练。`, `Today’s goal ${newWordsCompleted}/${newWordsGoal}. Tap a word still to learn, or “Keep learning”, to practice it.`) }}</p>
        <button
          v-if="pendingNewWords.length"
          type="button"
          class="primary records-continue"
          :disabled="busy"
          @click="continueNewWords()"
        >{{ label(`继续学习（${pendingNewWords.length}）`, `Keep learning (${pendingNewWords.length})`) }}</button>
        <ul v-if="records?.new_words_today?.length" class="record-list">
          <li v-for="item in records.new_words_today" :key="item.key" class="record-item" :class="{ learnable: item.status !== 'done' }">
            <button
              v-if="item.status !== 'done'"
              type="button"
              class="record-learn"
              :disabled="busy"
              @click="continueNewWords(item.key)"
            >
              <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
              <div>
                <p class="record-main" lang="en">{{ item.text }}</p>
                <p class="record-sub">{{ item.meaning }}</p>
              </div>
              <span class="record-tag" :class="item.status">{{ wordStatusLabel(item.status) }}</span>
            </button>
            <template v-else>
              <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
              <div>
                <p class="record-main" lang="en">{{ item.text }}</p>
                <p class="record-sub">{{ item.meaning }}</p>
              </div>
              <span class="record-tag" :class="item.status">{{ wordStatusLabel(item.status) }}</span>
            </template>
          </li>
        </ul>
        <p v-else class="hint">{{ label('今天的新词还在准备。', 'Today’s new words are still preparing.') }}</p>
      </template>
      <template v-else-if="recordsView === 'fairy_tales'">
        <ul v-if="records?.fairy_tales?.length" class="record-list">
          <li v-for="item in records.fairy_tales" :key="item.date" class="record-item">
            <button type="button" class="record-title-btn" @click="openHistoryTale(item.date)">
              <span class="record-main">{{ item.title_zh }}</span>
              <span v-if="item.title_en" class="record-sub" lang="en">{{ item.title_en }}</span>
              <span class="record-meta">{{ item.date }} · {{ item.source }}<template v-if="item.heard"> · {{ label('已听', 'Heard') }}</template></span>
            </button>
          </li>
        </ul>
        <p v-else class="hint">{{ label('还没有读过的童话。学满 10 个新词就能解锁。', 'No fairy tales yet. Unlock one after 10 new words.') }}</p>
      </template>
      <template v-else-if="recordsView === 'learned'">
        <ul v-if="records?.learned?.length" class="record-list">
          <li v-for="item in records.learned" :key="item.key" class="record-item">
            <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
            <div>
              <p class="record-main" lang="en">{{ item.text }}</p>
              <p class="record-sub">{{ item.meaning }}</p>
            </div>
            <span class="record-tag">Lv.{{ item.stage ?? 0 }}</span>
          </li>
        </ul>
        <p v-else class="hint">{{ label('还没有接触过的课程卡。', 'No discovered cards yet.') }}</p>
      </template>
      <template v-else-if="recordsView === 'mastered'">
        <p class="hint">{{ label('连续答对 4 次（间隔复习）后计入熟悉内容。', 'Cards count as familiar after 4 correct spaced reviews.') }}</p>
        <ul v-if="records?.mastered?.length" class="record-list">
          <li v-for="item in records.mastered" :key="item.key" class="record-item">
            <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
            <div>
              <p class="record-main" lang="en">{{ item.text }}</p>
              <p class="record-sub">{{ item.meaning }}</p>
            </div>
            <span class="record-tag done">Lv.{{ item.stage ?? 0 }}</span>
          </li>
        </ul>
        <p v-else class="hint">{{ label('还没有熟悉的内容，继续复习就会慢慢加上。', 'Nothing familiar yet — keep reviewing and they’ll show up here.') }}</p>
      </template>
      <template v-else-if="recordsView === 'known'">
        <p class="hint">{{ label(`词汇小测试估计大约认识 ${records?.estimated_size ?? plan?.vocabulary?.estimated_size ?? 0} 个表达。`, `Vocabulary checks estimate about ${records?.estimated_size ?? plan?.vocabulary?.estimated_size ?? 0} expressions.`) }}</p>
        <ul v-if="records?.known_words?.length" class="record-list">
          <li v-for="item in records.known_words" :key="item.key" class="record-item">
            <span class="record-emoji" aria-hidden="true">{{ item.emoji }}</span>
            <div>
              <p class="record-main" lang="en">{{ item.text }}</p>
            </div>
          </li>
        </ul>
        <p v-else class="hint">{{ label('还没有记入已知表达，多做词汇小测试就会积累。', 'No known expressions yet — vocabulary checks will fill this list.') }}</p>
      </template>
    </div>
    <p v-if="error" role="alert" class="error">{{ error }} <button :disabled="busy" @click="load">{{ label('重新加载', 'Reload') }}</button></p>
    <p v-if="!plan && busy">{{ label('正在准备探索任务…', 'Preparing your mission…') }}</p>
    <article v-if="current" class="lesson">
      <div class="card-top"><span>{{ current.theme }} · {{ kindLabel(current) }}</span><span class="badge">{{ badgeLabel(current) }}</span></div>
      <template v-if="phase === 'learn'">
        <div class="emoji" aria-hidden="true">{{ cardEmoji(current) }}</div>
        <h3 lang="en" class="sentence-line">
          <template v-if="(current.kind === 'sentence' || current.kind === 'phrase') || (current.probe && (current.kind === 'sentence' || current.kind === 'phrase'))">
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
        <p v-if="(current.kind === 'sentence' || current.kind === 'phrase')" class="hint">{{ label('不认识的词可以点一下，加入学习词库。', 'Tap a word you don’t know to add it to your list.') }}</p>
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
            <div class="spell-actions">
              <button class="primary" type="submit" :disabled="busy || !spellingInput.trim()">{{ label('提交拼写', 'Check spelling') }}</button>
              <button type="button" class="secondary" :disabled="busy" @click="skipSpelling">{{ label('我不会', "I don't know") }}</button>
            </div>
          </form>
          <div class="listen-row">
            <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
            <button class="secondary" :disabled="!audioSupported" @click="speakChinese">{{ listenText('听中文', 'Listen in Chinese', 'zh') }}</button>
          </div>
        </template>
        <template v-else>
          <p v-if="current.challenge || current.review">
            {{ current.challenge ? (current.question || label('选出正确答案', 'Choose the best answer')) : label('这句话或单词，用英语怎么说？', 'How do you say this in English?') }}
          </p>
          <h3 :lang="current.review ? 'zh' : 'en'" class="sentence-line">
            <span v-if="!current.review" class="word-emoji" aria-hidden="true">{{ cardEmoji(current) }}</span>
            <template v-if="!current.review && !current.challenge && (current.kind === 'sentence' || current.kind === 'phrase')">
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
          <p v-if="!current.review && (current.kind === 'sentence' || current.kind === 'phrase')" class="hint">{{ label('不认识的词可以点一下，加入学习词库。', 'Tap a word you don’t know to add it to your list.') }}</p>
          <p v-if="wordSaveMessage" class="hint" role="status">{{ wordSaveMessage }}</p>
          <p v-if="!current.review && wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
          <button v-if="!current.review" class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('再听一次', 'Listen again') }}</button>
          <div class="answers">
            <div v-for="option in choices" :key="option" class="answer-row">
              <button
                type="button"
                class="answer"
                :class="{ wrong: wrongPicks.includes(option) }"
                :disabled="busy || quizLocked || wrongPicks.includes(option)"
                @click="answer(option)"
              >
                <span class="answer-main">{{ option }}</span>
                <span v-if="optionPinyin(option)" class="answer-pinyin" lang="zh-Latn">{{ optionPinyin(option) }}</span>
              </button>
              <button
                v-if="isChineseText(option) && audioSupported"
                type="button"
                class="option-speak"
                :aria-label="optionSpeakLabel(option)"
                :title="optionSpeakLabel(option)"
                :disabled="busy"
                @click.stop="speakOption(option)"
              >{{ speaking && speakingLang === 'zh' && speakingOption === option ? '⏹' : '🔊' }}</button>
            </div>
          </div>
          <p v-if="wrongPicks.length && !quizLocked" class="hint" role="status">{{ label('不对，再选一次', 'Not that one — try again') }}</p>
          <button v-if="quizLocked" class="primary" @click="next">{{ label('继续探索', 'Continue') }} →</button>
        </template>
      </template>
      <div v-else-if="result" class="feedback" aria-live="polite">
        <div class="emoji">{{ result.correct ? '🎉' : '🌱' }}</div>
        <h3>{{ result.correct ? label('答对了！', 'You got it!') : label('没关系，再认识它一次', 'Let’s learn this one again') }}</h3>
        <p>
          <span class="word-emoji" aria-hidden="true">{{ cardEmoji(current) }}</span>
          <strong lang="en">{{ current.text }}</strong> · {{ result.meaning }}
        </p>
        <p class="word-meaning-pinyin" lang="zh-Latn">{{ chinesePinyin(result.meaning) }}</p>
        <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <section v-if="result.correct && isNewWord(current) && current.usage" class="word-usage" aria-label="词组和例句 / Phrase and sentence">
          <h4>{{ label('把新词用起来', 'Use your new word') }}</h4>
          <div v-for="entry in (['phrase', 'sentence'] as const)" :key="entry" class="usage-example" :data-usage="entry">
            <span class="usage-label">{{ entry === 'phrase' ? label('词组', 'Phrase') : label('例句', 'Sentence') }}</span>
            <p lang="en">{{ current.usage[entry].text }}</p>
            <p class="usage-pinyin" lang="zh-Latn">{{ chinesePinyin(current.usage[entry].meaning) }}</p>
            <p class="usage-meaning">{{ current.usage[entry].meaning }}</p>
            <div class="listen-row">
              <button class="secondary" :disabled="!audioSupported || usageAudioPending === current.usage[entry].text" @click="speakUsage(current.usage[entry].text, 'en-US')">{{ usageAudioPending === current.usage[entry].text ? label('朗读中…', 'Reading…') : entry === 'phrase' ? label('听词组', 'Listen to phrase') : label('听例句', 'Listen to sentence') }}</button>
              <button class="secondary" :disabled="!audioSupported" @click="speakUsage(current.usage[entry].meaning, 'zh-CN')">{{ label('听中文', 'Listen in Chinese') }}</button>
            </div>
          </div>
        </section>
        <p v-if="result.coach_tip" class="coach">{{ result.coach_tip }}</p>
        <p v-if="result.correct && !current.probe && !current.challenge">+{{ result.xp }} XP · +{{ result.coins }} {{ label('金币', 'coins') }}<br>{{ label('下次复习', 'Next review') }}：{{ result.next_review }}</p>
        <p v-else-if="isSpellingCard(current)">{{ wordSaveMessage || (result.correct ? label('拼写正确，这个词算学会啦！不发金币。', 'Spelled right — this word counts as learned. No coins.') : label('拼写练习不发金币；答对后才算完成。', 'Spelling awards no coins; a correct spelling finishes the word.')) }}</p>
        <p v-else-if="current.probe && current.kind === 'word'">
          <template v-if="result.promoted_to_spelling">{{ label('英选中已答对 2 次，接下来去拼写吧。', 'Two English→Chinese corrects — spelling is next.') }}</template>
          <template v-else-if="result.correct && result.probe_corrects != null && result.probe_goal != null">
            {{ label(`英选中进度 ${result.probe_corrects}/${result.probe_goal}，不发金币。`, `English→Chinese ${result.probe_corrects}/${result.probe_goal}. No coins.`) }}
          </template>
          <template v-else>{{ label('词汇小测试：英选中答对 2 次后转拼写；拼写答对才算学会。不发金币。', 'Vocabulary check: 2 English→Chinese corrects, then spelling; spelling finishes it. No coins.') }}</template>
        </p>
        <p v-else-if="current.probe">{{ label('这题用来认识你的词汇，不发金币。估计会留在家里，换一个 AI 也接着用。', 'This check estimates your vocabulary and does not award coins. The estimate stays with your family if the AI provider changes.') }}</p>
        <p v-else-if="current.challenge">{{ label('探索挑战由家长发布，不发金币。', 'Lab challenges are published by a parent and do not award coins.') }}</p>
        <p v-else>{{ label('答错不扣分。看看正确表达，稍后再试一次。', 'No points lost. Read the expression and try again shortly.') }}</p>
        <div class="listen-row">
          <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
          <button v-if="canListenChinese" class="secondary" :disabled="!audioSupported" @click="speakChinese">{{ listenText('听中文', 'Listen in Chinese', 'zh') }}</button>
        </div>
        <button class="primary" @click="next">{{ result.correct && isNewWord(current) ? label('读完了，继续', 'Done reading, continue') : label('继续探索', 'Continue') }} →</button>
      </div>
      <p v-if="!audioSupported">{{ label('当前浏览器不支持朗读，可以和家长一起读。', 'Read together with a grown-up; audio is not supported here.') }}</p>
      <p v-if="audioMessage" role="status">{{ audioMessage }}</p>
    </article>
    <div v-else-if="finished" class="lesson done">
      <div class="emoji">🪐</div>
      <h3>{{ label('这一轮完成啦', 'This round is done') }}</h3>
      <p v-if="plan?.fairy_tale_unlocked && !plan?.fairy_tale_heard_today && !fairyTale?.heard">
        {{ label('新词学完啦！先听完今天的童话，再去复习旧词和短句。', 'New words done! Listen to today’s fairy tale first, then review old words and phrases.') }}
      </p>
      <p v-else>
        {{ label('想开口的话，可以用小话筒跟读一句。练开口，不是正式考试。也可以马上再学一轮。', 'Want to speak? Try the mic and read a line aloud — practice only, not a real exam. Or start another round anytime.') }}
      </p>
      <div v-if="fairyTale?.available && fairyTale.tale" class="fairy-box" aria-label="fairy tale">
        <p class="speak-label">📖 {{ label('童话时间', 'Fairy-tale time') }}</p>
        <p class="hint">{{ label('学满今天 10 个新词的奖励：拼音在中文上，对应英文在下。听完英文后再复习。', 'Reward after 10 new words: pinyin above Chinese, matching English below. Reviews unlock after you listen in English.') }}</p>
        <div class="fairy-head">
          <div class="fairy-title fairy-line" role="heading" aria-level="3">
            <p class="fairy-zh-text" lang="zh">
              <span
                v-for="(unit, uIdx) in chinesePinyinUnits(fairyTale.tale.title_zh)"
                :key="`ft-${uIdx}`"
                class="fairy-syllable"
                :class="{ punct: !unit.pinyin }"
              >
                <span v-if="unit.pinyin" class="fairy-pinyin" lang="zh-Latn">{{ unit.pinyin }}</span>
                <span class="fairy-char">{{ unit.char }}</span>
              </span>
            </p>
            <div v-if="fairyTale.tale.title_en" class="fairy-en-text" lang="en">
              <template v-for="(token, tIdx) in sentenceTokens(fairyTale.tale.title_en)" :key="`ft-en-${tIdx}`">
                <template v-if="clickableWord(token)">
                  <span v-if="wordEdge(token).lead">{{ wordEdge(token).lead }}</span>
                  <button
                    type="button"
                    class="word-chip"
                    :class="{ added: taleWordAdded(token) }"
                    :disabled="taleWordBusy"
                    @click="addTaleWord(token)"
                  >{{ wordEdge(token).word }}</button>
                  <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                </template>
                <span v-else>{{ token }}</span>
              </template>
            </div>
          </div>
          <div class="fairy-actions">
            <button type="button" class="secondary" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('zh')">
              {{ fairyReading === 'zh' ? label('⏹ 停止', '⏹ Stop') : label('听中文', 'Listen in Chinese') }}
            </button>
            <button type="button" class="secondary" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('en')">
              {{ fairyReading === 'en' ? label('⏹ 停止', '⏹ Stop') : label('听英文', 'Listen in English') }}
            </button>
          </div>
        </div>
        <p v-if="fairyTale.tale.source" class="fairy-source">{{ fairyTale.tale.source }}<template v-if="fairyTale.tale.source_en"> · {{ fairyTale.tale.source_en }}</template></p>
        <div class="fairy-body">
          <div v-for="(line, idx) in fairyPairedLines(fairyTale.tale)" :key="`zh-${idx}`" class="fairy-line">
            <p class="fairy-zh-text" lang="zh">
              <span
                v-for="(unit, uIdx) in line.units"
                :key="`zhu-${idx}-${uIdx}`"
                class="fairy-syllable"
                :class="{ punct: !unit.pinyin }"
              >
                <span v-if="unit.pinyin" class="fairy-pinyin" lang="zh-Latn">{{ unit.pinyin }}</span>
                <span class="fairy-char">{{ unit.char }}</span>
              </span>
            </p>
            <div v-if="line.en" class="fairy-en-text" lang="en">
              <template v-for="(token, tIdx) in sentenceTokens(line.en)" :key="`fen-${idx}-${tIdx}`">
                <template v-if="clickableWord(token)">
                  <span v-if="wordEdge(token).lead">{{ wordEdge(token).lead }}</span>
                  <button
                    type="button"
                    class="word-chip"
                    :class="{ added: taleWordAdded(token) }"
                    :disabled="taleWordBusy"
                    @click="addTaleWord(token)"
                  >{{ wordEdge(token).word }}</button>
                  <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                </template>
                <span v-else>{{ token }}</span>
              </template>
            </div>
          </div>
          <p class="hint">{{ label('点不认识的英文单词，加入今日新词。', 'Tap an English word you don’t know to add it to today’s new words.') }}</p>
          <p v-if="taleWordMessage" class="hint" role="status">{{ taleWordMessage }}</p>
        </div>
        <p v-if="fairyTale.heard" class="hint" role="status">{{ label('今天的童话已经听过啦，明天还有新故事。', 'You’ve heard today’s tale — a new one tomorrow.') }}</p>
      </div>
      <p v-else-if="newWordsCompleted < newWordsGoal" class="hint">
        {{ label(`再学 ${newWordsGoal - newWordsCompleted} 个新词，就能解锁今日童话。`, `Learn ${newWordsGoal - newWordsCompleted} more new words to unlock today’s fairy tale.`) }}
      </p>
      <p v-else-if="fairyBusy" class="hint">{{ label('正在准备今日童话…', 'Preparing today’s fairy tale…') }}</p>
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
      <p v-if="plan && plan.review_unlocked && plan.due > 0">{{ label('童话听完啦，还有复习可以继续，点再学一轮吧。', 'Fairy tale done — more reviews are waiting. Start another round.') }}</p>
      <p v-else-if="plan?.fairy_tale_unlocked && !(plan.fairy_tale_heard_today || fairyTale?.heard)" class="hint">
        {{ label('听完童话后，才能开始今天的旧词和短句复习。', 'After the fairy tale, today’s old-word and phrase reviews unlock.') }}
      </p>
      <div class="done-actions">
        <button
          type="button"
          class="primary"
          :disabled="busy || (!!plan?.fairy_tale_unlocked && !(plan.fairy_tale_heard_today || fairyTale?.heard) && newWordsCompleted >= newWordsGoal)"
          @click="anotherRound"
        >
          {{ busy
            ? label('正在准备…', 'Preparing…')
            : plan?.fairy_tale_unlocked && !(plan.fairy_tale_heard_today || fairyTale?.heard) && newWordsCompleted >= newWordsGoal
              ? label('先听完童话', 'Listen to the fairy tale first')
              : speakDone ? label('再学一轮', 'Another round') : label('先跳过跟读，再学一轮', 'Skip speak — another round') }} →
        </button>
        <button type="button" class="secondary" @click="goHome">
          {{ label('回星球', 'Back to planet') }}
        </button>
      </div>
    </div>
    <details class="rules"><summary>{{ label('学习与奖励规则', 'Learning and rewards') }}</summary><p>{{ label('一天可以无限量学习。课程卡每天首次答对获得奖励，默认 2 XP + 1 金币；每天课程奖励最多 10 项，之后继续学不再发奖。词汇小测试、探索挑战与跟读打分不发金币。实际奖励以家长设置为准。答错不扣分。', 'You can learn without a daily card limit. Course cards earn a reward once per day: normally 2 XP + 1 coin, up to 10 rewarded course cards; keep practicing after that without more rewards. Vocabulary checks, lab challenges, and speak-along scores do not award coins. Parent settings apply. Mistakes cost nothing.') }}</p><p>{{ label('词汇小测试（单词）：英选中答对 2 次后转为拼写；拼写答对才算学会并记入大约认识。答错会重新累计英选中次数。', 'Word vocabulary checks: 2 English→Chinese corrects unlock spelling; a correct spelling marks the word known. A miss resets the English→Chinese count.') }}</p><p>{{ label('课程卡答对后间隔 1、3、7、14 天复习；答错则缩短间隔，重新巩固。熟悉内容仍会继续复习。', 'Course cards schedule reviews after 1, 3, 7, then 14 days. Mistakes restart the review sequence. Familiar cards continue to return.') }}</p></details>
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
.stats { display: flex; flex-wrap: nowrap; align-items: center; gap: 8px; margin: 20px 0; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
.stat-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #ffffff0a;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.2;
  border: 0;
  color: inherit;
  font: inherit;
  white-space: nowrap;
  flex: 0 0 auto;
  cursor: pointer;
  text-align: left;
  transition: background .15s ease, transform .15s ease;
}
.stat-chip:hover, .stat-chip:focus-visible, .stat-chip.open { background: #ffffff18; outline: none; }
.stat-chip:hover, .stat-chip:focus-visible { transform: translateY(-1px); }
.stat-label { display: inline-flex; align-items: center; line-height: 1.2; }
.stats b { margin: 0; font-weight: 700; line-height: 1.2; }
.records-panel { margin: 0 0 18px; padding: 16px; border-radius: 18px; background: #14242b99; border: 1px solid #77b7d955; }
.records-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.records-head h3 { margin: 0; flex: 1; font-size: 1.05rem; }
.record-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.record-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 14px; background: #ffffff0a; }
.record-emoji { font-size: 1.4rem; width: 2rem; text-align: center; }
.record-main { margin: 0; font-weight: 650; }
.record-sub { margin: 2px 0 0; opacity: 0.8; font-size: 13px; }
.record-meta { display: block; margin-top: 4px; font-size: 12px; opacity: 0.7; }
.record-tag { margin-left: auto; font-size: 12px; padding: 4px 8px; border-radius: 999px; background: #ffffff14; white-space: nowrap; }
.record-tag.done { background: #3dd68c33; color: #8dffc4; }
.record-tag.learning { background: #e0b35a33; color: #ffd89a; }
.record-title-btn { display: block; width: 100%; text-align: left; background: transparent; border: 0; color: inherit; font: inherit; cursor: pointer; padding: 0; }
.record-title-btn:hover .record-main { text-decoration: underline; }
.fairy-box { margin: 18px 0; padding: 16px; border-radius: 16px; background: #1a2f2833; border: 1px solid #77b7d944; text-align: center; }
.records-fairy { margin: 0; }
.fairy-head {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4.5rem;
  margin: 8px 0 4px;
  padding-inline: 0;
}
.fairy-title {
  margin: 0;
  max-width: min(100%, 28rem);
  padding-inline: 0.5rem;
}
.fairy-title.fairy-line { margin-bottom: 0; }
.fairy-title .fairy-zh-text { font-size: 1.2rem; }
.fairy-title .fairy-en-text { font-size: 1rem; opacity: 0.92; }
.fairy-actions {
  position: absolute;
  right: 0;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
}
@media (max-width: 640px) {
  .fairy-head {
    flex-direction: column;
    gap: 10px;
    min-height: 0;
  }
  .fairy-actions {
    position: static;
    transform: none;
    justify-content: center;
  }
}
.fairy-source { margin: 0 0 12px; font-size: 13px; opacity: 0.75; }
.fairy-body { margin: 10px 0; }
.fairy-line { margin-bottom: 12px; }
.fairy-zh-text {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: center;
  gap: 0.12em;
  margin: 0;
  font-size: 1.05rem;
  line-height: 1;
}
.fairy-syllable {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  box-sizing: border-box;
  width: 1.72em;
  min-height: 2.35em;
}
.fairy-syllable.punct {
  width: 0.72em;
  min-height: 1.35em;
}
.fairy-pinyin {
  display: block;
  width: 100%;
  margin: 0 0 0.12em;
  font-size: 0.52em;
  line-height: 1.05;
  letter-spacing: 0.01em;
  text-align: center;
  white-space: nowrap;
  color: #a8d4c8;
  font-weight: 500;
}
.fairy-char {
  display: block;
  width: 100%;
  line-height: 1.15;
  text-align: center;
}
.fairy-en-text {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: baseline;
  gap: 0.12em 0.28em;
  margin: 1px 0 0;
  line-height: 1.45;
  font-size: 0.95rem;
  opacity: 0.9;
  color: #d7ebe4;
  text-align: center;
}
.word-chip.added {
  border-bottom-style: solid;
  border-bottom-color: #3dd68c;
  color: #b8f5de;
}
.records-continue { margin: 4px auto 12px; }
.record-item.learnable { padding: 0; }
.record-learn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 46px;
  padding: 12px;
  text-align: left;
  background: transparent;
  border: 0;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.record-learn .record-tag { margin-left: auto; }
.lesson { background: #071e3280; border: 1px solid #ffffff1a; border-radius: 20px; padding: 24px; text-align: center; }
.card-top { display: flex; gap: 10px; justify-content: space-between; align-items: center; font-size: 13px; text-align: left; }.badge { background: #8cdbc81c; color: #9fe4d3; border-radius: 20px; padding: 5px 12px; white-space: nowrap; }
.emoji { font-size: 52px; margin-top: 20px; line-height: 1; }
.word-emoji { margin-right: 0.28em; font-size: 0.9em; }
h3 { font-size: clamp(24px, 4vw, 36px); margin: 18px 0 8px; overflow-wrap: anywhere; }
.meaning { font-size: 20px; }
/* Companion panels share the child theme, with explicit readable foregrounds. */
.word-usage { width: min(100%, 680px); margin: 12px auto; text-align: left; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.word-usage h4 { grid-column: 1 / -1; margin: 0 0 2px; text-align: center; font-size: 16px; }
.usage-example { padding: 12px; border-radius: 12px; background: #22354d; color: #f4f8fd; border: 1px solid #38516a; overflow-wrap: anywhere; }
.usage-label { font-size: 12px; font-weight: 700; color: #9fe4d3; }
.usage-example p { margin: 6px 0; line-height: 1.45; }
.usage-example p[lang="en"] { font-size: 18px; font-weight: 650; color: #f4f8fd; }
.usage-example .usage-pinyin { color: #adc3d5; font-size: 12px; }
.usage-example .usage-meaning { color: #d5e2ed; font-size: 15px; }
.usage-example .listen-row { justify-content: flex-start; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
.usage-example .secondary { padding: 7px 10px; min-height: 36px; font-size: 12px; color: #e7f4ff; background: #2c4660; border: 1px solid #56738c; }
.usage-example .secondary:hover:not(:disabled) { background: #395b78; border-color: #8cdbc8; }
.word-meaning-pinyin { margin: 4px 0 8px; color: #c3d8e7; font-size: 13px; }
.feedback > .emoji { font-size: 32px; margin-top: 10px; }
.feedback > h3 { font-size: 26px; margin-top: 10px; }
@media(max-width: 620px) { .word-usage { grid-template-columns: minmax(0, 1fr); max-width: 440px; } }
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
.answer-row .answer {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  text-align: center;
  padding: 12px 16px;
  min-height: 56px;
}
.answer-main { font-size: 18px; line-height: 1.25; width: 100%; text-align: center; }
.answer-pinyin {
  font-size: 13px;
  line-height: 1.2;
  letter-spacing: 0.02em;
  color: #9fe4d3;
  opacity: 0.92;
  font-weight: 500;
  width: 100%;
  text-align: center;
}
.answer.wrong,
.answer.wrong:disabled {
  border-color: #f07178;
  background: #f0717828;
  color: #ffd0d3;
  opacity: 1;
  cursor: default;
}
.answer.wrong .answer-pinyin { color: #ffb4b8; }
.option-speak { flex: 0 0 46px; width: 46px; min-width: 46px; padding: 0; font-size: 18px; line-height: 1; }
.answers .answer:hover:not(:disabled), .answers .option-speak:hover:not(:disabled) { border-color: #8cdbc8; background: #8cdbc81c; }
.answers .answer.wrong:hover:not(:disabled) { border-color: #f07178; background: #f0717828; }
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
.spell-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  align-items: stretch;
}
.spell-actions > button {
  width: 100%;
  margin: 0;
  min-height: 46px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
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
@media(max-width: 520px) { .learning { padding: 16px; }.lesson { padding: 18px 14px; }.card-top { flex-wrap: wrap; }.stats { gap: 6px; }.stat-chip { padding: 8px 10px; font-size: 12px; } }
</style>
