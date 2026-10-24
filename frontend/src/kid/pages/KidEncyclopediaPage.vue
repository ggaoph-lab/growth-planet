<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type {
  EncyclopediaDomainDetail,
  EncyclopediaSubject,
  EncyclopediaTopicDetail,
  EncyclopediaTopicListItem,
  EncyclopediaVideo,
} from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { speakPhrase, stopSpeaking, unlockSpeak } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

const { t, locale } = useI18n()
const router = useRouter()
const session = useSessionStore()

const subject = ref<EncyclopediaSubject | null>(peekWarmGet<EncyclopediaSubject>('/kid/encyclopedia') ?? null)
const domain = ref<EncyclopediaDomainDetail | null>(null)
const article = ref<EncyclopediaTopicDetail | null>(null)
const loading = ref(subject.value == null)
const error = ref('')
const speaking = ref(false)
const speakingLang = ref<'zh' | 'en' | null>(null)
const savingRead = ref(false)
const savingWord = ref('')
const wordFlash = ref('')

const view = computed(() => {
  if (article.value) return 'article' as const
  if (domain.value) return 'domain' as const
  return 'home' as const
})

const enTokens = computed(() => {
  const text = article.value?.body_en ?? ''
  if (!text) return [] as Array<{ type: 'word' | 'gap'; value: string }>
  const parts = text.split(/([A-Za-z]+(?:'[A-Za-z]+)?)/)
  return parts.filter(Boolean).map((part) => (
    /^[A-Za-z]/.test(part) ? { type: 'word' as const, value: part } : { type: 'gap' as const, value: part }
  ))
})

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

/** Prefer the locale side of BiMsg stacked or "中文 / English" strings for speech and compact UI. */
function biParts(raw: string): string[] {
  if (raw.includes('\n')) return raw.split(/\n/).map((s) => s.trim()).filter(Boolean)
  return raw.split(/\s*\/\s*/).map((s) => s.trim()).filter(Boolean)
}

function displayText(raw: string): string {
  const parts = biParts(raw)
  if (parts.length < 2) return raw
  if (locale.value === 'en') return parts[parts.length - 1] ?? raw
  if (locale.value === 'zh-en') return raw
  return parts[0] ?? raw
}

function topicTitle(row: Pick<EncyclopediaTopicListItem, 'name' | 'name_zh' | 'name_en'>): string {
  if (row.name_zh) {
    if (locale.value === 'en') return row.name_en || row.name_zh
    return row.name_zh
  }
  return displayText(row.name)
}

function videoSrc(video: EncyclopediaVideo): string {
  if (video.provider === 'youtube') {
    return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(video.id)}`
  }
  return `https://player.bilibili.com/player.html?bvid=${encodeURIComponent(video.id)}&autoplay=0`
}

async function loadHome(force = false): Promise<void> {
  if (force || subject.value == null) loading.value = true
  error.value = ''
  try {
    subject.value = await warmGet<EncyclopediaSubject>('/kid/encyclopedia', undefined, { force })
    domain.value = null
    article.value = null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function openDomain(key: string): Promise<void> {
  tap()
  loading.value = true
  error.value = ''
  try {
    domain.value = await api.get<EncyclopediaDomainDetail>(`/kid/encyclopedia/domains/${encodeURIComponent(key)}`)
    article.value = null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function openTopic(id: string, row?: EncyclopediaTopicListItem): Promise<void> {
  tap()
  if (row?.locked) {
    const hintName = row.unlock_hint?.name_zh || row.unlock_hint?.name_en
    error.value = hintName
      ? t('kid.encyclopedia.unlockHint', { name: hintName })
      : t('kid.encyclopedia.locked')
    return
  }
  void unlockSpeak()
  loading.value = true
  error.value = ''
  wordFlash.value = ''
  try {
    const detail = await api.get<EncyclopediaTopicDetail>(`/kid/encyclopedia/topics/${encodeURIComponent(id)}`)
    article.value = detail
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function markRead(): Promise<void> {
  if (!article.value || article.value.read || savingRead.value) return
  tap()
  savingRead.value = true
  error.value = ''
  try {
    const id = article.value.id
    const detail = await api.postIdempotent<EncyclopediaTopicDetail>(
      `/kid/encyclopedia/topics/${encodeURIComponent(id)}/read`,
      {},
    )
    if (article.value?.id === id) article.value = detail
    if (domain.value) {
      // Refresh so prerequisite unlocks update locked flags for siblings.
      domain.value = await api.get<EncyclopediaDomainDetail>(
        `/kid/encyclopedia/domains/${encodeURIComponent(domain.value.key)}`,
      )
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    savingRead.value = false
  }
}

async function collectWord(raw: string): Promise<void> {
  if (!article.value || savingWord.value) return
  const word = raw.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '').toLowerCase()
  if (!word) return
  tap()
  savingWord.value = word
  wordFlash.value = ''
  error.value = ''
  try {
    const result = await api.post<{
      word: string
      added: boolean
      already: boolean
      meaning: string
    }>(`/kid/encyclopedia/topics/${encodeURIComponent(article.value.id)}/words`, { word })
    wordFlash.value = result.already || !result.added
      ? t('kid.encyclopedia.wordAlready')
      : t('kid.encyclopedia.wordAdded')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    savingWord.value = ''
  }
}

function back(): void {
  tap()
  wordFlash.value = ''
  if (view.value === 'article') {
    article.value = null
    return
  }
  if (view.value === 'domain') {
    domain.value = null
    return
  }
  router.push({ name: 'kid-home' })
}

async function readAloud(lang: 'zh' | 'en'): Promise<void> {
  if (!article.value) return
  tap()
  if (speaking.value && speakingLang.value === lang) {
    stopSpeaking()
    speaking.value = false
    speakingLang.value = null
    return
  }
  stopSpeaking()
  speaking.value = true
  speakingLang.value = lang
  try {
    await unlockSpeak()
    if (lang === 'en') {
      const title = (article.value.name_en || '').trim()
      const body = (article.value.body_en || '').trim()
      const text = [title, body].filter(Boolean).join(title.endsWith('?') || title.endsWith('.') ? ' ' : '. ')
      if (text) await speakPhrase(text, 'en-US')
    } else {
      const title = article.value.name_zh || displayText(article.value.name)
      const body = article.value.body_zh || displayText(article.value.body)
      await speakPhrase(`${title}。${body}`, 'zh-CN')
    }
  } finally {
    speaking.value = false
    speakingLang.value = null
  }
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && view.value !== 'home') back()
}

onMounted(() => {
  void loadHome()
  window.addEventListener('keydown', onKey)
})
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="back">
        {{ view === 'home' ? t('kid.backPlanet') : t('kid.encyclopedia.back') }}
      </button>
      <h1>
        <template v-if="view === 'article' && article">{{ article.name_zh || displayText(article.name) }}</template>
        <template v-else-if="view === 'domain' && domain">{{ displayText(domain.title) }}</template>
        <template v-else>{{ t('kid.encyclopedia.title') }}</template>
      </h1>
      <span class="spacer" />
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <section v-else-if="view === 'home' && subject" class="home">
      <p class="lead">{{ t('kid.encyclopedia.lead', { count: subject.topic_count }) }}</p>
      <div class="grid">
        <button
          v-for="d in subject.domains"
          :key="d.key"
          type="button"
          class="domain"
          @click="openDomain(d.key)"
        >
          <span class="emoji" aria-hidden="true">{{ d.emoji }}</span>
          <span class="name">{{ displayText(d.title) }}</span>
          <span class="count">{{ t('kid.encyclopedia.topicCount', { count: d.topic_count }) }}</span>
        </button>
      </div>
      <p class="attr">{{ displayText(subject.attribution) }}</p>
    </section>

    <section v-else-if="view === 'domain' && domain" class="list">
      <button
        v-for="row in domain.topics"
        :key="row.id"
        type="button"
        class="topic"
        :class="{ read: row.read, locked: row.locked }"
        @click="openTopic(row.id, row)"
      >
        <span class="emoji" aria-hidden="true">{{ row.emoji }}</span>
        <span class="meta">
          <span class="name">{{ topicTitle(row) }}</span>
          <span class="name-en">{{ row.name_en }}</span>
          <span class="age">{{ t('kid.encyclopedia.age', { start: row.age_start, end: row.age_end }) }}</span>
        </span>
        <span v-if="row.read" class="badge">{{ t('kid.encyclopedia.read') }}</span>
        <span v-else-if="row.locked" class="badge locked">{{ t('kid.encyclopedia.locked') }}</span>
      </button>
    </section>

    <article v-else-if="view === 'article' && article" class="article">
      <div class="hero" aria-hidden="true">{{ article.emoji }}</div>
      <p class="domain-tag">{{ displayText(article.domain) }}</p>
      <h2>{{ article.name_zh }}</h2>
      <p class="name-en title-en">{{ article.name_en }}</p>
      <p class="age">{{ t('kid.encyclopedia.age', { start: article.age_start, end: article.age_end }) }}</p>

      <div v-if="article.video" class="video">
        <p class="video-label">{{ t('kid.encyclopedia.video') }}</p>
        <div class="video-frame">
          <iframe
            :src="videoSrc(article.video)"
            :title="t('kid.encyclopedia.video')"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
          />
        </div>
      </div>

      <p class="body body-zh">{{ article.body_zh }}</p>
      <p class="body body-en" aria-label="English">
        <template v-for="(tok, idx) in enTokens" :key="`${idx}-${tok.value}`">
          <button
            v-if="tok.type === 'word'"
            type="button"
            class="en-word"
            :disabled="savingWord === tok.value.toLowerCase()"
            @click="collectWord(tok.value)"
          >{{ tok.value }}</button>
          <span v-else>{{ tok.value }}</span>
        </template>
      </p>
      <p class="words-hint">{{ t('kid.encyclopedia.words') }}</p>
      <p v-if="wordFlash" class="word-flash">{{ wordFlash }}</p>

      <div class="speak-row">
        <button
          type="button"
          class="speak"
          :disabled="speaking && speakingLang !== 'zh'"
          @click="readAloud('zh')"
        >
          {{ speaking && speakingLang === 'zh' ? t('kid.encyclopedia.speaking') : t('kid.encyclopedia.speakZh') }}
        </button>
        <button
          type="button"
          class="speak"
          :disabled="speaking && speakingLang !== 'en'"
          @click="readAloud('en')"
        >
          {{ speaking && speakingLang === 'en' ? t('kid.encyclopedia.speaking') : t('kid.encyclopedia.speakEn') }}
        </button>
      </div>
      <button v-if="!article.read" type="button" class="speak finish-read" :disabled="savingRead" @click="markRead">
        {{ t('kid.encyclopedia.finishReading') }}
      </button>
      <p v-else class="read-confirmed">{{ t('kid.encyclopedia.read') }}</p>

      <section v-if="article.prerequisites.length" class="related">
        <h3>{{ t('kid.encyclopedia.prerequisites') }}</h3>
        <div class="chips">
          <button
            v-for="row in article.prerequisites"
            :key="row.id"
            type="button"
            class="chip"
            :class="{ locked: row.locked }"
            @click="openTopic(row.id, row)"
          >
            <span aria-hidden="true">{{ row.emoji }}</span>
            {{ topicTitle(row) }}
          </button>
        </div>
      </section>

      <section v-if="article.next_topics.length" class="related">
        <h3>{{ t('kid.encyclopedia.nextTopics') }}</h3>
        <div class="chips">
          <button
            v-for="row in article.next_topics"
            :key="row.id"
            type="button"
            class="chip"
            :class="{ locked: row.locked }"
            @click="openTopic(row.id, row)"
          >
            <span aria-hidden="true">{{ row.emoji }}</span>
            {{ topicTitle(row) }}
          </button>
        </div>
      </section>

      <p class="attr">{{ displayText(article.attribution) }}</p>
    </article>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 40px;
  max-width: 720px;
  margin: 0 auto;
}
.head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.head h1 {
  margin: 0;
  text-align: center;
  font-size: clamp(18px, 4vw, 24px);
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.16);
  color: inherit;
  border-radius: 999px;
  padding: 8px 12px;
  cursor: pointer;
}
.spacer { width: 64px; }
.lead, .hint, .err { text-align: center; }
.err { color: #ffd0d0; }
.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
@media (min-width: 720px) {
  .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
.domain, .topic, .speak, .chip, .en-word {
  border: none;
  cursor: pointer;
  color: inherit;
  touch-action: manipulation;
}
.domain {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  min-height: 120px;
  padding: 16px 10px;
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.14);
}
.domain .emoji { font-size: 36px; line-height: 1; }
.domain .name { font-weight: 700; text-align: center; }
.domain .count, .age, .attr, .domain-tag, .words-hint, .name-en {
  opacity: 0.85;
  font-size: 13px;
}
.list { display: flex; flex-direction: column; gap: 10px; }
.topic {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 12px;
  align-items: center;
  text-align: left;
  padding: 14px 16px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.14);
}
.topic.locked { opacity: 0.72; }
.topic .emoji { font-size: 28px; }
.topic .meta { display: flex; flex-direction: column; gap: 2px; }
.topic .name { font-weight: 700; }
.badge {
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 999px;
  background: rgba(120, 220, 160, 0.35);
}
.badge.locked { background: rgba(255, 200, 120, 0.35); }
.article {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 8px 4px 24px;
}
.hero {
  font-size: 88px;
  line-height: 1;
  text-align: center;
  filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.18));
}
.article h2 {
  margin: 0;
  text-align: center;
  font-size: clamp(22px, 5vw, 30px);
}
.title-en {
  margin: 0;
  text-align: center;
  font-size: 16px;
}
.body {
  margin: 0;
  font-size: 18px;
  line-height: 1.65;
  white-space: pre-wrap;
}
.body-en {
  font-size: 17px;
  line-height: 1.7;
}
.en-word {
  display: inline;
  padding: 0 1px;
  margin: 0;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 4px;
  font: inherit;
  text-decoration: underline;
  text-decoration-thickness: 1px;
  text-underline-offset: 3px;
}
.en-word:disabled { opacity: 0.6; }
.words-hint, .word-flash { text-align: center; margin: 0; }
.word-flash { color: #c8f7d4; font-weight: 700; }
.speak-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: center;
  margin-top: 4px;
}
.video { display: flex; flex-direction: column; gap: 8px; }
.video-label { margin: 0; text-align: center; font-weight: 700; }
.video-frame {
  position: relative;
  width: 100%;
  padding-top: 56.25%;
  border-radius: 16px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.25);
}
.video-frame iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}
.speak {
  align-self: center;
  margin-top: 4px;
  padding: 12px 22px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.22);
  font-weight: 700;
}
.finish-read { margin-top: 8px; }
.related h3 {
  margin: 12px 0 8px;
  font-size: 16px;
}
.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  font-size: 14px;
}
.chip.locked { opacity: 0.7; }
.attr {
  margin-top: 18px;
  text-align: center;
  font-size: 12px;
  line-height: 1.4;
  opacity: 0.75;
}
</style>
