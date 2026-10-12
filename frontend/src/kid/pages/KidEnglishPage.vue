<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { EnglishShow, EnglishWatchLog } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const shows = ref<EnglishShow[]>([])
const logs = ref<EnglishWatchLog[]>([])
const loading = ref(true)
const error = ref('')
const showId = ref<number | undefined>()
const minutes = ref(15)
const selfChosen = ref(false)
const liking = ref<'like' | 'ok' | 'dislike'>('like')

function statusLabel(status: string): string {
  const key = `kid.englishStatus.${status}`
  return t(key)
}

function likingLabel(opt: 'like' | 'ok' | 'dislike'): string {
  if (opt === 'like') return t('common.like')
  if (opt === 'ok') return t('common.ok')
  return t('common.dislike')
}

async function load(): Promise<void> {
  loading.value = true
  try {
    shows.value = await api.get<EnglishShow[]>('/kid/english/shows')
    logs.value = await api.get<EnglishWatchLog[]>('/kid/english/logs')
    showId.value = shows.value[0]?.id
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (!showId.value) return
  if (session.me?.child?.preferences.sounds) playTap()
  try {
    const today = new Date().toISOString().slice(0, 10)
    await api.postIdempotent('/kid/english/logs', {
      show_id: showId.value,
      watch_date: today,
      minutes: minutes.value,
      self_chosen: selfChosen.value,
      liking: liking.value,
    })
    await load()
    error.value = ''
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.registerFailed')
  }
}

async function cancelLog(id: number): Promise<void> {
  try {
    await api.delete(`/kid/english/logs/${id}`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.withdrawFailed')
  }
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.english') }}</h1>
    </header>
    <p class="hint">{{ t('common.englishHint') }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <section v-if="!loading" class="form">
      <label>
        {{ t('common.show') }}
        <select v-model="showId">
          <option v-for="s in shows" :key="s.id" :value="s.id">{{ s.emoji }} {{ s.title }}</option>
        </select>
      </label>
      <label>
        {{ t('common.minutesStep') }}
        <input v-model.number="minutes" type="number" min="5" max="120" step="5" />
      </label>
      <label class="check"><input v-model="selfChosen" type="checkbox" /> {{ t('common.selfChosenEnglish') }}</label>
      <div class="liking">
        <button v-for="opt in ['like', 'ok', 'dislike'] as const" :key="opt" type="button" class="chip" :class="{ on: liking === opt }" @click="liking = opt">
          {{ likingLabel(opt) }}
        </button>
      </div>
      <button type="button" class="btn" @click="submit">{{ t('common.submitLog') }}</button>
    </section>
    <section>
      <h2>{{ t('common.myLogs') }}</h2>
      <article v-for="log in logs" :key="log.id" class="card">
        <div>{{ log.show?.title }} · {{ log.minutes }} {{ t('common.minutes') }}</div>
        <div class="muted">{{ statusLabel(log.status) }}</div>
        <button v-if="log.status === 'PENDING'" type="button" class="link" @click="cancelLog(log.id)">{{ t('common.withdraw') }}</button>
      </article>
    </section>
  </main>
</template>

<style scoped>
.page { padding: 0 16px 32px; max-width: 720px; margin: 0 auto; }
@media (min-width: 768px) {
  .page { padding: 0 24px 40px; }
}
@media (min-width: 1024px) {
  .page { max-width: 960px; }
}
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.back { background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; }
.hint { opacity: 0.85; font-size: 14px; }
.form { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.form label { display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
select, input { padding: 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.2); color: inherit; }
.check { flex-direction: row !important; align-items: center; gap: 8px !important; }
.liking { display: flex; gap: 8px; flex-wrap: wrap; }
.chip { border: 1px solid rgba(255,255,255,0.3); background: transparent; color: inherit; border-radius: 999px; padding: 6px 12px; }
.chip.on { background: var(--gp-accent, #5b8def); border-color: transparent; }
.btn { border: none; border-radius: 12px; padding: 12px; background: var(--gp-accent, #5b8def); color: #fff; font-weight: 700; }
.card { background: rgba(255,255,255,0.06); padding: 12px; border-radius: 12px; margin-bottom: 8px; }
.muted { font-size: 13px; opacity: 0.75; }
.link { background: none; border: none; color: #9fd0ff; margin-top: 6px; }
.error { color: #ffb4b4; }
</style>
