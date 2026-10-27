<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, api } from '@/shared/api/client'
import { probeSpeakEngine, speakPhrase, stopSpeaking, unlockSpeak, warmPhrases, warmPhrasesNow } from '@/shared/speak'
import { prepareVoiceUpload } from '@/kid/englishBeta/audio'
import {
  type CardId,
  type LayoutState,
  DEFAULT_LAYOUT,
  GRID_COLS,
  clampCols,
  clampHeight,
  clampScale,
  clearLayout,
  defaultLayout,
  loadLayout,
  saveLayout,
  visibleCards,
} from '@/kid/englishBeta/layout'

type Word = { word: string; understood: boolean; spoken: boolean; used_in_sentence: boolean }
type Turn = { speaker: string; text: string; text_zh?: string | null }
type WordMeaning = { word: string; meaning_zh: string | null; pos_zh: string | null }
type MenuChoice = { code: string; title_zh: string; title_en: string }
const mealEmojis: Record<string, string> = {
  fruit_salad: '🍓', sandwich: '🥪', breakfast: '🍳', vegetable_soup: '🥣',
  pasta: '🍝', rice_bowl: '🍚', picnic: '🧺', smoothie: '🥤',
  pizza: '🍕', green_salad: '🥗', taco: '🌮', pancakes: '🥞',
  stir_fry: '🍛', baked_potato: '🥔',
}
function mealEmoji(code: string): string { return mealEmojis[code] ?? '🍽️' }
type Scene = { code: string; title_zh: string; title_en: string; greeting: string; greeting_zh?: string; words: string[]; menu?: MenuChoice & { ingredients: { word: string; zh: string; icon: string }[] }; missions: { code: string; zh: string; en: string }[] }
type Session = { id: number; scene: string; scene_data?: Scene; started_at: string; greeting: string; greeting_zh?: string; turns: Turn[]; completed_tasks: string[]; listening_target: string | null; listening_progress: { completed: number; total: number }; practice_word: string; tomorrow_options?: MenuChoice[]; tomorrow_choice?: MenuChoice | null }
type WeeklyPlan = { practiced_days: number; target_days: number; remaining_days: number; suggested_scene: string; suggested_word: string }
type Home = { settings: { minutes_per_session: number; days_per_week: number; allow_voice_input: boolean; voice_available: boolean; speech_rate_percent: number }; scenes: Scene[]; selected_scene?: string | null; vocabulary: Word[]; active_session: Session | null; weekly_plan: WeeklyPlan }

const router = useRouter()
const words = ref<Word[]>([])
const scenes = ref<Scene[]>([])
const selectedScene = ref<string | null>(null)
const settings = ref({ minutes_per_session: 20, days_per_week: 5, allow_voice_input: false, voice_available: false, speech_rate_percent: 85 })
const session = ref<Session | null>(null)
const dialogueEl = ref<HTMLElement | null>(null)
const wordBubble = ref<{ word: string; loading: boolean; meaning: WordMeaning | null; top: number; left: number } | null>(null)
const meaningCache = new Map<string, WordMeaning>()
let wordRequest = 0
let speechRequest = 0
let activeSpeech: Promise<boolean> | null = null
let activeSpeechKey = ''
function setDialogueEl(el: Element | ComponentPublicInstance | null) {
  dialogueEl.value = el instanceof HTMLElement ? el : null
}
const weeklyPlan = ref<WeeklyPlan | null>(null)
const input = ref('')
const voiceDraftId = ref('')
const speechDraftText = ref('')
const busy = ref(false)
const preparingVoice = ref(false)
const recording = ref(false)
const error = ref('')
const feedback = ref('')
const helpNotice = ref('')
const finished = ref(false)
const finishSummary = ref<{ score: number | null; score_status: string; score_feedback: string | null; point_reward: number; coin_reward: number } | null>(null)
const layoutEditing = ref(false)
const talkFullscreen = ref(false)
let previousBodyOverflow = ''
const headerActionsReady = ref(false)
const layout = reactive<LayoutState>(defaultLayout())
const draggingId = ref<CardId | null>(null)
const dropTargetId = ref<CardId | null>(null)
let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let recordingTimer: ReturnType<typeof setTimeout> | null = null
let mounted = true
let discardRecording = false
let captureEpoch = 0
let activePointer: {
  kind: 'drag' | 'resize'
  mode?: 'both' | 'width' | 'height'
  id: CardId
  pointerId: number
  startX: number
  startY: number
  startCols: number
  startHeight: number
  startScale: number
} | null = null

const productNames: Record<string, { zh: string; icon: string }> = {
  apple: { zh: '苹果', icon: '🍎' }, banana: { zh: '香蕉', icon: '🍌' },
  milk: { zh: '牛奶', icon: '🥛' }, bread: { zh: '面包', icon: '🍞' },
  water: { zh: '水', icon: '💧' },
  cake: { zh: '蛋糕', icon: '🍰' }, cookie: { zh: '曲奇饼', icon: '🍪' }, muffin: { zh: '玛芬', icon: '🧁' },
  ball: { zh: '球', icon: '⚽' }, puzzle: { zh: '拼图', icon: '🧩' }, robot: { zh: '机器人', icon: '🤖' }, kite: { zh: '风筝', icon: '🪁' },
}
const wordEmojis: Record<string, string> = {
  hello: '👋', hi: '👋', welcome: '🙌', thanks: '🙏', thank: '🙏', please: '🙂',
  yesterday: '🕰️', today: '☀️', tomorrow: '🌅', now: '⏰',
  buy: '🛒', bought: '🛒', supermarket: '🛒', restaurant: '🍽️',
  eat: '🍽️', ate: '🍽️', meal: '🍽️', food: '🥘', ingredient: '🥕', ingredients: '🥕',
  price: '💰', bill: '🧾', dollar: '💵', dollars: '💵',
  find: '🔎', found: '🔎', ask: '💬', order: '🍽️',
  delicious: '😋', great: '🌟', nice: '😊', new: '✨',
  cake: '🍰', cookie: '🍪', muffin: '🧁', ball: '⚽', puzzle: '🧩', robot: '🤖', kite: '🪁',
}
function emojiForWord(word: string, pos?: string | null): string {
  const lower = word.toLowerCase()
  const menuIcon = currentScene.value?.menu?.ingredients.find(item => item.word === lower)?.icon
  if (menuIcon && menuIcon !== '🍽️') return menuIcon
  if (productNames[lower]) return productNames[lower].icon
  if (wordEmojis[lower]) return wordEmojis[lower]
  if (pos?.includes('动词')) return '💬'
  if (pos?.includes('形容词')) return '✨'
  if (pos?.includes('时间')) return '⏰'
  if (pos?.includes('名词')) return '🧩'
  return '🔤'
}
const currentScene = computed(() => session.value?.scene_data ?? scenes.value.find(scene => scene.code === session.value?.scene) ?? null)
const products = computed(() => (currentScene.value?.words ?? []).map(word => currentScene.value?.menu?.ingredients.find(item => item.word === word) ?? { word, ...(productNames[word] ?? { zh: word, icon: '🛒' }) }))
const missions = computed(() => currentScene.value?.missions ?? [])
const nextMission = computed(() => missions.value.find(mission => !session.value?.completed_tasks.includes(mission.code)) ?? null)
const awaitingTomorrowChoice = computed(() => Boolean(session.value?.scene === 'supermarket'
  && currentScene.value?.menu && session.value.completed_tasks.includes('checkout')
  && !session.value.tomorrow_choice))
/** Still collecting or speaking — leave should pause, not end the visit. */
const adventureOpen = computed(() => Boolean(session.value?.listening_target || nextMission.value))
function shoppingPhrase(word: string): string {
  if (['apple', 'orange', 'egg', 'onion', 'avocado'].includes(word)) return `an ${word}`
  if (['banana', 'tomato', 'carrot', 'potato', 'mushroom', 'cucumber', 'tortilla', 'pepper', 'strawberry', 'cake', 'cookie', 'muffin', 'ball', 'puzzle', 'robot', 'kite', 'burger', 'sandwich', 'taco', 'pizza'].includes(word)) return `a ${word}`
  return `some ${word}`
}
const currentStep = computed(() => {
  if (!session.value) return { zh: '选择今天的探索', en: 'Choose today’s adventure', number: '01' }
  if (session.value.listening_target) return { zh: session.value.scene === 'supermarket' && currentScene.value?.menu ? '找齐今天的食材' : '听一听，找目标', en: session.value.scene === 'supermarket' && currentScene.value?.menu ? 'Collect the ingredients' : 'Listen and find', number: '01' }
  if (awaitingTomorrowChoice.value) return { zh: '决定明天吃什么', en: 'Choose tomorrow’s meal', number: '03' }
  if (nextMission.value) return { zh: '和伙伴说一说', en: 'Talk with your partner', number: '02' }
  return { zh: '本次探索完成', en: 'Adventure complete', number: '✓' }
})
const speakingCoach = computed(() => {
  switch (nextMission.value?.code) {
    case 'ask_for_ingredient': return `I would like ${shoppingPhrase(session.value?.practice_word ?? 'food')}, please.`
    case 'ask_for_milk': return 'I would like milk, please.'
    case 'ask_for_item': return `I would like ${shoppingPhrase(session.value?.practice_word ?? 'cake')}, please.`
    case 'checkout': return 'How much is it?'
    case 'order_food': return 'I would like water, please.'
    case 'ask_bill': return 'How much is the bill?'
    default: return ''
  }
})
const turns = computed(() => session.value
  ? [{ speaker: 'partner', text: session.value.greeting, text_zh: session.value.greeting_zh ?? '' }, ...session.value.turns]
  : [])
