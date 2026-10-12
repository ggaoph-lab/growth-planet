<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { ExplorationProject } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const projects = ref<ExplorationProject[]>([])
const loading = ref(true)
const error = ref('')
const title = ref('')
const busy = ref(false)
const toast = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    projects.value = await api.get<ExplorationProject[]>('/kid/explorations')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function createProject(): Promise<void> {
  if (!title.value.trim()) return
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = true
  try {
    const p = await api.postIdempotent<ExplorationProject>('/kid/explorations', {
      title: title.value.trim(),
      category: 'activity',
    })
    projects.value.unshift(p)
    title.value = ''
    toast.value = t('common.newExploreProject')
    setTimeout(() => (toast.value = ''), 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.createFailed')
  } finally {
    busy.value = false
  }
}

async function logStage(p: ExplorationProject): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  try {
    await api.postIdempotent(`/kid/explorations/${p.id}/events`, {
      stage: Math.min(5, p.current_stage + 1),
      feeling: '😊',
      willing_next: 'yes',
      child_words: t('kid.exploreTryAgain'),
    })
    await load()
    toast.value = t('common.recordSuccess')
    setTimeout(() => (toast.value = ''), 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.recordFailed')
  }
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.explore') }}</h1>
      <span class="spacer" />
    </header>

    <div class="new">
      <input v-model="title" type="text" :placeholder="t('common.explorePlaceholder')" class="input" />
      <button type="button" class="big" :disabled="busy" @click="createProject">{{ t('common.start') }}</button>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <article v-for="p in projects" :key="p.id" class="card">
      <h2>{{ p.title }}</h2>
      <p class="meta">{{ t('common.stageOf', { current: p.current_stage }) }} · {{ p.category }}</p>
      <button type="button" class="big" @click="logStage(p)">{{ t('common.recordToday') }}</button>
    </article>

    <el-empty v-if="!loading && projects.length === 0" :description="t('kid.exploreEmpty')" />

    <div v-if="toast" class="toast">{{ toast }}</div>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 32px;
  max-width: 560px;
  margin: 0 auto;
}
@media (min-width: 768px) {
  .page {
    max-width: 720px;
    padding: 0 24px 40px;
  }
}
@media (min-width: 1024px) {
  .page { max-width: 960px; }
}
.head {
  display: flex;
  align-items: center;
  margin-bottom: 16px;
}
.head h1 {
  flex: 1;
  text-align: center;
  margin: 0;
  font-size: 22px;
}
.spacer {
  width: 72px;
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
}
.new {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
.input {
  flex: 1;
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(0, 0, 0, 0.2);
  color: #fff;
}
.card {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 16px;
  margin-bottom: 12px;
}
.card h2 {
  margin: 0 0 6px;
}
.meta {
  margin: 0 0 12px;
  opacity: 0.8;
  font-size: 14px;
}
.big {
  width: 100%;
  min-height: 48px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(135deg, #6b9fff, #5b8def);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}
.toast {
  position: fixed;
  left: 50%;
  bottom: 24px;
  transform: translateX(-50%);
  padding: 12px 20px;
  border-radius: 999px;
  background: #fff;
  color: var(--gp-space-dark);
  font-weight: 600;
}
.err {
  color: #ffb4b4;
  text-align: center;
}
.hint {
  text-align: center;
  opacity: 0.7;
}
</style>
