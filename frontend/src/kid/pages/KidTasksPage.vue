<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { TaskInstance } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const instances = ref<TaskInstance[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref<number | null>(null)

function statusLabel(status: string): string {
  const key = `kid.taskStatus.${status}`
  return t(key)
}

function titleOf(inst: TaskInstance): string {
  const title = inst.snapshot?.title
  return typeof title === 'string' && title ? title : t('common.taskDefault')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    instances.value = await api.get<TaskInstance[]>('/kid/tasks')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function ready(inst: TaskInstance): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = inst.id
  try {
    const updated = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${inst.id}/ready`)
    replace(updated)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.operationFailed')
  } finally {
    busy.value = null
  }
}

async function submit(inst: TaskInstance): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = inst.id
  try {
    const updated = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${inst.id}/submit`)
    replace(updated)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.submitFailed')
  } finally {
    busy.value = null
  }
}

function replace(updated: TaskInstance): void {
  const i = instances.value.findIndex((x) => x.id === updated.id)
  if (i >= 0) instances.value[i] = updated
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.tasks') }}</h1>
      <RouterLink class="link" :to="{ name: 'kid-habits' }">{{ t('kid.navHabits') }}</RouterLink>
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <el-empty v-if="!loading && instances.length === 0" :description="t('kid.tasksEmpty')" />

    <article v-for="inst in instances" :key="inst.id" class="card">
      <h2>{{ titleOf(inst) }}</h2>
      <p class="status">{{ statusLabel(inst.status) }}</p>
      <p v-if="inst.snapshot?.xp_reward || inst.snapshot?.coin_reward" class="reward">
        {{ t('kid.taskReward', { xp: inst.snapshot.xp_reward ?? 0, coins: inst.snapshot.coin_reward ?? 0 }) }}
      </p>
      <div class="actions">
        <button
          v-if="inst.status === 'pending' || inst.status === 'returned'"
          type="button"
          class="big primary"
          :disabled="busy === inst.id"
          @click="ready(inst)"
        >
          {{ inst.status === 'returned' ? t('kid.taskActions.restart') : t('kid.taskActions.start') }}
        </button>
        <button v-if="inst.status === 'in_progress'" type="button" class="big sun" :disabled="busy === inst.id" @click="submit(inst)">
          {{ t('kid.taskActions.done') }}
        </button>
      </div>
    </article>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 32px;
  max-width: 560px;
  margin: 0 auto;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 16px;
}
.head h1 {
  margin: 0;
  font-size: 22px;
  flex: 1;
  text-align: center;
}
.back,
.link {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  text-decoration: none;
  cursor: pointer;
  min-height: 44px;
}
.card {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  padding: 20px;
  margin-bottom: 14px;
}
.card h2 {
  margin: 0 0 8px;
  font-size: 20px;
}
.status {
  margin: 0 0 8px;
  opacity: 0.85;
}
.reward {
  margin: 0 0 16px;
  font-size: 15px;
}
.actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.big {
  min-height: 56px;
  border: none;
  border-radius: 16px;
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
}
.big.primary {
  background: #5b8def;
  color: #fff;
}
.big.sun {
  background: var(--gp-sun);
  color: var(--gp-space-dark);
}
.big:disabled {
  opacity: 0.5;
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