function sentenceParts(text: string): { text: string; word: boolean }[] {
  return (text.match(/[A-Za-z]+(?:['’][A-Za-z]+)?|[^A-Za-z]+/g) ?? []).map(part => ({
    text: part, word: /^[A-Za-z]/.test(part),
  }))
}
async function inspectWord(word: string, context: string, event: Event) {
  if (layoutEditing.value) return
  const wordEl = event.currentTarget as HTMLElement
  const rect = wordEl.getBoundingClientRect()
  const left = Math.max(12, Math.min(rect.left, window.innerWidth - 292))
  const top = rect.bottom + 8 + 190 < window.innerHeight ? rect.bottom + 8 : Math.max(12, rect.top - 188)
  const key = `${word.toLowerCase()}|${context}`
  const request = ++wordRequest
  wordBubble.value = { word, loading: !meaningCache.has(key), meaning: meaningCache.get(key) ?? null, top, left }
  speak(word, true)
  if (meaningCache.has(key)) return
  try {
    const meaning = await api.post<WordMeaning>('/kid/english-beta/words/lookup', { word, context })
    if (request !== wordRequest) return
    meaningCache.set(key, meaning)
    if (wordBubble.value) wordBubble.value = { ...wordBubble.value, loading: false, meaning }
  } catch {
    if (request === wordRequest && wordBubble.value) wordBubble.value = { ...wordBubble.value, loading: false, meaning: null }
  }
}
function closeWordBubble() { wordRequest++; wordBubble.value = null }
function onWordOutside(event: PointerEvent) {
  const target = event.target as Element | null
  if (wordBubble.value && !target?.closest('.word-bubble, .dialogue-word')) closeWordBubble()
}
function setTalkFullscreen(enabled: boolean) {
  if (talkFullscreen.value === enabled) return
  talkFullscreen.value = enabled
  if (enabled) {
    previousBodyOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = previousBodyOverflow
  }
  closeWordBubble()
}
function onWordEscape(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  if (wordBubble.value) closeWordBubble()
  else setTalkFullscreen(false)
}
watch(() => Boolean(session.value), hasSession => {
  if (!hasSession) setTalkFullscreen(false)
})
watch(layoutEditing, editing => {
  if (editing) setTalkFullscreen(false)
})
watch(
  () => [session.value?.id, session.value?.listening_target, session.value?.turns.length],
  async () => {
    closeWordBubble()
    await nextTick()
    if (dialogueEl.value) dialogueEl.value.scrollTop = dialogueEl.value.scrollHeight
  },
  { flush: 'post' },
)
const recordingMime = typeof MediaRecorder !== 'undefined'
  ? ['audio/webm', 'audio/mp4'].find(type => MediaRecorder.isTypeSupported(type)) : undefined
const voiceAvailable = typeof window !== 'undefined' && window.isSecureContext
  && Boolean(navigator.mediaDevices?.getUserMedia) && Boolean(recordingMime)
  && (recordingMime !== 'audio/mp4' || (typeof AudioContext !== 'undefined' && typeof OfflineAudioContext !== 'undefined'))
const boardCards = computed(() => {
  const cards = visibleCards(layout.order, Boolean(session.value), Boolean(weeklyPlan.value))
  if (session.value?.listening_target) {
    return cards.filter(id => id !== 'talk' && id !== 'missions')
  }
  if (session.value && !layoutEditing.value
    && layout.order.join(',') === DEFAULT_LAYOUT.order.join(',')) {
    return ['intro', 'talk', 'missions', 'words'].filter(id => cards.includes(id as CardId)) as CardId[]
  }
  return session.value ? cards.filter(id => id !== 'listen') : cards
})

function message(e: unknown): string { return e instanceof ApiError ? e.message : '连接失败，请稍后重试。' }
function playSpeech(text: string, lang: 'en-US' | 'zh-CN', afterCurrent = false) {
  const line = text.trim()
  if (!line) return
  const key = `${lang}:${line}`
  if (activeSpeech && activeSpeechKey === key) return
  const previous = afterCurrent ? activeSpeech : null
  const request = ++speechRequest
  const playback = (async () => {
    if (previous) await previous.catch(() => false)
    if (!mounted || request !== speechRequest) return false
    await unlockSpeak()
    if (!mounted || request !== speechRequest) return false
    return lang === 'en-US' ? speakPhrase(line, lang, undefined, settings.value.speech_rate_percent / 100) : speakPhrase(line, lang)
  })()
  activeSpeech = playback
  activeSpeechKey = key
  void playback.finally(() => {
    if (activeSpeech === playback) {
      activeSpeech = null
      activeSpeechKey = ''
    }
  })
}
function speak(text: string, afterCurrent = false) {
  playSpeech(text, 'en-US', afterCurrent)
}
function speakZh(text: string) {
  playSpeech(text, 'zh-CN')
}
function partnerZh(turn: Turn): string {
  return (turn.text_zh ?? '').trim()
}
function persistLayout() {
  saveLayout({
    order: [...layout.order],
    cols: { ...layout.cols },
    heights: { ...layout.heights },
    scale: { ...layout.scale },
  })
}
function applyLoadedLayout(next: LayoutState) {
  layout.order = [...next.order]
  Object.assign(layout.cols, next.cols)
  Object.assign(layout.heights, next.heights)
  Object.assign(layout.scale, next.scale)
}
function toggleLayoutEdit() {
  if (talkFullscreen.value) setTalkFullscreen(false)
  layoutEditing.value = !layoutEditing.value
  if (!layoutEditing.value) persistLayout()
}
function resetLayout() {
  clearLayout()
  applyLoadedLayout(defaultLayout())
}
function cardStyle(id: CardId) {
  const speakingStage = Boolean(session.value && !session.value.listening_target && !layoutEditing.value)
  const listeningStage = Boolean(session.value?.listening_target && !layoutEditing.value)
  const cols = listeningStage && id === 'listen' && layout.cols.listen === DEFAULT_LAYOUT.cols.listen ? 12
    : speakingStage && id === 'talk' && layout.cols.talk === DEFAULT_LAYOUT.cols.talk ? 8
      : (layout.cols[id] ?? GRID_COLS)
  const height = layout.heights[id] ?? 160
  const scale = layout.scale[id] ?? 1
  return {
    '--card-cols': String(cols),
    '--card-height': `${height}px`,
    '--card-scale': String(scale),
    ...(id === 'talk' && height !== DEFAULT_LAYOUT.heights.talk ? { '--talk-card-height': `${height}px` } : {}),
    gridColumn: `span ${cols}`,
    minHeight: `${height}px`,
  }
}
function reorder(from: CardId, to: CardId) {
  if (from === to) return
  const next = [...layout.order]
  const fromIndex = next.indexOf(from)
  const toIndex = next.indexOf(to)
  if (fromIndex < 0 || toIndex < 0) return
  next.splice(fromIndex, 1)
  next.splice(toIndex, 0, from)
  layout.order = next
}
function onDragStart(id: CardId, event: PointerEvent) {
  if (!layoutEditing.value) return
  event.preventDefault()
  event.stopPropagation()
  const target = event.currentTarget as HTMLElement
  target.setPointerCapture(event.pointerId)
  draggingId.value = id
  dropTargetId.value = id
  activePointer = {
    kind: 'drag',
    id,
    pointerId: event.pointerId,
    startX: event.clientX,
    startY: event.clientY,
    startCols: layout.cols[id],
    startHeight: layout.heights[id],
    startScale: layout.scale[id],
  }
}
function onResizeStart(id: CardId, event: PointerEvent, mode: 'both' | 'width' | 'height' = 'both') {
  if (!layoutEditing.value) return
  event.preventDefault()
  event.stopPropagation()
  const target = event.currentTarget as HTMLElement
  target.setPointerCapture(event.pointerId)
  activePointer = {
    kind: 'resize',
    mode,
    id,
    pointerId: event.pointerId,
    startX: event.clientX,
    startY: event.clientY,
    startCols: layout.cols[id],
    startHeight: layout.heights[id],
    startScale: layout.scale[id],
  }
}
function onBoardPointerMove(event: PointerEvent) {
  if (!activePointer || activePointer.pointerId !== event.pointerId) return
  if (activePointer.kind === 'resize') {
    const dx = event.clientX - activePointer.startX
    const dy = event.clientY - activePointer.startY
    const mode = activePointer.mode ?? 'both'
    if (mode === 'width' || mode === 'both') {
      // Horizontal drag changes grid columns → how many cards fit on a row.
      layout.cols[activePointer.id] = clampCols(activePointer.startCols + dx / 48)
    }
    if (mode === 'height' || mode === 'both') {
      layout.heights[activePointer.id] = clampHeight(activePointer.startHeight + dy)
    }
    const widthRatio = layout.cols[activePointer.id] / Math.max(1, activePointer.startCols)
    const heightRatio = layout.heights[activePointer.id] / Math.max(1, activePointer.startHeight)
    const ratio = mode === 'width' ? widthRatio : mode === 'height' ? heightRatio : Math.sqrt(widthRatio * heightRatio)
    layout.scale[activePointer.id] = clampScale(activePointer.startScale * ratio)
    return
  }
  const el = document.elementFromPoint(event.clientX, event.clientY)
  const shell = el?.closest?.('[data-card-id]') as HTMLElement | null
  const targetId = shell?.dataset.cardId as CardId | undefined
  if (targetId && boardCards.value.includes(targetId)) dropTargetId.value = targetId
}
function onBoardPointerUp(event: PointerEvent) {
  if (!activePointer || activePointer.pointerId !== event.pointerId) return
  if (activePointer.kind === 'drag' && dropTargetId.value) {
    reorder(activePointer.id, dropTargetId.value)
  }
  activePointer = null
  draggingId.value = null
  dropTargetId.value = null
  persistLayout()
}
async function warmSessionAudio(active: Session | null) {
  if (!active) return
  const firstLine = active.listening_target ? `Find the ${active.listening_target}.` : active.greeting
  // Warm the current step first; the greeting belongs to the later conversation step.
  const engine = await probeSpeakEngine(firstLine, 'en-US')
  if (engine !== 'neural') return
  warmPhrasesNow([firstLine], 'en-US')
  if (!active.listening_target) warmPhrases([active.greeting], 'en-US')
  const zhLines = [active.greeting_zh, ...active.turns.map(turn => turn.text_zh)].filter((line): line is string => Boolean(line?.trim()))
  if (zhLines.length) warmPhrases(zhLines, 'zh-CN')
}
function applySession(data: Session, opts: { autoSpeak?: boolean } = {}) {
  session.value = data
  feedback.value = ''
  if (opts.autoSpeak && data.listening_target) {
    speak(`Find the ${data.listening_target}.`)
  } else {
    void warmSessionAudio(data)
  }
}
async function load() {
  try {
    const data = await api.get<Home>('/kid/english-beta')
    settings.value = data.settings
    scenes.value = data.scenes
    selectedScene.value = data.selected_scene ?? null
    words.value = data.vocabulary
    session.value = data.active_session
    weeklyPlan.value = data.weekly_plan
    void warmSessionAudio(data.active_session)
  } catch (e) { error.value = message(e) }
}
function releaseMicrophone() {
  if (recordingTimer) clearTimeout(recordingTimer)
  recordingTimer = null
  stream?.getTracks().forEach(track => track.stop())
  stream = null
  recording.value = false
}
function cancelVoice() {
  captureEpoch++
  preparingVoice.value = false
  discardRecording = true
  if (recorder?.state === 'recording') recorder.stop()
  releaseMicrophone()
  feedback.value = '已取消录音。\nRecording cancelled.'
}
function onVisibilityChange() { if (document.hidden && recording.value) cancelVoice() }
onMounted(() => {
  mounted = true
  headerActionsReady.value = Boolean(document.getElementById('kid-header-actions'))
  applyLoadedLayout(loadLayout())
  document.addEventListener('visibilitychange', onVisibilityChange)
  document.addEventListener('pointerdown', onWordOutside)
  document.addEventListener('keydown', onWordEscape)
  void load()
})
onBeforeUnmount(() => {
  mounted = false
  setTalkFullscreen(false)
  speechRequest++
  document.removeEventListener('visibilitychange', onVisibilityChange)
  document.removeEventListener('pointerdown', onWordOutside)
  document.removeEventListener('keydown', onWordEscape)
  cancelVoice()
  stopSpeaking()
})
async function start(scene: string) {
  if (layoutEditing.value) return
  void unlockSpeak()
  busy.value = true; error.value = ''
  helpNotice.value = ''
  try {
    const created = await api.post<Session>('/kid/english-beta/sessions', { scene })
    applySession(created, { autoSpeak: true })
    finished.value = false
    await load()
  } catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
async function choose(word: string) {
  if (!session.value || busy.value || layoutEditing.value) return
  void unlockSpeak()
  busy.value = true; feedback.value = ''; error.value = ''
  const sessionId = session.value.id
  try {
    const data = await api.post<{ correct: boolean; target: string; next_target: string | null; listening_progress: { completed: number; total: number }; completed_tasks: string[] }>(`/kid/english-beta/sessions/${sessionId}/listen`, { answer: word })
    if (session.value?.id !== sessionId) return
    feedback.value = data.correct
      ? (data.next_target ? '答对了！听下一个目标。\nCorrect! Listen for the next item.' : '')
      : '再听一次，慢慢找。\nListen again and try once more.'
    session.value.listening_target = data.next_target
    session.value.listening_progress = data.listening_progress
    session.value.completed_tasks = data.completed_tasks
    if (data.next_target) {
      speak(`Find the ${data.next_target}.`)
      return
    }
    await load()
    if (data.correct && !data.next_target && session.value?.id === sessionId) {
      // Enter speak step with the partner's opening line, not the coach hint.
      if (session.value.greeting && !awaitingTomorrowChoice.value) speak(session.value.greeting)
      await nextTick()
      document.getElementById('beta-talk')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  } catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
async function send(suggested?: string) {
  if (!session.value || !(suggested ?? input.value).trim() || busy.value || recording.value || preparingVoice.value || layoutEditing.value) return
  void unlockSpeak()
  busy.value = true; error.value = ''
  helpNotice.value = ''
  const text = (suggested ?? input.value).trim()
  const fromVoice = !suggested && Boolean(voiceDraftId.value && text === speechDraftText.value)
  try {
    const result = await api.post<{ text: string; text_zh?: string; completed_tasks: string[] }>(`/kid/english-beta/sessions/${session.value.id}/turns`, {
      text, input_mode: fromVoice ? 'speech' : 'text',
      ...(fromVoice ? { voice_draft_id: voiceDraftId.value } : {}),
    })
    session.value.turns.push(
      { speaker: 'child', text },
      { speaker: 'partner', text: result.text, text_zh: result.text_zh ?? '' },
    )
    warmPhrasesNow([result.text], 'en-US')
    if (result.text_zh) warmPhrasesNow([result.text_zh], 'zh-CN')
    speak(result.text)
    session.value.completed_tasks = result.completed_tasks
    input.value = ''; voiceDraftId.value = ''; speechDraftText.value = ''
    await load()
  } catch (e) { error.value = message(e); if (e instanceof ApiError && e.status === 409) await load() }
  finally { busy.value = false }
}
async function helpMe() {
  if (!session.value || session.value.listening_target || busy.value || recording.value || preparingVoice.value || layoutEditing.value) return
  void unlockSpeak()
  const sessionId = session.value.id
  busy.value = true; error.value = ''; helpNotice.value = ''
  try {
    const hint = await api.post<{ text: string; text_zh: string; provider: string }>(`/kid/english-beta/sessions/${sessionId}/hint`)
    if (!mounted || session.value?.id !== sessionId) return
    session.value.turns.push({ speaker: 'hint', text: hint.text, text_zh: hint.text_zh })
    helpNotice.value = '伙伴给你一句示范，试着自己说出来或输入。\nHere is a line you can try saying.'
    speak(hint.text)
    await load()
  } catch (e) {
    error.value = message(e)
    if (e instanceof ApiError && e.status === 409) await load()
  } finally { busy.value = false }
}
async function beginVoice() {
  if (!settings.value.voice_available || !voiceAvailable || recording.value || preparingVoice.value || busy.value || layoutEditing.value) return
  const epoch = ++captureEpoch
  preparingVoice.value = true
  stopSpeaking()
  void unlockSpeak()
  error.value = ''; voiceDraftId.value = ''; speechDraftText.value = ''
  discardRecording = false
  try {
    const acquired = await navigator.mediaDevices.getUserMedia({ audio: true })
    if (!mounted || epoch !== captureEpoch || !session.value) { acquired.getTracks().forEach(track => track.stop()); return }
    preparingVoice.value = false
    stream = acquired
    const mimeType = recordingMime
    if (!mimeType) throw new Error('Unsupported audio format')
    const chunks: Blob[] = []
    recorder = new MediaRecorder(stream, { mimeType })
    const recordingSessionId = session.value.id
    const startedAt = Date.now()
    recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data) }
    recorder.onstop = async () => {
      releaseMicrophone()
      if (discardRecording || !mounted || session.value?.id !== recordingSessionId) return
      const audio = new Blob(chunks, { type: mimeType })
      if (Date.now() - startedAt < 500 || audio.size < 32 || audio.size > 2 * 1024 * 1024) { error.value = '录音太短或太大，请重试。'; return }
      busy.value = true
      try {
        const upload = await prepareVoiceUpload(audio, mimeType)
        if (upload.blob.size > 2 * 1024 * 1024) throw new Error('Audio too large')
        if (!mounted || session.value?.id !== recordingSessionId) return
        const form = new FormData()
        form.append('audio', upload.blob, upload.filename)
        form.append('duration_ms', String(Math.min(15000, Math.max(300, Date.now() - startedAt))))
        const result = await api.upload<{ text: string; voice_draft_id: string }>(`/kid/english-beta/sessions/${recordingSessionId}/transcribe`, form)
        if (!mounted || session.value?.id !== recordingSessionId) return
        input.value = result.text; speechDraftText.value = result.text; voiceDraftId.value = result.voice_draft_id
        feedback.value = '请确认识别的句子，再点发送。\nCheck the words, then tap Send.'
      } catch (e) { error.value = e instanceof ApiError ? message(e) : '录音格式处理失败，请重试或使用文字输入。' }
      finally { busy.value = false }
    }
    recorder.onerror = () => { cancelVoice(); error.value = '录音中断，请重试或使用文字输入。' }
    stream.getTracks().forEach(track => track.addEventListener('ended', () => {
      if (recording.value) { cancelVoice(); error.value = '麦克风已断开，请重试。' }
    }, { once: true }))
    recorder.start()
    recording.value = true
    recordingTimer = setTimeout(() => recorder?.state === 'recording' && recorder.stop(), 15000)
  } catch {
    if (epoch !== captureEpoch) return
    preparingVoice.value = false
    releaseMicrophone()
    error.value = '无法使用麦克风，请检查权限或用文字输入。'
  }
}
function stopVoice() { if (recorder?.state === 'recording') recorder.stop() }
function stopCapture() {
  if (recording.value || preparingVoice.value) {
    captureEpoch++
    preparingVoice.value = false
    discardRecording = true
    if (recorder?.state === 'recording') recorder.stop()
    releaseMicrophone()
  }
  stopSpeaking()
}
/** End the visit for scoring. Progress for a finished visit starts fresh next time. */
async function finish(opts: { announce?: boolean } = {}) {
  if (!session.value || busy.value || layoutEditing.value) return
  stopCapture()
  busy.value = true; error.value = ''
  helpNotice.value = ''
  const sessionId = session.value.id
  try {
    const result = await api.post<{ score: number | null; score_status: string; score_feedback: string | null; point_reward: number; coin_reward: number }>(`/kid/english-beta/sessions/${sessionId}/finish`)
    finishSummary.value = result
    session.value = null
    finished.value = opts.announce !== false
    feedback.value = ''
    await load()
  } catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
/**
 * Mid-visit: keep the server session and leave — re-entering restores progress.
 * Visit complete (or choosing tomorrow’s meal): end and score.
 */
async function saveAndLeave(opts: { announce?: boolean } = {}) {
  if (!session.value || busy.value || layoutEditing.value) return
  if (adventureOpen.value) {
    stopCapture()
    error.value = ''
    helpNotice.value = ''
    feedback.value = ''
    finished.value = false
    await router.push({ name: 'kid-home' })
    return
  }
  await finish(opts)
}
async function leaveScene() {
  await saveAndLeave({ announce: false })
}
</script>

<template>
  <main class="beta" :class="{ editing: layoutEditing }">
    <Teleport v-if="headerActionsReady" to="#kid-header-actions">
      <button
        type="button"
        class="round-btn"
        :class="{ on: layoutEditing }"
        :aria-label="layoutEditing ? '完成布局' : '调整布局'"
        :title="layoutEditing ? '完成布局 / Done' : '调整布局 / Layout'"
        @click="toggleLayoutEdit"
      >{{ layoutEditing ? '✓' : '📐' }}</button>
      <button
        v-if="layoutEditing"
        type="button"
        class="round-btn"
        aria-label="恢复默认布局"
        title="恢复默认 / Reset"
        @click="resetLayout"
      >↺</button>
    </Teleport>
    <div v-else class="layout-fallback">
      <button
        type="button"
        class="round-btn"
        :class="{ on: layoutEditing }"
        :aria-label="layoutEditing ? '完成布局' : '调整布局'"
        @click="toggleLayoutEdit"
      >{{ layoutEditing ? '✓' : '📐' }}</button>
      <button v-if="layoutEditing" type="button" class="round-btn" aria-label="恢复默认布局" @click="resetLayout">↺</button>
    </div>

    <nav class="dir" aria-label="英语星球 Beta">
      <span class="tag">Beta</span>
      <div class="dir-text">
        <strong>英语星球 Beta</strong>
        <small>English Planet Beta</small>
      </div>
    </nav>

    <p v-if="layoutEditing" class="layout-hint">
      拖动手柄换位置；右下角拖动可改宽高。变窄可一行多卡，拉宽可一行少卡；上下拉改高度，内容会一起缩放。<br>
      Drag to reorder. Corner resize: width changes cards-per-row, height scales content.
    </p>

    <div
      class="board"
      @pointermove="onBoardPointerMove"
      @pointerup="onBoardPointerUp"
      @pointercancel="onBoardPointerUp"
    >
      <div
        v-for="id in boardCards"
        :key="id"
        class="card-shell"
        :class="[
          id,
          {
            dragging: draggingId === id,
            'drop-target': dropTargetId === id && draggingId && dropTargetId !== draggingId,
            'is-focus': session && ((id === 'listen' && session.listening_target) || (id === 'talk' && !session.listening_target)),
            'is-done': session && id === 'listen' && !session.listening_target,
            fullscreen: id === 'talk' && talkFullscreen,
          },
        ]"
        :data-card-id="id"
        :style="cardStyle(id)"
      >
        <button
          v-if="layoutEditing"
          type="button"
          class="drag-handle"
          aria-label="拖动卡片"
          @pointerdown="onDragStart(id, $event)"
        >⋮⋮</button>
        <button
          v-if="layoutEditing"
          type="button"
          class="edge-handle edge-east"
          aria-label="调整宽度"
          title="改宽度 / Width"
          @pointerdown="onResizeStart(id, $event, 'width')"
        />
        <button
          v-if="layoutEditing"
          type="button"
          class="edge-handle edge-south"
          aria-label="调整高度"
          title="改高度 / Height"
          @pointerdown="onResizeStart(id, $event, 'height')"
        />
        <button
          v-if="layoutEditing"
          type="button"
          class="scale-handle"
          aria-label="缩放宽高"
          title="宽高一起调 / Resize"
          @pointerdown="onResizeStart(id, $event, 'both')"
        />

        <section v-if="id === 'intro'" class="panel panel-face">
          <div class="intro-copy">
            <span class="eyebrow">英语星球 Beta · 第 {{ currentStep.number }} 步</span>
            <h1>{{ currentScene?.title_zh ?? currentStep.zh }}<br><small>{{ currentScene?.title_en ?? currentStep.en }}</small></h1>
            <p v-if="session" class="intro-next">现在：{{ currentStep.zh }}<br><small>Now: {{ currentStep.en }}</small></p>
            <p v-else class="intro-next">今天从两个场景中选一个学习。<br><small>Choose one of today’s two scenes.</small></p>
          </div>
          <div class="intro-side">
            <span class="time-chip">建议 {{ settings.minutes_per_session }} 分钟 / 次</span>
          <button v-if="session" type="button" class="leave" :disabled="busy || recording || preparingVoice || layoutEditing" @click="leaveScene">{{ adventureOpen ? '← 保存并离开 / Save & leave' : '← 结束本次 / Finish visit' }}</button>
          </div>
        </section>

        <section v-else-if="id === 'plan' && weeklyPlan" class="panel panel-face">
          <span class="card-kicker">本周目标 / Weekly goal</span>
          <p class="plan-count"><strong>{{ weeklyPlan.practiced_days }}</strong><span>/ {{ weeklyPlan.target_days }} 天</span></p>
          <p class="plan-sub">还需 {{ weeklyPlan.remaining_days }} 天 · 建议去{{ weeklyPlan.suggested_scene === 'restaurant' ? '餐厅' : '超市' }}，试试 {{ weeklyPlan.suggested_word }}。<br><small>{{ weeklyPlan.remaining_days }} days left this week.</small></p>
        </section>

        <section v-else-if="id === 'scenes'" class="scene-grid panel-face" aria-label="探索场景 / Explore scenes">
          <div class="scene-intro"><h2>选择今天的场景</h2><p>每天两个不同场景，选一个开始学习。<br><small>Choose one of today’s two scenes.</small></p></div>
          <p v-if="selectedScene" class="scene-note">今天已选择一个星球，可以继续练习它。<br>Continue today’s chosen planet.</p>
          <button
            v-for="scene in scenes"
            :key="scene.code"
            class="scene-card"
            :class="{ current: session?.scene === scene.code }"
            :disabled="busy || layoutEditing || session?.scene === scene.code || Boolean(selectedScene && selectedScene !== scene.code)"
            @click="start(scene.code)"
          >
            <strong>{{ scene.title_zh }}</strong>
            <small>{{ scene.title_en }}</small>
            <span v-if="scene.menu" class="menu-label">{{ scene.code === 'restaurant' ? '今日餐单' : '今日主题' }}：{{ scene.menu.title_zh }}<br>Today: {{ scene.menu.title_en }}</span>
            <span v-if="session?.scene === scene.code" class="hint">正在探索 / Exploring</span>
            <span v-else-if="selectedScene && selectedScene !== scene.code" class="hint">明天再来 / Come back tomorrow</span>
            <span v-else-if="weeklyPlan?.suggested_scene === scene.code" class="hint">建议下一站 / Suggested</span>
            <span class="cta">{{ selectedScene && selectedScene !== scene.code ? '今天已选择 / Chosen' : session?.scene === scene.code ? '正在学习 / Learning' : '开始探索 / Start' }}</span>
          </button>
        </section>

        <section v-else-if="id === 'listen' && session" class="panel panel-face listen">
          <span class="card-kicker">第 1 步 · 听辨 / Listen</span>
          <h2 v-if="currentScene?.menu && session.scene === 'supermarket'">今天做 {{ currentScene.menu.title_zh }}<br><small>Today we will make {{ currentScene.menu.title_en }}</small></h2>
          <h2 v-else-if="currentScene?.menu && session.scene === 'restaurant'">今日餐单：{{ currentScene.menu.title_zh }}<br><small>Today’s menu: {{ currentScene.menu.title_en }}</small></h2>
          <h2 v-else>听一听，找目标<br><small>Listen and find the item</small></h2>
          <p class="step-progress">{{ session.scene === 'supermarket' && currentScene?.menu ? '食材已找到' : '已找到' }} {{ session.listening_progress.completed }}/{{ session.listening_progress.total }}<br>Found {{ session.listening_progress.completed }} of {{ session.listening_progress.total }}</p>
          <template v-if="session.listening_target">
            <p>{{ session.scene === 'supermarket' ? '先听英语，找齐食材，然后再和伙伴对话。' : '先听英语，找到目标，然后再和伙伴对话。' }}<br>Listen and find the items before talking.</p>
            <button class="primary" :disabled="busy || layoutEditing" @click="speak(`Find the ${session.listening_target}.`)">🔊 播放 / Play</button>
            <div class="products">
              <button v-for="product in products" :key="product.word" :disabled="busy || layoutEditing" @click="choose(product.word)">
                <span aria-hidden="true">{{ product.icon }}</span>
                <strong>{{ product.zh }}</strong>
                <small>{{ product.word }}</small>
              </button>
            </div>
          </template>
          <p v-else class="notice" role="status">找齐了！现在可以和伙伴聊一聊。<br>All items are ready! Now talk with your partner.</p>
          <p v-if="feedback" class="notice" role="status">{{ feedback }}</p>
        </section>

        <section v-else-if="id === 'missions' && session" class="panel panel-face missions">
          <span class="card-kicker">探索路线 / Mission path</span>
          <h2>场景任务<br><small>Scene missions</small></h2>
          <ul>
            <li
              v-for="mission in missions"
              :key="mission.code"
              :aria-label="`${session.completed_tasks.includes(mission.code) ? '已完成' : '未完成'}：${mission.zh}`"
            >
              <span aria-hidden="true">{{ session.completed_tasks.includes(mission.code) ? '✓' : '○' }}</span>
              {{ mission.zh }}<br><small>{{ mission.en }}</small>
            </li>
          </ul>
        </section>

        <section v-else-if="id === 'talk' && session" id="beta-talk" class="panel panel-face talk" :class="{ 'awaiting-choice': awaitingTomorrowChoice }">
          <div class="talk-top">
          <div class="talk-header">
            <span class="card-kicker">{{ awaitingTomorrowChoice ? '第 3 步 · 选餐 / Choose a meal' : '第 2 步 · 开口 / Speak' }}</span>
            <button
              type="button"
              class="talk-fullscreen"
              :aria-label="talkFullscreen ? '退出全屏' : '全屏显示对话'"
              :aria-pressed="talkFullscreen"
              :disabled="layoutEditing"
              @click="setTalkFullscreen(!talkFullscreen)"
            >
              <span class="talk-fullscreen-icon" aria-hidden="true">{{ talkFullscreen ? '↙' : '⛶' }}</span>
              <span class="talk-fullscreen-copy">
                {{ talkFullscreen ? '退出全屏' : '全屏' }}
                <small>{{ talkFullscreen ? 'Exit' : 'Full screen' }}</small>
              </span>
            </button>
          </div>
          <h2 v-if="awaitingTomorrowChoice">先选明天吃什么<br><small>Choose tomorrow’s meal first</small></h2>
          <h2 v-else>和伙伴说一说<br><small>Talk with your partner</small></h2>
          <p v-if="session.listening_target" class="notice">先完成“听一听，找目标”，再和伙伴说话。<br>Find the items first, then talk with your partner.</p>
          <p v-else-if="!awaitingTomorrowChoice && nextMission" class="notice">下一步：{{ nextMission.zh }}<br>{{ nextMission.en }} · 试着说 “{{ speakingCoach }}”</p>
          <p v-else-if="!awaitingTomorrowChoice" class="notice" role="status">场景任务已完成！可以结束本次探索。<br>Scene missions complete! You can finish this visit.</p>
          <p v-if="session.scene === 'supermarket' && session.completed_tasks.includes('checkout') && session.tomorrow_choice" class="tomorrow-selected" role="status"><span aria-hidden="true">{{ mealEmoji(session.tomorrow_choice.code) }}</span> 明天我们做 {{ session.tomorrow_choice.title_zh }}！<br>Tomorrow we will make {{ session.tomorrow_choice.title_en }}.</p>
          </div>
          <div v-if="awaitingTomorrowChoice" class="dialogue dialogue-waiting" role="status">
            <strong>明天想吃什么？<br><small>What would you like to eat tomorrow?</small></strong>
            <div class="tomorrow-options">
              <button v-for="choice in session.tomorrow_options ?? []" :key="choice.code" type="button" :disabled="busy || layoutEditing" @click="send(`I would like ${choice.title_en} tomorrow.`)"><span class="meal-emoji" aria-hidden="true">{{ mealEmoji(choice.code) }}</span>{{ choice.title_zh }}<small>{{ choice.title_en }}</small></button>
            </div>
          </div>
          <div v-else :ref="setDialogueEl" class="dialogue" aria-live="polite" @scroll="closeWordBubble">
            <p v-for="(turn, i) in turns" :key="i" :class="{ 'hint-line': turn.speaker === 'hint' }">
              <strong>{{ turn.speaker === 'child' ? '我 / Me' : turn.speaker === 'hint' ? '伙伴提示 / Hint' : '伙伴 / Partner' }}：</strong><template v-if="turn.speaker === 'child'">{{ turn.text }}</template>
              <template v-else><template v-for="(part, partIndex) in sentenceParts(turn.text)" :key="partIndex"><span v-if="part.word" class="dialogue-word" role="button" tabindex="0" :aria-label="`点读并查看 ${part.text} 的词义`" @click="inspectWord(part.text, turn.text, $event)" @keydown.enter.prevent="inspectWord(part.text, turn.text, $event)" @keydown.space.prevent="inspectWord(part.text, turn.text, $event)">{{ part.text }}</span><span v-else>{{ part.text }}</span></template></template>
              <span v-if="turn.speaker !== 'child'" class="speak-pair">
                <button class="icon-speak" type="button" :aria-label="`播放英语：${turn.text}`" title="英语" :disabled="layoutEditing" @click="speak(turn.text)">🔊</button>
                <button class="icon-speak" type="button" :aria-label="partnerZh(turn) ? `播放中文：${partnerZh(turn)}` : '暂无中文翻译'" title="中文翻译" :disabled="layoutEditing || !partnerZh(turn)" @click="speakZh(partnerZh(turn))">🔈</button>
              </span>
            </p>
          </div>
          <Teleport to="body">
            <div v-if="wordBubble" class="word-bubble" role="dialog" :aria-label="`${wordBubble.word} 的词义`" :style="{ top: `${wordBubble.top}px`, left: `${wordBubble.left}px` }">
              <button type="button" class="word-bubble-close" aria-label="关闭词义" @click="closeWordBubble">×</button>
              <div class="word-bubble-head">
                <span class="word-bubble-emoji" aria-hidden="true">{{ emojiForWord(wordBubble.word, wordBubble.meaning?.pos_zh) }}</span>
                <div class="word-bubble-title">
                  <strong>{{ wordBubble.word }}</strong>
                  <span v-if="wordBubble.meaning?.pos_zh" class="word-bubble-pos">{{ wordBubble.meaning.pos_zh }}</span>
                </div>
              </div>
              <p v-if="wordBubble.loading" role="status">正在查词…</p>
              <template v-else-if="wordBubble.meaning?.meaning_zh">
                <p>{{ wordBubble.meaning.meaning_zh }}</p>
              </template>
              <p v-else>暂时没有释义，点词仍可听发音。</p>
              <div class="word-bubble-audio">
                <button type="button" class="word-bubble-play" :aria-label="`播放英文：${wordBubble.word}`" @click="speak(wordBubble.word)">🔊 英文</button>
                <button type="button" class="word-bubble-play" :aria-label="wordBubble.meaning?.meaning_zh ? `播放中文：${wordBubble.meaning.meaning_zh}` : '暂无中文释义'" :disabled="!wordBubble.meaning?.meaning_zh" @click="speakZh(wordBubble.meaning?.meaning_zh ?? '')">🔈 中文</button>
              </div>
            </div>
          </Teleport>
          <div class="talk-bottom">
          <form v-if="!awaitingTomorrowChoice" @submit.prevent="send()">
            <label for="beta-input">说一句英语<br>Say something in English</label>
            <input id="beta-input" v-model="input" maxlength="240" autocomplete="off" :disabled="busy || recording || preparingVoice || layoutEditing">
            <p v-if="helpNotice" class="notice help-notice" role="status">{{ helpNotice }}</p>
            <p v-if="recording || preparingVoice" class="notice" role="status">{{ preparingVoice ? '正在打开麦克风…' : '正在录音，最多 15 秒。' }}<br>{{ preparingVoice ? 'Opening microphone…' : 'Recording, up to 15 seconds.' }}</p>
            <div class="actions" :class="{ 'without-voice': !(voiceAvailable && settings.voice_available) }">
              <button v-if="recording || preparingVoice" type="button" @click="cancelVoice">取消录音<br>Cancel</button>
              <button v-else class="help" type="button" :disabled="busy || layoutEditing" @click="helpMe">我不会<br>Help me</button>
              <button v-if="voiceAvailable && settings.voice_available" type="button" :disabled="busy || preparingVoice || layoutEditing" @click="recording ? stopVoice() : beginVoice()">{{ recording ? '停止并识别' : '🎙️ 说话' }}<br>{{ recording ? 'Transcribe' : 'Speak' }}</button>
              <button class="primary" :disabled="busy || recording || preparingVoice || layoutEditing || !input.trim()">发送<br>Send</button>
              <button class="finish" type="button" :disabled="busy || recording || preparingVoice || layoutEditing" @click="saveAndLeave()">
                <template v-if="adventureOpen">保存并离开<br>Save and leave</template>
                <template v-else>结束并保存<br>Finish and save</template>
              </button>
            </div>
          </form>
          <p v-else class="talk-choice-prompt">请先从中间选一个明天的餐食。<br>Choose tomorrow’s meal above first.</p>
          <button v-if="awaitingTomorrowChoice" class="finish" type="button" :disabled="busy || recording || preparingVoice || layoutEditing" @click="saveAndLeave()">结束并保存<br>Finish and save</button>
          </div>
        </section>

        <section v-else-if="id === 'words'" class="panel panel-face words">
          <h2>词汇进度 <small>Word progress</small></h2>
          <div class="word-list">
            <p v-for="word in words" :key="word.word">
              <strong>{{ word.word }}</strong>
              <span class="word-flags">
                <span>听懂 {{ word.understood ? '✓' : '○' }}</span>
                <span>说出 {{ word.spoken ? '✓' : '○' }}</span>
                <span>句中 {{ word.used_in_sentence ? '✓' : '○' }}</span>
              </span>
            </p>
          </div>
        </section>
      </div>
    </div>

    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <p v-if="settings.allow_voice_input && (!settings.voice_available || !voiceAvailable)" class="notice banner">{{ !voiceAvailable ? '当前浏览器需要安全连接和麦克风支持；请用文字输入。' : '语音伙伴暂不可用，可以继续打字。' }}<br>Voice partner is unavailable; you can type instead.</p>
    <p v-if="finished" class="notice banner" role="status">本次探索已记录！<br>Session saved!<template v-if="finishSummary?.score !== null && finishSummary?.score !== undefined"><br>AI 评分 {{ finishSummary.score }} 分 · +{{ finishSummary.point_reward }} 积分 · +{{ finishSummary.coin_reward }} 金币<br>{{ finishSummary.score_feedback }}</template><template v-else-if="finishSummary?.score_status === 'unavailable'"><br>评分暂不可用，本次没有发放奖励。</template></p>
  </main>
</template>

<style scoped>
.beta {
  --beta-pad: clamp(12px, 2.2vw, 28px);
  --beta-gap: clamp(12px, 1.8vw, 20px);
  --beta-radius: clamp(16px, 2vw, 24px);
  --beta-font: clamp(15px, 1.55vw + 0.55rem, 20px);
  --beta-h2: clamp(1.15rem, 1.2vw + 0.85rem, 1.65rem);
  --beta-btn: clamp(44px, 5.5vw, 56px);
  width: min(100%, 1120px);
  margin: 0 auto;
  padding: var(--beta-pad) var(--beta-pad) calc(var(--beta-pad) + 16px);
  color: white;
  font-size: var(--beta-font);
  line-height: 1.5;
  box-sizing: border-box;
}
.layout-fallback {
  display: flex;
  gap: 8px;
  margin-bottom: var(--beta-gap);
}
.dir {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 var(--beta-gap);
  text-align: left;
}
.dir-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  line-height: 1.2;
}
.dir-text strong {
  font-size: clamp(1.05rem, 1.2vw + 0.7rem, 1.35rem);
  font-weight: 800;
}
.dir-text small {
  font-size: 0.78em;
  opacity: 0.75;
  font-weight: 500;
}
.round-btn {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 22px;
  cursor: pointer;
  display: grid;
  place-items: center;
  padding: 0;
  line-height: 1;
}
.round-btn.on {
  background: #ffe17a;
  color: #20263a;
}
.layout-hint {
  margin: 0 0 var(--beta-gap);
  font-size: 0.85em;
  opacity: 0.85;
  white-space: pre-line;
}
.board {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: clamp(14px, 2vw, 22px);
  align-items: stretch;
}
.card-shell {
  position: relative;
  grid-column: span 12;
  min-width: 0;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
}
.card-shell.dragging { opacity: 0.72; z-index: 2; }
.card-shell.drop-target .panel-face {
  outline: 2px dashed #ffe17a;
  outline-offset: 2px;
}
.panel-face {
  flex: 1;
  height: 100%;
  min-height: 0;
  overflow: auto;
  padding: calc(clamp(14px, 2vw, 28px) * var(--card-scale, 1));
  border-radius: var(--beta-radius);
  background: #292d6a;
  border: 1px solid rgba(255, 255, 255, 0.15);
  box-shadow: 0 12px 30px rgba(10, 12, 48, 0.18);
  font-size: calc(1em * var(--card-scale, 1));
  box-sizing: border-box;
}
.beta:not(.editing) .card-shell.talk .panel-face.talk {
  /* Follow the visible viewport, including iPad split view and rotation. */
  height: var(--talk-card-height, clamp(620px, calc(100dvh - 48px), 1200px));
  max-height: calc(100dvh - 24px);
  display: grid;
  grid-template-rows: max-content minmax(0, 1fr) max-content;
  gap: calc(12px * var(--card-scale, 1));
  overflow: hidden;
}
.card-shell.talk.fullscreen {
  position: fixed;
  inset: 0;
  z-index: 1000;
  min-height: 0 !important;
  padding: max(8px, env(safe-area-inset-top)) max(8px, env(safe-area-inset-right)) max(8px, env(safe-area-inset-bottom)) max(8px, env(safe-area-inset-left));
  background: #1b2153;
}
.beta:not(.editing) .card-shell.talk.fullscreen .panel-face.talk {
  height: 100%;
  max-height: none;
}
.talk-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.panel-face .talk-fullscreen:not(.icon-speak) {
  flex: none;
  min-height: 44px;
  padding: 5px 10px;
  font-size: 14px;
  line-height: 1.15;
  white-space: nowrap;
}
.talk-fullscreen small { font-size: 0.78em; font-weight: 500; }
.talk-top {
  min-height: 0;
  font-size: clamp(14px, 1.3vw, 18px);
  line-height: 1.3;
}
.talk-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}
.talk-top .card-kicker { margin-bottom: 4px; }
.talk-top h2 { margin-bottom: 6px; font-size: clamp(21px, 2.2vw, 28px); }
.talk-top .notice,
.talk-top .tomorrow-selected { margin: 6px 0 0; padding: 8px 10px; }
.panel-face button.talk-fullscreen:not(.icon-speak) {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  min-width: 0;
  min-height: 0;
  height: auto;
  margin: 0;
  padding: 4px 2px;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.78em;
  font-weight: 700;
  line-height: 1.15;
  box-shadow: none;
  opacity: 0.82;
}
.panel-face button.talk-fullscreen:not(.icon-speak):hover:not(:disabled) {
  opacity: 1;
  background: transparent;
}
.panel-face button.talk-fullscreen:not(.icon-speak):active:not(:disabled) {
  transform: none;
  background: transparent;
}
.talk-fullscreen-icon {
  display: grid;
  place-items: center;
  width: 1.35em;
  font-size: 1.15em;
  line-height: 1;
}
.talk-fullscreen-copy {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
}
.talk-fullscreen-copy small {
  font-size: 0.78em;
  font-weight: 500;
  opacity: 0.72;
}
.card-shell.is-focus .panel-face button.talk-fullscreen:not(.icon-speak):not(.primary) {
  background: transparent;
  color: #5a4a8a;
  border: 0;
  box-shadow: none;
}
.card-shell.fullscreen {
  position: fixed;
  inset: 0;
  z-index: 9000;
  width: auto;
  height: auto;
  min-height: 0 !important;
  margin: 0;
  padding:
    max(10px, env(safe-area-inset-top, 0px))
    max(10px, env(safe-area-inset-right, 0px))
    max(10px, env(safe-area-inset-bottom, 0px))
    max(10px, env(safe-area-inset-left, 0px));
  box-sizing: border-box;
  background: rgba(18, 20, 46, 0.55);
  grid-column: unset !important;
}
.beta:not(.editing) .card-shell.fullscreen.talk .panel-face.talk {
  height: 100%;
  max-height: none;
}
.talk-bottom {
  min-width: 0;
  border-top: 1px solid rgba(107, 84, 173, 0.2);
  padding-top: 6px;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 8px;
  align-items: center;
}
.talk-bottom form {
  min-width: 0;
  grid-template-columns: minmax(105px, 25%) minmax(0, 1fr);
  align-items: center;
  gap: 5px 8px;
}
.talk-bottom form label { font-size: clamp(13px, 1.3vw, 17px); line-height: 1.2; }
.talk-bottom form input { min-width: 0; min-height: 44px; padding: 6px 9px; font-size: 16px; }
.talk-bottom form .actions,
.talk-bottom form .help-notice,
.talk-bottom form .notice { grid-column: 1 / -1; }
.talk-bottom .actions { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.talk-bottom .actions.without-voice { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.panel-face .talk-bottom .actions button:not(.icon-speak),
.panel-face .talk-bottom .finish:not(.icon-speak) {
  min-width: 0;
  height: 56px;
  padding: 6px 8px;
  font-size: clamp(11px, 1.15vw, 16px);
  line-height: 1.15;
  white-space: nowrap;
}
.talk-bottom .finish { margin-top: 0; }
.talk-choice-prompt { margin: 0; font-weight: 700; }
.beta.editing .card-shell.talk .panel-face.talk { display: block; }
@media (min-width: 768px) and (max-width: 1366px) {
  .beta:not(.editing) .card-shell.talk { grid-column: span 12 !important; }
}
.card-shell.intro .panel-face {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  overflow: hidden;
  background: linear-gradient(115deg, #4144a6, #27306f 76%);
}
.intro-copy { position: relative; z-index: 1; }
.eyebrow,
.card-kicker {
  display: inline-block;
  margin-bottom: 10px;
  color: #ffe17a;
  font-size: 0.72em;
  font-weight: 800;
  letter-spacing: 0.04em;
}
.card-shell.intro h1 {
  margin: 0;
  font-size: clamp(1.4rem, 2.7vw, 2rem);
  line-height: 1.15;
}
.card-shell.intro h1 small {
  font-size: 0.52em;
  font-weight: 500;
  opacity: 0.82;
}
.intro-next {
  margin: 8px 0 0;
  font-size: 0.88em;
}
.intro-next small { opacity: 0.8; }
.intro-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
  flex-shrink: 0;
}
.time-chip {
  padding: 8px 12px;
  border: 1px solid rgba(255, 255, 255, 0.35);
  border-radius: 999px;
  font-size: 0.75em;
  white-space: nowrap;
}
.card-shell.is-focus .panel-face {
  background: #fff8e8;
  border-color: #ffe17a;
  color: #242b52;
  box-shadow: 0 15px 32px rgba(7, 8, 38, 0.24);
}
.card-shell.is-focus .card-kicker { color: #6b54ad; }
.card-shell.is-focus h2 small { color: #5b6077; }
.card-shell.is-focus .step-progress { color: #494d70; }
.card-shell.is-focus .notice {
  background: #f0e5ff;
  color: #292751;
}
.card-shell.is-focus .panel-face button:not(.icon-speak):not(.primary):not(.talk-fullscreen) {
  background: #fff;
  color: #242b52;
  border: 1px solid #d9cbea;
}
.card-shell.is-done .panel-face {
  background: #273364;
  border-color: rgba(255, 225, 122, 0.48);
}
.card-shell.words .panel-face {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  padding: calc(10px * var(--card-scale, 1)) calc(14px * var(--card-scale, 1));
  background: rgba(40, 44, 104, 0.78);
}
.card-shell.words h2 {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 6px 8px;
  margin: 0;
  font-size: 0.82em;
  line-height: 1.2;
}
.card-shell.words h2 small {
  font-size: 0.78em;
  font-weight: 500;
  opacity: 0.72;
}
.card-shell.plan .panel-face {
  display: flex;
  flex-direction: column;
  justify-content: center;
  background: #38337a;
}
.plan-count {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 2px 0;
}
.plan-count strong { color: #ffe17a; font-size: clamp(2.5rem, 5vw, 4rem); line-height: 1; }
.plan-count span { font-weight: 700; }
.plan-sub { margin: 8px 0 0; font-size: 0.82em; }
.plan-sub small { opacity: 0.75; }
.scene-intro { grid-column: 1 / -1; }
.scene-intro h2 { margin-bottom: 2px; }
.scene-intro p { margin: 0 0 10px; font-size: 0.82em; }
.scene-intro small { opacity: 0.75; }
.drag-handle,
.scale-handle,
.edge-handle {
  position: absolute;
  z-index: 3;
  border: 0;
  touch-action: none;
  color: #20263a;
  padding: 0;
}
.drag-handle {
  top: 8px;
  right: 8px;
  min-width: 36px;
  min-height: 36px;
  border-radius: 10px;
  background: #ffe17a;
  font-size: 16px;
  font-weight: 700;
  line-height: 1;
  cursor: grab;
}
.edge-handle {
  background: rgba(255, 225, 122, 0.85);
}
.edge-east {
  top: 28px;
  right: 0;
  bottom: 28px;
  width: 14px;
  min-height: 28px;
  border-radius: 8px 0 0 8px;
  cursor: ew-resize;
}
.edge-south {
  left: 28px;
  right: 28px;
  bottom: 0;
  height: 14px;
  min-height: 14px;
  border-radius: 8px 8px 0 0;
  cursor: ns-resize;
}
.scale-handle {
  right: 2px;
  bottom: 2px;
  width: 24px;
  height: 24px;
  min-height: 24px;
  border-radius: 6px;
  background:
    linear-gradient(135deg, transparent 45%, #ffe17a 45%, #ffe17a 55%, transparent 55%),
    linear-gradient(135deg, transparent 60%, #ffe17a 60%, #ffe17a 70%, transparent 70%),
    rgba(255, 255, 255, 0.4);
  cursor: nwse-resize;
}
.editing .panel-face { box-shadow: inset 0 0 0 1px rgba(255, 225, 122, 0.35); }

@media (max-width: 767px) {
  .talk-bottom { grid-template-columns: minmax(0, 1fr); }
  .talk-bottom form { grid-template-columns: minmax(0, 1fr); }
  .beta:not(.editing) .card-shell {
    grid-column: span 12 !important;
  }
}
h2 { margin: 0 0 8px; font-size: calc(var(--beta-h2) * var(--card-scale, 1)); }
h2 small { font-size: 0.65em; font-weight: 500; }
.tag {
  display: inline-block;
  background: #ffe17a;
  color: #20263a;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 0.85em;
  font-weight: 700;
}
.panel-face button:not(.icon-speak),
.scene-card {
  min-height: calc(var(--beta-btn) * var(--card-scale, 1));
  padding: calc(10px * var(--card-scale, 1)) calc(16px * var(--card-scale, 1));
  border: 0;
  border-radius: 13px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
}
button:focus-visible,
input:focus-visible { outline: 3px solid #ffe17a; outline-offset: 2px; }
button:disabled { opacity: 0.55; cursor: not-allowed; }
.panel-face button.primary:not(.icon-speak) {
  background: #ffe17a;
  color: #20263a;
}
.scene-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: calc(12px * var(--card-scale, 1));
  align-content: start;
}
.leave {
  margin-top: calc(12px * var(--card-scale, 1));
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}
.scene-note {
  margin: 0 0 calc(8px * var(--card-scale, 1));
  font-size: 0.85em;
  opacity: 0.85;
  white-space: pre-line;
}
.panel-face button.scene-card:not(.icon-speak) {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 4px;
  text-align: left;
  background: #ffe17a;
  color: #20263a;
  font-size: 0.9em;
  min-height: 140px;
  border: 1px solid #fff0ad;
}
.panel-face button.scene-card.current:not(.icon-speak) {
  background: rgba(255, 225, 122, 0.35);
  color: #fff;
}
.scene-card strong { font-size: 1.05em; line-height: 1.25; }
.scene-card small { font-weight: 500; opacity: 0.75; font-size: 0.9em; }
.scene-card .hint,
.scene-card .cta { font-size: 0.85em; font-weight: 600; opacity: 0.85; }
.scene-card .menu-label { display: block; margin-top: 8px; padding: 6px 9px; border-radius: 10px; background: rgba(255,255,255,.22); font-size: .8em; line-height: 1.35; }
.tomorrow-options { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.tomorrow-options button { padding: 10px 14px; border: 1px solid rgba(255,255,255,.5); border-radius: 12px; background: rgba(255,255,255,.25); color: inherit; font: inherit; cursor: pointer; }
.tomorrow-options .meal-emoji { display: block; font-size: 1.5em; line-height: 1.2; }
.tomorrow-options small { display: block; opacity: .78; }
.listen .primary {
  min-height: calc(42px * var(--card-scale, 1));
  padding: calc(8px * var(--card-scale, 1)) calc(12px * var(--card-scale, 1));
}
.products {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: calc(8px * var(--card-scale, 1));
  margin-top: calc(10px * var(--card-scale, 1));
}
.products button {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  min-height: calc(68px * var(--card-scale, 1));
  padding: calc(8px * var(--card-scale, 1));
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
}
.card-shell.is-focus .products button {
  background: #ffffff;
  color: #252b50;
  border: 2px solid #e4d9ff;
  box-shadow: 0 3px 0 #dbcbf5;
}
.card-shell.is-focus .products button:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #dbcbf5;
}
.step-progress { margin: 0 0 12px; font-size: 0.78em; font-weight: 700; opacity: 0.9; }
.products span { font-size: calc(1.4rem * var(--card-scale, 1)); }
.products small { font-weight: 500; }
.panel ul { list-style: none; padding: 0; margin: 0; }
.panel li,
.missions li {
  margin: calc(6px * var(--card-scale, 1)) 0;
  padding: calc(8px * var(--card-scale, 1));
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
}
.missions li:first-child { margin-top: 0; }
.missions li:last-child { margin-bottom: 0; }
.missions li { border-left: 3px solid rgba(255, 225, 122, 0.45); }
.dialogue {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.dialogue-waiting { display: flex; flex-direction: column; justify-content: center; align-items: stretch; gap: 12px; text-align: center; font-weight: 700; }
.dialogue-waiting > strong { font-size: 1.12em; }
.dialogue-waiting > strong small { font-size: 0.8em; font-weight: 500; }
.dialogue-waiting .tomorrow-options { justify-content: center; }
.dialogue-waiting .tomorrow-options button { min-width: min(190px, 100%); }
.tomorrow-selected { margin: 8px 0 0; }
.dialogue p {
  padding: calc(10px * var(--card-scale, 1));
  background: rgba(255, 255, 255, 0.1);
  border-radius: 10px;
}
.dialogue-word {
  cursor: help;
}
.dialogue-word:hover, .dialogue-word:focus-visible { background: #eee6ff; outline: none; }
.word-bubble {
  position: fixed;
  z-index: 10000;
  width: min(280px, calc(100vw - 24px));
  overflow: hidden;
  padding: 16px;
  border: 1px solid rgba(255, 255, 255, 0.62);
  border-radius: 22px;
  background: linear-gradient(140deg, rgba(255, 255, 255, 0.22), rgba(182, 159, 246, 0.13) 52%, rgba(255, 221, 149, 0.1)), rgba(39, 42, 91, 0.72);
  -webkit-backdrop-filter: blur(22px) saturate(170%);
  backdrop-filter: blur(22px) saturate(170%);
  box-shadow: 0 18px 42px rgba(22, 21, 63, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.6), inset 0 -1px 0 rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 16px;
  line-height: 1.45;
  animation: word-bubble-appear 180ms ease-out both;
}
.word-bubble::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(ellipse at 18% 0, rgba(255, 255, 255, 0.26), transparent 58%);
}
.word-bubble-head { position: relative; display: flex; align-items: center; gap: 12px; padding-right: 25px; }
.word-bubble-emoji {
  display: grid;
  place-items: center;
  flex: 0 0 48px;
  height: 48px;
  border: 1px solid rgba(255, 255, 255, 0.38);
  border-radius: 15px;
  background: rgba(255, 255, 255, 0.16);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.38);
  font-size: 27px;
}
.word-bubble-title { min-width: 0; }
.word-bubble strong { display: block; overflow-wrap: anywhere; font-size: 1.18em; line-height: 1.2; }
.word-bubble p { position: relative; margin: 12px 0 4px; color: rgba(255, 255, 255, 0.96); }
.word-bubble-pos { display: inline-block; margin-top: 5px; padding: 2px 7px; border-radius: 999px; background: rgba(255, 255, 255, 0.18); color: #fff2c8; font-size: 0.76em; }
.word-bubble-close {
  position: absolute;
  z-index: 1;
  right: 9px;
  top: 9px;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  padding: 0;
  border: 1px solid rgba(255, 255, 255, 0.24);
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.13);
  color: #fff;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
}
.word-bubble-audio { position: relative; display: flex; gap: 8px; margin-top: 9px; }
.word-bubble-play {
  position: relative;
  flex: 1;
  min-height: 38px;
  padding: 7px 11px;
  border: 1px solid rgba(255, 255, 255, 0.32);
  border-radius: 11px;
  background: rgba(255, 255, 255, 0.17);
  color: #fff;
  font: inherit;
  font-size: 0.88em;
  cursor: pointer;
}
.word-bubble-play:disabled { opacity: 0.48; cursor: not-allowed; }
.word-bubble-close:hover, .word-bubble-play:hover { background: rgba(255, 255, 255, 0.26); }
.word-bubble-close:focus-visible, .word-bubble-play:focus-visible { outline: 2px solid #ffe17a; outline-offset: 2px; }
@keyframes word-bubble-appear { from { opacity: 0; transform: translateY(5px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
@media (prefers-reduced-motion: reduce) { .word-bubble { animation: none; } }
@media (prefers-reduced-transparency: reduce) { .word-bubble { background: #353763; -webkit-backdrop-filter: none; backdrop-filter: none; } }
.card-shell.is-focus .dialogue p { background: #fff; border: 1px solid #e6ddf5; }
.card-shell.is-focus .dialogue p.hint-line { background: #f0e5ff; border-color: #c7b5ed; }
.card-shell.is-focus .panel-face button.help:not(.icon-speak):not(.primary) {
  background: #e8dcff;
  color: #392868;
  border-color: #bea7ea;
}
.card-shell.is-focus input { background: #fff; color: #242b52; border-color: #b8a8dc; }
.card-shell.is-focus input::placeholder { color: #62677d; }
.card-shell.is-focus .finish { color: #323758; border-color: #b8a8dc; }
.speak-pair {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-left: 4px;
  vertical-align: middle;
}
.panel-face button.icon-speak {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: calc(30px * var(--card-scale, 1));
  min-width: calc(30px * var(--card-scale, 1));
  min-height: calc(30px * var(--card-scale, 1));
  height: calc(30px * var(--card-scale, 1));
  padding: 0;
  margin: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: #ffe17a;
  font-size: calc(18px * var(--card-scale, 1));
  font-weight: 400;
  line-height: 1;
  box-shadow: none;
}
.panel-face button.icon-speak:disabled { opacity: 0.35; }
form { display: grid; gap: calc(10px * var(--card-scale, 1)); }
input {
  min-height: calc(var(--beta-btn) * var(--card-scale, 1));
  padding: calc(10px * var(--card-scale, 1));
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.22);
  color: #fff;
  font-size: max(16px, 1em);
}
input::placeholder { color: rgba(255, 255, 255, 0.45); }
.actions { display: flex; flex-wrap: wrap; gap: 10px; }
.finish {
  width: 100%;
  margin-top: calc(10px * var(--card-scale, 1));
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.2);
}
.word-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 4px 14px;
  align-items: center;
}
.word-list p {
  display: grid;
  grid-template-columns: minmax(4.5em, max-content) minmax(0, 1fr);
  align-items: center;
  gap: 6px 10px;
  margin: 0;
  font-size: 0.72em;
  line-height: 1.25;
}
.word-list strong {
  font-weight: 800;
  letter-spacing: 0.01em;
}
.word-flags {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 4px 8px;
  min-width: 0;
  opacity: 0.88;
  font-weight: 600;
  white-space: nowrap;
}
.word-flags span {
  overflow: hidden;
  text-overflow: ellipsis;
}
.error { color: #ffe0e0; }
.notice {
  white-space: pre-line;
  background: rgba(255, 225, 122, 0.2);
  padding: calc(12px * var(--card-scale, 1));
  border-radius: 10px;
}
.notice.banner {
  margin-top: var(--beta-gap);
  --card-scale: 1;
}
@media (max-width: 767px) {
  .card-shell.intro .panel-face { align-items: flex-start; flex-direction: column; }
  .intro-side { align-items: flex-start; flex-direction: row; flex-wrap: wrap; }
  .scene-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .products { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .panel-face button.scene-card:not(.icon-speak) { min-height: 138px; padding: 12px; }
  .scene-card .hint { display: none; }
}
@media (max-width: 360px) {
  .scene-grid { grid-template-columns: 1fr; }
}

</style>
