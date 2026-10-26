<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/shared/api/client'
import { chinesePinyinUnits, loadChinesePinyin, onChinesePinyinReady } from '@/shared/chinesePinyin'
import { canSpeak, speakPhrase, stopSpeaking, unlockSpeak, warmPhrases } from '@/shared/speak'

type WordMeaning = { word: string; meaning_zh: string | null; pos_zh: string | null }
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
  heard: boolean
  tale: FairyTaleBody | null
  date?: string
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
  fairy_tales: FairyTaleHistoryItem[]
  fairy_tales_heard: number
}

const { locale } = useI18n()
const router = useRouter()
const label = (zh: string, en: string) => locale.value === 'en' ? en : locale.value === 'zh-en' ? `${zh} / ${en}` : zh

const busy = ref(true)
const error = ref('')
const audioMessage = ref('')
const audioSupported = canSpeak()
const fairyTale = ref<FairyTalePayload | null>(null)
const fairyBusy = ref(false)
const fairyReading = ref<'zh' | 'en' | null>(null)
const lineReading = ref<{ index: number; lang: 'zh' | 'en' } | null>(null)
const speaking = ref(false)
const recordsOpen = ref(false)
const records = ref<LearningRecords | null>(null)
const recordsBusy = ref(false)
const historyTale = ref<FairyTalePayload | null>(null)
const historyTaleDate = ref<string | null>(null)
const pinyinTick = ref(0)
const SPEECH_RATES = [
  { value: 0.65, zh: '慢', en: 'Slow' },
  { value: 1, zh: '正常', en: 'Normal' },
  { value: 1.4, zh: '快', en: 'Fast' },
] as const
const speechRate = ref(1)
const wordBubble = ref<{ word: string; loading: boolean; meaning: WordMeaning | null; top: number; left: number } | null>(null)
const meaningCache = new Map<string, WordMeaning>()
let wordRequest = 0

const wordEmojis: Record<string, string> = {
  hello: '👋', hi: '👋', once: '📖', little: '🌱', girl: '👧', boy: '👦',
  grandma: '👵', forest: '🌲', house: '🏠', wolf: '🐺', red: '🔴',
  riding: '🐴', hood: '🧢', she: '👧', he: '👦', went: '🚶', see: '👀',
  there: '📍', was: '✨', a: '🔤', the: '🔤', to: '➡️',
}

const fairyTalesHeard = computed(() => records.value?.fairy_tales_heard ?? 0)
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

let speakToken = 0
let stopPinyinReady: (() => void) | undefined

function goHome() {
  void router.push({ name: 'kid-home' })
}

function sentenceTokens(text: string) {
  return text.split(/(\s+)/).filter(part => part.length > 0)
}

function clickableWord(token: string) {
  const clean = token.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '')
  if (clean.length < 2) return false
  return /^[A-Za-z']+$/.test(clean)
}

