<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type {
  EncyclopediaDomainDetail,
  EncyclopediaSubject,
  EncyclopediaTopicDetail,
} from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { speakPhrase, unlockSpeak } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

const { t, locale } = useI18n()
const router = useRouter()
const session = useSessionStore()

const subject = ref<EncyclopediaSubject | null>(null)
const domain = ref<EncyclopediaDomainDetail | null>(null)
const article = ref<EncyclopediaTopicDetail | null>(null)
const loading = ref(true)
const error = ref('')
const speaking = ref(false)
const savingRead = ref(false)

const view = computed(() => {
  if (article.value) return 'article' as const
  if (domain.value) return 'domain' as const
  return 'home' as const
})

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

/** Prefer the locale side of BiMsg "中文 / English" strings for speech and compact UI. */
function displayText(raw: string): string {
  const parts = raw.split(/\s*\/\s*/)
  if (parts.length < 2) return raw
  if (locale.value === 'en') return parts[parts.length - 1] ?? raw
  if (locale.value === 'zh-en') return raw
  return parts[0] ?? raw
}

function speakText(raw: string): string {
  const parts = raw.split(/\s*\/\s*/)
  if (locale.value === 'en') return parts[parts.length - 1] ?? raw
  return parts[0] ?? raw
}

async function loadHome(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    subject.value = await api.get<EncyclopediaSubject>('/kid/encyclopedia')
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

async function openTopic(id: string): Promise<void> {
  tap()
  void unlockSpeak()
  loading.value = true
  error.value = ''
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
      domain.value = {
        ...domain.value,
        topics: domain.value.topics.map((row) => (row.id === id ? { ...row, read: true } : row)),
      }
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    savingRead.value = false
  }
}

function back(): void {
  tap()
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

async function readAloud(): Promise<void> {
  if (!article.value) return
  tap()
  speaking.value = true
  try {
    await unlockSpeak()
    await speakPhrase(`${speakText(article.value.name)}。${speakText(article.value.body)}`)
  } finally {
    speaking.value = false
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
        <template v-if="view === 'article' && article">{{ displayText(article.name) }}</template>
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
        :class="{ read: row.read }"
        @click="openTopic(row.id)"
      >
        <span class="emoji" aria-hidden="true">{{ row.emoji }}</span>
        <span class="meta">
          <span class="name">{{ displayText(row.name) }}</span>
          <span class="age">{{ t('kid.encyclopedia.age', { start: row.age_start, end: row.age_end }) }}</span>
        </span>
        <span v-if="row.read" class="badge">{{ t('kid.encyclopedia.read') }}</span>
      </button>
    </section>

    <article v-else-if="view === 'article' && article" class="article">
      <div class="hero" aria-hidden="true">{{ article.emoji }}</div>
      <p class="domain-tag">{{ displayText(article.domain) }}</p>
      <h2>{{ displayText(article.name) }}</h2>
      <p class="age">{{ t('kid.encyclopedia.age', { start: article.age_start, end: article.age_end }) }}</p>
      <p class="body">{{ speakText(article.body) }}</p>
      <button type="button" class="speak" :disabled="speaking" @click="readAloud">
        {{ speaking ? t('kid.encyclopedia.speaking') : t('kid.encyclopedia.speak') }}
      </button>
      <button v-if="!article.read" type="button" class="speak" :disabled="savingRead" @click="markRead">
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
            @click="openTopic(row.id)"
          >
            <span aria-hidden="true">{{ row.emoji }}</span>
            {{ displayText(row.name) }}
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
            @click="openTopic(row.id)"
          >
            <span aria-hidden="true">{{ row.emoji }}</span>
            {{ displayText(row.name) }}
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
.domain, .topic, .speak, .chip {
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
.domain .count, .age, .attr, .domain-tag {
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
.topic .emoji { font-size: 28px; }
.topic .meta { display: flex; flex-direction: column; gap: 2px; }
.topic .name { font-weight: 700; }
.badge {
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 999px;
  background: rgba(120, 220, 160, 0.35);
}
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
.body {
  margin: 0;
  font-size: 18px;
  line-height: 1.65;
  white-space: pre-wrap;
}
.speak {
  align-self: center;
  margin-top: 4px;
  padding: 12px 22px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.22);
  font-weight: 700;
}
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
.attr {
  margin-top: 18px;
  text-align: center;
  font-size: 12px;
  line-height: 1.4;
  opacity: 0.75;
}
</style>
