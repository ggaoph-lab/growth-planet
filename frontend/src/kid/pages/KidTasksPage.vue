<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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

function dueOf(inst: TaskInstance): string {
  const due = inst.snapshot?.due_time
  return typeof due === 'string' && due ? due.slice(0, 5) : ''
}

function groupIdOf(inst: TaskInstance): number | null {
  const raw = inst.snapshot?.group_task_id
  const id = typeof raw === 'number' ? raw : typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : NaN
  return Number.isInteger(id) && id > 0 ? id : null
}

function linesOf(inst: TaskInstance): { key: string; title: string }[] {
  const related = inst.instance_steps
  if (Array.isArray(related) && related.length > 0) {
    return [...related]
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .filter((step) => step.title)
      .map((step) => ({ key: `r-${step.id}`, title: step.title }))
  }
  const steps = inst.snapshot?.steps
  if (!Array.isArray(steps)) return []
  return steps
    .filter((step) => typeof step?.title === 'string' && step.title.length > 0)
    .map((step, index) => ({ key: `s-${step.id ?? index}`, title: step.title }))
}

interface TaskGroup {
  key: string
  title: string
  isGroup: boolean
  items: TaskInstance[]
}

const groups = computed<TaskGroup[]>(() => {
  const out: TaskGroup[] = []
  const index = new Map<string, TaskGroup>()
  for (const inst of instances.value) {
    const groupId = groupIdOf(inst)
    if (groupId !== null) {
      const key = `g-${groupId}`
      let group = index.get(key)
      if (!group) {
        const title = inst.snapshot?.group_title
        group = {
          key,
          title: typeof title === 'string' && title ? title : t('common.taskDefault'),
          isGroup: true,
          items: [],
        }
        index.set(key, group)
        out.push(group)
      }
      group.items.push(inst)
    } else {
      out.push({ key: `i-${inst.id}`, title: '', isGroup: false, items: [inst] })
    }
  }
  for (const group of out) {
    if (!group.isGroup) continue
    group.items.sort((a, b) => dueOf(a).localeCompare(dueOf(b)))
  }
  return out
})

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

    <article v-for="group in groups" :key="group.key" class="card">
      <header v-if="group.isGroup" class="group-head">
        <h2>{{ group.title }}</h2>
        <p class="status">{{ t('common.subtaskCount', { n: group.items.length }) }}</p>
      </header>
      <div v-for="inst in group.items" :key="inst.id" class="sub" :class="{ nested: group.isGroup }">
        <h2 v-if="!group.isGroup">{{ titleOf(inst) }}</h2>
        <h3 v-else>{{ dueOf(inst) ? `${dueOf(inst)} ${titleOf(inst)}` : titleOf(inst) }}</h3>
        <p class="status">{{ statusLabel(inst.status) }}</p>
        <p v-if="!group.isGroup && dueOf(inst)" class="status">{{ dueOf(inst) }}</p>
        <ul v-if="linesOf(inst).length" class="items">
          <li v-for="line in linesOf(inst)" :key="line.key">{{ line.title }}</li>
        </ul>
        <p v-if="inst.snapshot?.with_parent" class="with-parent">{{ t('common.withParentTag') }}</p>
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
.card h3 {
  margin: 0 0 8px;
  font-size: 18px;
}
.group-head {
  margin-bottom: 4px;
}
.group-head h2 {
  margin-bottom: 4px;
}
.group-head .status {
  margin-bottom: 0;
}
.sub.nested {
  margin-top: 14px;
  padding: 14px 12px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.06);
}
.sub.nested + .sub.nested {
  margin-top: 10px;
}
.sub:not(.nested) + .sub:not(.nested) {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(255, 255, 255, 0.12);
}
.items {
  list-style: none;
  margin: 0 0 14px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.items li {
  margin: 0;
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.08);
  font-size: 16px;
  font-weight: 600;
}
.status {
  margin: 0 0 8px;
  opacity: 0.85;
}
.with-parent {
  display: inline-block;
  margin: 0 0 10px;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(255, 196, 86, 0.25);
  color: #ffe7a8;
  font-size: 13px;
  font-weight: 600;
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