function wordEdge(token: string) {
  const match = /^([^A-Za-z]*)([A-Za-z']*)(.*)$/.exec(token)
  return {
    lead: match?.[1] ?? '',
    word: match?.[2] ?? '',
    tail: match?.[3] ?? '',
  }
}

function linePinyinUnits(text: string) {
  void pinyinTick.value
  return chinesePinyinUnits(text)
}

function fairyPairedLines(tale: FairyTaleBody) {
  void pinyinTick.value
  const count = Math.max(tale.zh_lines.length, tale.en_lines.length)
  return Array.from({ length: count }, (_, index) => ({
    zh: tale.zh_lines[index] ?? '',
    en: tale.en_lines[index] ?? '',
    units: chinesePinyinUnits(tale.zh_lines[index] ?? ''),
  }))
}

function haltSpeak() {
  stopSpeaking()
  speaking.value = false
  speakToken += 1
}

function haltFairy() {
  haltSpeak()
  fairyReading.value = null
  lineReading.value = null
}

function rateLabel(option: (typeof SPEECH_RATES)[number]) {
  return label(option.zh, option.en)
}

function setSpeechRate(value: number) {
  speechRate.value = value
}

function isLineReading(index: number, lang: 'zh' | 'en') {
  return lineReading.value?.index === index && lineReading.value.lang === lang
}

async function markFairyHeard() {
  if (!canMarkFairyHeard.value) return
  try {
    const payload = await api.postIdempotent<FairyTalePayload>('/kid/english/learning/fairy-tale/heard', {})
    fairyTale.value = payload
    if (historyTale.value) {
      historyTale.value = { ...payload, date: historyTale.value.date || historyTaleDate.value || undefined }
    }
    if (recordsOpen.value) {
      records.value = await api.get<LearningRecords>('/kid/english/learning/records')
    } else if (payload.heard) {
      records.value = {
        as_of: records.value?.as_of ?? '',
        fairy_tales: records.value?.fairy_tales ?? [],
        fairy_tales_heard: Math.max(fairyTalesHeard.value, 1),
      }
    }
  } catch {
    // Listening still works if the mark fails.
  }
}

async function playFairyLines(lang: 'zh' | 'en') {
  const tale = activeFairyTale.value
  if (!tale || !audioSupported) return
  if (fairyReading.value === lang) {
    haltFairy()
    return
  }
  unlockSpeak()
  if (speaking.value || fairyReading.value || lineReading.value) haltFairy()
  const lines = lang === 'zh' ? tale.zh_lines : tale.en_lines
  const localeTag = lang === 'zh' ? 'zh-CN' : 'en-US'
  const token = ++speakToken
  fairyReading.value = lang
  lineReading.value = null
  speaking.value = true
  audioMessage.value = ''
  try {
    for (const line of lines) {
      if (token !== speakToken) return
      await speakPhrase(line, localeTag, undefined, speechRate.value)
    }
    if (lang === 'en' && token === speakToken) {
      await markFairyHeard()
    }
  } catch {
    if (token === speakToken) {
      audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    }
  } finally {
    if (token === speakToken) {
      speaking.value = false
      fairyReading.value = null
    }
  }
}

async function playFairyLine(index: number, lang: 'zh' | 'en') {
  const tale = activeFairyTale.value
  if (!tale || !audioSupported) return
  if (isLineReading(index, lang)) {
    haltFairy()
    return
  }
  const text = (lang === 'zh' ? tale.zh_lines[index] : tale.en_lines[index])?.trim()
  if (!text) return
  unlockSpeak()
  if (speaking.value || fairyReading.value || lineReading.value) haltFairy()
  const localeTag = lang === 'zh' ? 'zh-CN' : 'en-US'
  const token = ++speakToken
  fairyReading.value = null
  lineReading.value = { index, lang }
  speaking.value = true
  audioMessage.value = ''
  try {
    if (token !== speakToken) return
    await speakPhrase(text, localeTag, undefined, speechRate.value)
  } catch {
    if (token === speakToken) {
      audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    }
  } finally {
    if (token === speakToken) {
      speaking.value = false
      lineReading.value = null
    }
  }
}

function emojiForWord(word: string, pos?: string | null): string {
  const lower = word.toLowerCase()
  if (wordEmojis[lower]) return wordEmojis[lower]
  if (pos?.includes('动词')) return '💬'
  if (pos?.includes('形容词')) return '✨'
  if (pos?.includes('时间')) return '⏰'
  if (pos?.includes('名词')) return '🧩'
  return '🔤'
}

async function speakWordAudio(word: string) {
  if (!word || !audioSupported) return
  unlockSpeak()
  if (fairyReading.value || lineReading.value) haltFairy()
  const speakId = ++speakToken
  speaking.value = true
  audioMessage.value = ''
  try {
    await speakPhrase(word, 'en-US', undefined, speechRate.value)
  } catch {
    if (speakId === speakToken) {
      audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    }
  } finally {
    if (speakId === speakToken) speaking.value = false
  }
}

async function speakZh(text: string) {
  const line = text.trim()
  if (!line || !audioSupported) return
  unlockSpeak()
  if (fairyReading.value || lineReading.value) haltFairy()
  const speakId = ++speakToken
  speaking.value = true
  audioMessage.value = ''
  try {
    await speakPhrase(line, 'zh-CN', undefined, speechRate.value)
  } catch {
    if (speakId === speakToken) {
      audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
    }
  } finally {
    if (speakId === speakToken) speaking.value = false
  }
}

async function inspectWord(token: string, context: string, event: Event) {
  const word = wordEdge(token).word
  if (!word) return
  const wordEl = event.currentTarget as HTMLElement
  const rect = wordEl.getBoundingClientRect()
  const left = Math.max(12, Math.min(rect.left, window.innerWidth - 292))
  const top = rect.bottom + 8 + 190 < window.innerHeight ? rect.bottom + 8 : Math.max(12, rect.top - 188)
  const key = `${word.toLowerCase()}|${context}`
  const request = ++wordRequest
  wordBubble.value = { word, loading: !meaningCache.has(key), meaning: meaningCache.get(key) ?? null, top, left }
  void speakWordAudio(word)
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

function closeWordBubble() {
  wordRequest += 1
  wordBubble.value = null
}

function onWordOutside(event: PointerEvent) {
  const target = event.target as Element | null
  if (wordBubble.value && !target?.closest('.fairy-word-bubble, .word-chip')) closeWordBubble()
}

function onWordEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') closeWordBubble()
}

async function loadFairyTale() {
  fairyBusy.value = true
  error.value = ''
  try {
    fairyTale.value = await api.get<FairyTalePayload>('/kid/english/learning/fairy-tale')
    const tale = fairyTale.value?.tale
    if (tale) {
      if (tale.zh_lines.length) warmPhrases(tale.zh_lines, 'zh-CN')
      if (tale.en_lines.length) warmPhrases(tale.en_lines, 'en-US')
    }
  } catch (e) {
    fairyTale.value = null
    error.value = e instanceof ApiError ? e.message : label('童话加载失败，请重试。', 'Could not load the fairy tale. Please retry.')
  } finally {
    fairyBusy.value = false
    busy.value = false
  }
}

async function loadRecordsSummary() {
  try {
    records.value = await api.get<LearningRecords>('/kid/english/learning/records')
  } catch {
    // History chip can stay empty until the panel opens.
  }
}

async function openRecords() {
  if (recordsOpen.value) {
    closeRecords()
    return
  }
  recordsOpen.value = true
  historyTale.value = null
  historyTaleDate.value = null
  recordsBusy.value = true
  try {
    records.value = await api.get<LearningRecords>('/kid/english/learning/records')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : label('记录加载失败，请重试。', 'Could not load records. Please retry.')
    recordsOpen.value = false
  } finally {
    recordsBusy.value = false
  }
}

function closeRecords() {
  haltFairy()
  closeWordBubble()
  recordsOpen.value = false
  historyTale.value = null
  historyTaleDate.value = null
}

async function openHistoryTale(date: string) {
  historyTaleDate.value = date
  fairyBusy.value = true
  haltFairy()
  try {
    historyTale.value = await api.get<FairyTalePayload>(`/kid/english/learning/fairy-tale?date=${encodeURIComponent(date)}`)
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
  closeWordBubble()
  historyTale.value = null
  historyTaleDate.value = null
}

watch(
  () => [activeFairyTale.value?.id, historyTaleDate.value, recordsOpen.value],
  () => { closeWordBubble() },
)

onMounted(() => {
  void loadChinesePinyin()
  stopPinyinReady = onChinesePinyinReady(() => {
    pinyinTick.value += 1
  })
  document.addEventListener('pointerdown', onWordOutside)
  document.addEventListener('keydown', onWordEscape)
  void loadFairyTale()
  void loadRecordsSummary()
})

onUnmounted(() => {
  stopPinyinReady?.()
  document.removeEventListener('pointerdown', onWordOutside)
  document.removeEventListener('keydown', onWordEscape)
  closeWordBubble()
  haltFairy()
})
</script>

<template>
  <section class="learning" :aria-label="label('今日童话', 'Today’s fairy tale')" :aria-busy="busy">
    <div class="intro">
      <span class="eyebrow">FAIRY TALE · {{ label('每天一个故事', 'One story a day') }}</span>
      <h2>{{ label('今天，来读一篇童话', 'Read today’s fairy tale') }}</h2>
      <p>{{ label('拼音在中文上，对应英文在下。可调语速后整篇听中文或英文；每句右侧小喇叭可单句朗读。点英文单词可听发音并看中文意思。听完整篇英文就算今天读过了。', 'Pinyin above Chinese, matching English below. Adjust the rate, then listen to the whole tale — or tap a line’s speakers. Tap an English word to hear it and see the meaning. Finishing the full English reading marks today’s tale heard.') }}</p>
    </div>

    <div class="stats">
      <button type="button" class="stat-chip" :class="{ open: recordsOpen }" @click="openRecords">
        <span class="stat-label">📚 {{ label('已读童话', 'Fairy tales read') }}</span>
        <b>{{ fairyTalesHeard }}</b>
      </button>
    </div>

    <div v-if="recordsOpen" class="records-panel" role="dialog" :aria-label="label('已读童话', 'Fairy tales read')">
      <div class="records-head">
        <button v-if="historyTale" type="button" class="secondary" @click="backToFairyList">← {{ label('返回列表', 'Back to list') }}</button>
        <h3>
          <template v-if="historyTale">{{ label('再读一遍', 'Read again') }}</template>
          <template v-else>{{ label('已读童话', 'Fairy tales read') }}</template>
        </h3>
        <button type="button" class="secondary" @click="closeRecords">{{ label('关闭', 'Close') }}</button>
      </div>
      <p v-if="recordsBusy" class="hint">{{ label('正在加载记录…', 'Loading records…') }}</p>
      <template v-else-if="historyTale?.tale">
        <div class="fairy-box records-fairy" aria-label="fairy tale reread">
          <div class="fairy-head">
            <div class="fairy-title" role="heading" aria-level="3">
              <p class="fairy-zh-text" lang="zh">
                <span
                  v-for="(unit, uIdx) in linePinyinUnits(historyTale.tale.title_zh)"
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
                    <button type="button" class="word-chip" :aria-label="label(`点读并查看 ${wordEdge(token).word} 的词义`, `Tap to hear and look up ${wordEdge(token).word}`)" @click="inspectWord(token, historyTale.tale.title_en, $event)">{{ wordEdge(token).word }}</button>
                    <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                  </template>
                  <span v-else>{{ token }}</span>
                </template>
              </div>
            </div>
            <p v-if="historyTale.tale.source" class="fairy-source">{{ historyTale.tale.source }}<template v-if="historyTale.tale.source_en"> · {{ historyTale.tale.source_en }}</template></p>
            <div class="fairy-actions">
              <div class="rate-group" role="group" :aria-label="label('语速', 'Speech rate')">
                <span class="rate-label">{{ label('语速', 'Rate') }}</span>
                <button
                  v-for="option in SPEECH_RATES"
                  :key="option.value"
                  type="button"
                  class="rate-chip"
                  :class="{ active: speechRate === option.value }"
                  :aria-pressed="speechRate === option.value"
                  @click="setSpeechRate(option.value)"
                >{{ rateLabel(option) }}</button>
              </div>
              <div class="listen-row">
                <button type="button" class="secondary listen-all" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('zh')">
                  {{ fairyReading === 'zh' ? label('⏹ 停止', '⏹ Stop') : label('听中文', 'Listen in Chinese') }}
                </button>
                <button type="button" class="secondary listen-all" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('en')">
                  {{ fairyReading === 'en' ? label('⏹ 停止', '⏹ Stop') : label('听英文', 'Listen in English') }}
                </button>
              </div>
            </div>
          </div>
          <div class="fairy-body">
            <div v-for="(line, idx) in fairyPairedLines(historyTale.tale)" :key="`hz-${idx}`" class="fairy-line">
              <div class="fairy-line-text">
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
                      <button type="button" class="word-chip" :aria-label="label(`点读并查看 ${wordEdge(token).word} 的词义`, `Tap to hear and look up ${wordEdge(token).word}`)" @click="inspectWord(token, line.en, $event)">{{ wordEdge(token).word }}</button>
                      <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                    </template>
                    <span v-else>{{ token }}</span>
                  </template>
                </div>
              </div>
              <div class="fairy-line-speak">
                <button
                  type="button"
                  class="line-horn"
                  :class="{ active: isLineReading(idx, 'zh') }"
                  :disabled="!audioSupported || fairyBusy || !line.zh"
                  :aria-label="label(`听本句中文`, `Listen to this line in Chinese`)"
                  :title="label('听本句中文', 'Chinese')"
                  @click="playFairyLine(idx, 'zh')"
                >{{ isLineReading(idx, 'zh') ? '⏹' : '🔊' }}<span class="horn-lang">中</span></button>
                <button
                  type="button"
                  class="line-horn"
                  :class="{ active: isLineReading(idx, 'en') }"
                  :disabled="!audioSupported || fairyBusy || !line.en"
                  :aria-label="label(`听本句英文`, `Listen to this line in English`)"
                  :title="label('听本句英文', 'English')"
                  @click="playFairyLine(idx, 'en')"
                >{{ isLineReading(idx, 'en') ? '⏹' : '🔊' }}<span class="horn-lang">EN</span></button>
              </div>
            </div>
          </div>
          <p class="hint">{{ label('点下方小喇叭听这一句；点英文单词可听发音并看中文意思。', 'Tap the speakers below to hear this line. Tap an English word to hear it and see the meaning.') }}</p>
        </div>
      </template>
      <template v-else>
        <ul v-if="records?.fairy_tales?.length" class="record-list">
          <li v-for="item in records.fairy_tales" :key="item.date" class="record-item">
            <button type="button" class="record-title-btn" @click="openHistoryTale(item.date)">
              <span class="record-main">{{ item.title_zh }}</span>
              <span v-if="item.title_en" class="record-sub" lang="en">{{ item.title_en }}</span>
              <span class="record-meta">{{ item.date }} · {{ item.source }}<template v-if="item.heard"> · {{ label('已听', 'Heard') }}</template></span>
            </button>
          </li>
        </ul>
        <p v-else class="hint">{{ label('还没有读过的童话。今天就可以听第一篇。', 'No fairy tales yet. You can listen to today’s story now.') }}</p>
      </template>
    </div>

    <p v-if="error" role="alert" class="error">{{ error }} <button :disabled="busy || fairyBusy" @click="loadFairyTale">{{ label('重新加载', 'Reload') }}</button></p>
    <p v-if="busy" class="hint">{{ label('正在准备今日童话…', 'Preparing today’s fairy tale…') }}</p>

    <div v-else-if="fairyTale?.available && fairyTale.tale" class="fairy-box" aria-label="fairy tale">
      <p class="speak-label">📖 {{ label('童话时间', 'Fairy-tale time') }}</p>
      <div class="fairy-head">
        <div class="fairy-title" role="heading" aria-level="3">
          <p class="fairy-zh-text" lang="zh">
            <span
              v-for="(unit, uIdx) in linePinyinUnits(fairyTale.tale.title_zh)"
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
                <button type="button" class="word-chip" :aria-label="label(`点读并查看 ${wordEdge(token).word} 的词义`, `Tap to hear and look up ${wordEdge(token).word}`)" @click="inspectWord(token, fairyTale.tale.title_en, $event)">{{ wordEdge(token).word }}</button>
                <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
              </template>
              <span v-else>{{ token }}</span>
            </template>
          </div>
        </div>
        <p v-if="fairyTale.tale.source" class="fairy-source">{{ fairyTale.tale.source }}<template v-if="fairyTale.tale.source_en"> · {{ fairyTale.tale.source_en }}</template></p>
        <div class="fairy-actions">
          <div class="rate-group" role="group" :aria-label="label('语速', 'Speech rate')">
            <span class="rate-label">{{ label('语速', 'Rate') }}</span>
            <button
              v-for="option in SPEECH_RATES"
              :key="option.value"
              type="button"
              class="rate-chip"
              :class="{ active: speechRate === option.value }"
              :aria-pressed="speechRate === option.value"
              @click="setSpeechRate(option.value)"
            >{{ rateLabel(option) }}</button>
          </div>
          <div class="listen-row">
            <button type="button" class="secondary listen-all" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('zh')">
              {{ fairyReading === 'zh' ? label('⏹ 停止', '⏹ Stop') : label('听中文', 'Listen in Chinese') }}
            </button>
            <button type="button" class="secondary listen-all" :disabled="!audioSupported || fairyBusy" @click="playFairyLines('en')">
              {{ fairyReading === 'en' ? label('⏹ 停止', '⏹ Stop') : label('听英文', 'Listen in English') }}
            </button>
          </div>
        </div>
      </div>
      <div class="fairy-body">
        <div v-for="(line, idx) in fairyPairedLines(fairyTale.tale)" :key="`zh-${idx}`" class="fairy-line">
          <div class="fairy-line-text">
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
                  <button type="button" class="word-chip" :aria-label="label(`点读并查看 ${wordEdge(token).word} 的词义`, `Tap to hear and look up ${wordEdge(token).word}`)" @click="inspectWord(token, line.en, $event)">{{ wordEdge(token).word }}</button>
                  <span v-if="wordEdge(token).tail">{{ wordEdge(token).tail }}</span>
                </template>
                <span v-else>{{ token }}</span>
              </template>
            </div>
          </div>
          <div class="fairy-line-speak">
            <button
              type="button"
              class="line-horn"
              :class="{ active: isLineReading(idx, 'zh') }"
              :disabled="!audioSupported || fairyBusy || !line.zh"
              :aria-label="label(`听本句中文`, `Listen to this line in Chinese`)"
              :title="label('听本句中文', 'Chinese')"
              @click="playFairyLine(idx, 'zh')"
            >{{ isLineReading(idx, 'zh') ? '⏹' : '🔊' }}<span class="horn-lang">中</span></button>
            <button
              type="button"
              class="line-horn"
              :class="{ active: isLineReading(idx, 'en') }"
              :disabled="!audioSupported || fairyBusy || !line.en"
              :aria-label="label(`听本句英文`, `Listen to this line in English`)"
              :title="label('听本句英文', 'English')"
              @click="playFairyLine(idx, 'en')"
            >{{ isLineReading(idx, 'en') ? '⏹' : '🔊' }}<span class="horn-lang">EN</span></button>
          </div>
        </div>
        <p class="hint">{{ label('点下方小喇叭听这一句；点英文单词可听发音并看中文意思。', 'Tap the speakers below to hear this line. Tap an English word to hear it and see the meaning.') }}</p>
      </div>
      <p v-if="fairyTale.heard" class="hint" role="status">{{ label('今天的童话已经听过啦，明天还有新故事。', 'You’ve heard today’s tale — a new one tomorrow.') }}</p>
    </div>
    <p v-else-if="fairyBusy" class="hint">{{ label('正在准备今日童话…', 'Preparing today’s fairy tale…') }}</p>
    <p v-else class="hint">{{ label('今天的童话还没准备好，请稍后再试。', 'Today’s fairy tale is not ready yet. Please try again shortly.') }}</p>

    <p v-if="!audioSupported" class="hint">{{ label('当前浏览器不支持朗读，可以和家长一起读。', 'Read together with a grown-up; audio is not supported here.') }}</p>
    <p v-if="audioMessage" role="status" class="hint">{{ audioMessage }}</p>

    <div class="done-actions">
      <button type="button" class="secondary" @click="goHome">{{ label('回星球', 'Back to planet') }}</button>
    </div>

    <Teleport to="body">
      <div
        v-if="wordBubble"
        class="fairy-word-bubble"
        role="dialog"
        :aria-label="label(`${wordBubble.word} 的词义`, `Meaning of ${wordBubble.word}`)"
        :style="{ top: `${wordBubble.top}px`, left: `${wordBubble.left}px` }"
      >
        <button type="button" class="fairy-word-bubble-close" :aria-label="label('关闭词义', 'Close meaning')" @click="closeWordBubble">×</button>
        <div class="fairy-word-bubble-head">
          <span class="fairy-word-bubble-emoji" aria-hidden="true">{{ emojiForWord(wordBubble.word, wordBubble.meaning?.pos_zh) }}</span>
          <div class="fairy-word-bubble-title">
            <strong>{{ wordBubble.word }}</strong>
            <span v-if="wordBubble.meaning?.pos_zh" class="fairy-word-bubble-pos">{{ wordBubble.meaning.pos_zh }}</span>
          </div>
        </div>
        <p v-if="wordBubble.loading" role="status">{{ label('正在查词…', 'Looking up…') }}</p>
        <template v-else-if="wordBubble.meaning?.meaning_zh">
          <p>{{ wordBubble.meaning.meaning_zh }}</p>
        </template>
        <p v-else class="fairy-word-bubble-miss">{{ label('暂时没有释义，先听发音吧。', 'No meaning yet — you can still hear it.') }}</p>
        <div class="fairy-word-bubble-audio">
          <button type="button" class="fairy-word-bubble-play" :aria-label="label(`播放英文：${wordBubble.word}`, `Play English: ${wordBubble.word}`)" @click="speakWordAudio(wordBubble.word)">🔊 {{ label('英文', 'EN') }}</button>
          <button type="button" class="fairy-word-bubble-play" :aria-label="wordBubble.meaning?.meaning_zh ? label(`播放中文：${wordBubble.meaning.meaning_zh}`, `Play Chinese: ${wordBubble.meaning.meaning_zh}`) : label('暂无中文释义', 'No Chinese meaning')" :disabled="!wordBubble.meaning?.meaning_zh" @click="speakZh(wordBubble.meaning?.meaning_zh ?? '')">🔈 {{ label('中文', 'ZH') }}</button>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.learning { margin: 24px 0 32px; padding: 24px; border: 1px solid #77b7d944; border-radius: 24px; background: linear-gradient(135deg, #23577933, #34523c22); }
.eyebrow { color: #8cdbc8; font-size: 12px; letter-spacing: 1px; }
h2 { margin: 10px 0; font-size: clamp(21px, 3vw, 28px); }
p { line-height: 1.7; }
.intro p, .hint { opacity: .8; font-size: 14px; }
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
.record-main { margin: 0; font-weight: 650; }
.record-sub { margin: 2px 0 0; opacity: 0.8; font-size: 13px; }
.record-meta { display: block; margin-top: 4px; font-size: 12px; opacity: 0.7; }
.record-title-btn { display: block; width: 100%; text-align: left; background: transparent; border: 0; color: inherit; font: inherit; cursor: pointer; padding: 0; }
.record-title-btn:hover .record-main { text-decoration: underline; }
.fairy-box { margin: 18px 0; padding: 16px; border-radius: 16px; background: #1a2f2833; border: 1px solid #77b7d944; text-align: center; }
.records-fairy { margin: 0; }
.fairy-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  margin: 8px 0 4px;
  width: 100%;
}
.fairy-title {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  margin: 0;
  width: 100%;
  max-width: 40rem;
  padding: 0;
  text-align: center;
}
.fairy-title .fairy-zh-text {
  justify-content: center;
  font-size: 1.25rem;
}
.fairy-title .fairy-en-text {
  justify-content: center;
  font-size: 1rem;
  opacity: 0.92;
}
.fairy-actions {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 6px;
  width: min(100%, 15.5rem);
}
.rate-group {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  width: 100%;
  box-sizing: border-box;
  padding: 2px 4px;
  border-radius: 999px;
  background: #ffffff0d;
  border: 1px solid #ffffff22;
}
.rate-label {
  font-size: 11px;
  opacity: 0.75;
  padding-inline: 4px 2px;
  white-space: nowrap;
}
.rate-chip {
  min-height: 28px;
  padding: 2px 8px;
  border-radius: 999px;
  border: 0;
  background: transparent;
  color: inherit;
  font-size: 12px;
  line-height: 1.2;
}
.rate-chip.active {
  background: #8cdbc833;
  color: #c9f3e8;
}
.listen-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  width: 100%;
}
.listen-all {
  min-height: 36px;
  width: 100%;
  padding: 6px 10px;
  margin: 0;
}
.fairy-source { margin: 10px 0 4px; font-size: 13px; opacity: 0.75; }
.fairy-body {
  margin: 16px auto 0;
  width: 100%;
  max-width: 40rem;
}
.fairy-line {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin: 0 0 18px;
  text-align: center;
}
.fairy-line-text {
  width: 100%;
}
.fairy-line-speak {
  display: flex;
  flex-direction: row;
  justify-content: center;
  gap: 8px;
  align-items: center;
}
.line-horn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-height: 32px;
  min-width: 48px;
  padding: 4px 10px;
  border-radius: 999px;
  border: 1px solid #ffffff35;
  background: #ffffff0a;
  color: inherit;
  font-size: 13px;
  line-height: 1;
}
.line-horn.active {
  background: #8cdbc833;
  border-color: #8cdbc8;
  color: #c9f3e8;
}
.horn-lang {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.02em;
  opacity: 0.9;
}
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
.speak-label { margin: 0; font-size: 14px; color: #9fe4d3; }
.done-actions { display: grid; gap: 10px; max-width: 320px; margin: 18px auto 0; }
button { font: inherit; cursor: pointer; border-radius: 12px; min-height: 46px; padding: 10px 18px; color: inherit; border: 1px solid #ffffff35; background: #ffffff0a; }
button:disabled { opacity: .5; cursor: not-allowed; }
button:focus-visible { outline: 3px solid #8cdbc8; outline-offset: 3px; }
.secondary { font-size: 14px; }
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
  cursor: help;
}
.word-chip:hover:not(:disabled), .word-chip:focus-visible {
  color: #9fe4d3;
  border-bottom-color: #9fe4d3;
  background: #8cdbc814;
  outline: none;
}
.error { color: #ffb4b4; }
@media(max-width: 520px) { .learning { padding: 16px; }.stats { gap: 6px; }.stat-chip { padding: 8px 10px; font-size: 12px; } }
</style>

<style>
/* Teleported to body — namespaced so it does not clash with English Beta bubbles. */
.fairy-word-bubble {
  position: fixed;
  z-index: 10000;
  width: min(280px, calc(100vw - 24px));
  overflow: hidden;
  padding: 16px;
  border: 1px solid rgba(255, 255, 255, 0.62);
  border-radius: 22px;
  background: linear-gradient(140deg, rgba(255, 255, 255, 0.22), rgba(140, 219, 200, 0.16) 52%, rgba(255, 221, 149, 0.1)), rgba(26, 47, 40, 0.82);
  -webkit-backdrop-filter: blur(22px) saturate(170%);
  backdrop-filter: blur(22px) saturate(170%);
  box-shadow: 0 18px 42px rgba(18, 36, 32, 0.32), inset 0 1px 0 rgba(255, 255, 255, 0.55), inset 0 -1px 0 rgba(255, 255, 255, 0.1);
  color: #fff;
  font-size: 16px;
  line-height: 1.45;
  animation: fairy-word-bubble-appear 180ms ease-out both;
}
.fairy-word-bubble::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(ellipse at 18% 0, rgba(255, 255, 255, 0.26), transparent 58%);
}
.fairy-word-bubble-head { position: relative; display: flex; align-items: center; gap: 12px; padding-right: 25px; }
.fairy-word-bubble-emoji {
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
.fairy-word-bubble-title { min-width: 0; }
.fairy-word-bubble strong { display: block; overflow-wrap: anywhere; font-size: 1.18em; line-height: 1.2; }
.fairy-word-bubble p { position: relative; margin: 12px 0 4px; color: rgba(255, 255, 255, 0.96); }
.fairy-word-bubble-miss { opacity: 0.82; font-size: 0.92em; }
.fairy-word-bubble-pos {
  display: inline-block;
  margin-top: 5px;
  padding: 2px 7px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.18);
  color: #c9f3e8;
  font-size: 0.76em;
}
.fairy-word-bubble-close {
  position: absolute;
  z-index: 1;
  right: 9px;
  top: 9px;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  min-height: 30px;
  padding: 0;
  border: 1px solid rgba(255, 255, 255, 0.24);
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.13);
  color: #fff;
  font: inherit;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
}
.fairy-word-bubble-audio { position: relative; display: flex; gap: 8px; margin-top: 9px; }
.fairy-word-bubble-play {
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
.fairy-word-bubble-play:disabled { opacity: 0.48; cursor: not-allowed; }
.fairy-word-bubble-close:hover, .fairy-word-bubble-play:hover { background: rgba(255, 255, 255, 0.26); }
.fairy-word-bubble-close:focus-visible, .fairy-word-bubble-play:focus-visible { outline: 2px solid #8cdbc8; outline-offset: 2px; }
@keyframes fairy-word-bubble-appear { from { opacity: 0; transform: translateY(5px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
@media (prefers-reduced-motion: reduce) { .fairy-word-bubble { animation: none; } }
@media (prefers-reduced-transparency: reduce) { .fairy-word-bubble { background: #1a2f28; -webkit-backdrop-filter: none; backdrop-filter: none; } }
</style>
