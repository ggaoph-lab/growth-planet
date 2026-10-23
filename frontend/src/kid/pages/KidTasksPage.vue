<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type { TaskInstance } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { canSpeak, speakTask, stopSpeaking, taskPhrase, warmPhrases } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

const { t, locale } = useI18n()
const router = useRouter()
const session = useSessionStore()
const instances = ref<TaskInstance[]>(peekWarmGet<TaskInstance[]>('/kid/tasks') ?? [])
const loading = ref(peekWarmGet('/kid/tasks') == null)
const error = ref('')
const busy = ref<string | null>(null)
const speakingKey = ref<string | null>(null)
const speechSupported = canSpeak()

type StepLine = {
  key: string
  id: number | null
  title: string
  checked: boolean
  isRequired: boolean
}

function statusLabel(status: string): string {
  const key = `kid.taskStatus.${status}`
  return t(key)
}

function speechLang(): string {
  return locale.value === 'en' ? 'en-US' : 'zh-CN'
}

/** Prefer snapshot description; fall back to splitting legacy "标题：说明" titles. */
function titleDescOf(inst: TaskInstance): { title: string; description: string } {
  const raw = inst.snapshot?.title
  const full = typeof raw === 'string' && raw.trim() ? raw.trim() : t('common.taskDefault')
  const descRaw = inst.snapshot?.description
  if (typeof descRaw === 'string' && descRaw.trim()) {
    return { title: full, description: descRaw.trim() }
  }
  const matched = full.match(/^(.+?)[：:](.+)$/)
  if (matched?.[1] && matched[2]) {
    return { title: matched[1].trim(), description: matched[2].trim() }
  }
  return { title: full, description: '' }
}

function titleOf(inst: TaskInstance): string {
  return titleDescOf(inst).title
}

function descriptionOf(inst: TaskInstance): string {
  return titleDescOf(inst).description
}

async function readAloud(key: string, title: string, description?: string): Promise<void> {
  if (!speechSupported) {
    error.value = t('kid.taskActions.speechUnsupported')
    return
  }
  if (session.me?.child?.preferences.sounds) playTap()
  // Second tap on the same speaker stops reading.
  if (speakingKey.value === key) {
    stopSpeaking()
    speakingKey.value = null
    return
  }
  stopSpeaking()
  speakingKey.value = key
  try {
    await speakTask(title, description, speechLang())
  } catch {
    error.value = t('kid.taskActions.speechUnsupported')
  } finally {
    if (speakingKey.value === key) speakingKey.value = null
  }
}

function dueOf(inst: TaskInstance): string {
  const due = inst.snapshot?.due_time
  return typeof due === 'string' && due ? due.slice(0, 5) : ''
}

function rewardOf(inst: TaskInstance): string | null {
  const xp = inst.snapshot?.xp_reward ?? 0
  const points = inst.snapshot?.point_reward ?? 0
  const coins = inst.snapshot?.coin_reward ?? 0
  if (!xp && !points && !coins) return null
  if (inst.status === 'completed') return t('kid.taskReward', { xp, points, coins })
  return t('kid.taskRewardPending', { xp, points, coins })
}

function groupIdOf(inst: TaskInstance): number | null {
  const raw = inst.snapshot?.group_task_id
  const id = typeof raw === 'number' ? raw : typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : NaN
  return Number.isInteger(id) && id > 0 ? id : null
}

function stepsOf(inst: TaskInstance): StepLine[] {
  const related = inst.instance_steps
  if (Array.isArray(related) && related.length > 0) {
    return [...related]
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .filter((step) => step.title)
      .map((step) => ({
        key: `r-${step.id}`,
        id: step.id,
        title: step.title,
        checked: Boolean(step.checked),
        isRequired: step.is_required !== false,
      }))
  }
  const steps = inst.snapshot?.steps
  if (!Array.isArray(steps)) return []
  return steps
    .filter((step) => typeof step?.title === 'string' && step.title.length > 0)
    .map((step, index) => ({
      key: `s-${step.id ?? index}`,
      id: typeof step.id === 'number' ? step.id : null,
      title: step.title,
      checked: false,
      isRequired: true,
    }))
}

function allRequiredStepsChecked(inst: TaskInstance): boolean {
  const steps = stepsOf(inst)
  if (steps.length === 0) return true
  return steps.filter((s) => s.isRequired).every((s) => s.checked)
}

function canActOn(inst: TaskInstance): boolean {
  return inst.status === 'pending' || inst.status === 'returned' || inst.status === 'in_progress'
}

function canWithdraw(inst: TaskInstance): boolean {
  if (typeof inst.can_withdraw === 'boolean') return inst.can_withdraw
  return inst.status === 'submitted' || inst.status === 'completed'
}

function isDoneStatus(status: string): boolean {
  return status === 'submitted' || status === 'completed'
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

async function load(force = false): Promise<void> {
  if (force || peekWarmGet('/kid/tasks') == null) loading.value = true
  error.value = ''
  try {
    instances.value = await warmGet<TaskInstance[]>('/kid/tasks', undefined, { force })
    const phrases: string[] = []
    for (const inst of instances.value) {
      phrases.push(taskPhrase(titleOf(inst), descriptionOf(inst)))
      for (const step of stepsOf(inst)) phrases.push(taskPhrase(step.title))
    }
    warmPhrases(phrases)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

async function ready(inst: TaskInstance): Promise<TaskInstance | null> {
  try {
    const updated = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${inst.id}/ready`)
    replace(updated)
    return updated
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.operationFailed')
    return null
  }
}

async function submit(inst: TaskInstance): Promise<TaskInstance | null> {
  try {
    const updated = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${inst.id}/submit`)
    replace(updated)
    return updated
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.submitFailed')
    return null
  }
}

async function withdraw(inst: TaskInstance): Promise<TaskInstance | null> {
  try {
    const updated = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${inst.id}/withdraw`)
    replace(updated)
    return updated
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.withdrawFailed')
    return null
  }
}

/** Tap a finished subtask again to take the completion back. */
async function withdrawSubtask(inst: TaskInstance): Promise<void> {
  if (!canWithdraw(inst) || busy.value) return
  tap()
  busy.value = `i-${inst.id}`
  error.value = ''
  const snapshot = { ...inst }
  // Optimistic undo so the row clears immediately.
  replace({
    ...inst,
    status: 'pending',
    can_withdraw: false,
    ready_at: null,
    first_step_at: null,
    submitted_at: null,
    completed_at: null,
  })
  try {
    const updated = await withdraw(inst)
    if (!updated) replace(snapshot)
  } catch {
    replace(snapshot)
  } finally {
    busy.value = null
  }
}

function onSubtaskClick(inst: TaskInstance): void {
  if (canWithdraw(inst)) {
    void withdrawSubtask(inst)
    return
  }
  void advanceSubtask(inst)
}

/** Tap a subtask row to complete it (one submit; self-check still starts first). */
async function advanceSubtask(inst: TaskInstance): Promise<void> {
  if (!canActOn(inst) || busy.value) return
  tap()
  busy.value = `i-${inst.id}`
  error.value = ''
  try {
    // Self-check tasks only start here; kid confirms done on a later tap.
    if (inst.snapshot?.requires_self_check && (inst.status === 'pending' || inst.status === 'returned')) {
      await ready(inst)
      return
    }

    const snapshot = { ...inst }
    // Optimistic complete — UI should not wait on the reward ledger round-trip.
    replace({
      ...inst,
      status: 'completed',
      can_withdraw: true,
      submitted_at: inst.submitted_at ?? new Date().toISOString(),
      completed_at: new Date().toISOString(),
    })
    const updated = await submit(inst)
    if (!updated) replace(snapshot)
  } finally {
    busy.value = null
  }
}

async function startCard(inst: TaskInstance): Promise<void> {
  if (busy.value) return
  tap()
  busy.value = `i-${inst.id}`
  error.value = ''
  try {
    await ready(inst)
  } finally {
    busy.value = null
  }
}

async function submitCard(inst: TaskInstance): Promise<void> {
  if (busy.value) return
  if (!allRequiredStepsChecked(inst)) {
    error.value = t('kid.taskActions.finishStepsFirst')
    return
  }
  tap()
  busy.value = `i-${inst.id}`
  error.value = ''
  const snapshot = { ...inst }
  replace({
    ...inst,
    status: 'completed',
    can_withdraw: true,
    submitted_at: inst.submitted_at ?? new Date().toISOString(),
    completed_at: new Date().toISOString(),
  })
  try {
    const updated = await submit(inst)
    if (!updated) replace(snapshot)
  } finally {
    busy.value = null
  }
}

async function toggleStep(inst: TaskInstance, step: StepLine): Promise<void> {
  if (!step.id || busy.value) return
  if (!canActOn(inst)) return

  const nextChecked = !step.checked
  tap()
  busy.value = `s-${step.id}`
  error.value = ''
  // Optimistic UI so the row updates immediately even if a later response omits steps.
  setStepChecked(inst.id, step.id, nextChecked)
  try {
    let current = instances.value.find((x) => x.id === inst.id) ?? inst
    if (nextChecked && (current.status === 'pending' || current.status === 'returned')) {
      const started = await ready(current)
      if (!started) return
      current = started
    }

    if (nextChecked && current.first_step_at == null && current.status === 'in_progress') {
      try {
        const marked = await api.postIdempotent<TaskInstance>(`/kid/task-instances/${current.id}/first-step`)
        replace(marked)
        current = instances.value.find((x) => x.id === current.id) ?? marked
      } catch {
        // first-step is optional bookkeeping; continue toggling the step
      }
    }

    const updated = await api.postIdempotent<TaskInstance | { id: number; checked?: boolean }>(
      `/kid/task-instances/${current.id}/steps/${step.id}/check`,
      { checked: nextChecked },
    )
    if (updated && typeof updated === 'object' && 'status' in updated && 'id' in updated) {
      replace(updated as TaskInstance)
    } else {
      setStepChecked(current.id, step.id, nextChecked)
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.operationFailed')
    // Reload so a failed toggle does not leave a wrong checkmark.
    await load(true)
  } finally {
    busy.value = null
  }
}

function setStepChecked(instanceId: number, stepId: number, checked: boolean): void {
  const prev = instances.value.find((x) => x.id === instanceId)
  if (!prev) return
  const existing = prev.instance_steps ?? []
  if (existing.some((s) => s.id === stepId)) {
    replace({
      ...prev,
      instance_steps: existing.map((s) => (s.id === stepId ? { ...s, id: s.id, title: s.title, checked } : s)),
    })
    return
  }
  // ready/first-step responses often omit steps; rebuild from the visible line list.
  const lines = stepsOf(prev)
  replace({
    ...prev,
    instance_steps: lines.map((line, index) => ({
      id: line.id ?? stepId,
      title: line.title,
      sort: index,
      is_required: line.isRequired,
      checked: line.id === stepId ? checked : line.checked,
    })),
  })
}

/** Merge API payloads without dropping instance_steps the server omitted. */
function replace(updated: TaskInstance): void {
  const prev = instances.value.find((x) => x.id === updated.id)
  if (!prev) return
  const next: TaskInstance = {
    ...prev,
    ...updated,
    snapshot: updated.snapshot ?? prev.snapshot,
    instance_steps: updated.instance_steps ?? prev.instance_steps,
  }
  const i = instances.value.findIndex((x) => x.id === updated.id)
  if (i >= 0) instances.value[i] = next
}

function subtaskActionLabel(inst: TaskInstance): string {
  if (canWithdraw(inst)) return `${statusLabel(inst.status)} · ${t('kid.taskActions.tapToUndo')}`
  if (isDoneStatus(inst.status)) return statusLabel(inst.status)
  if (inst.status === 'returned') return t('kid.taskActions.tapToFinish')
  if (inst.status === 'in_progress' && inst.snapshot?.requires_self_check) {
    return t('kid.taskActions.done')
  }
  if (canActOn(inst)) return t('kid.taskActions.tapToFinish')
  return statusLabel(inst.status)
}

onMounted(load)
onUnmounted(stopSpeaking)
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
      <!-- Grouped subtasks: one card, each row clickable -->
      <template v-if="group.isGroup">
        <header class="group-head">
          <h2>{{ group.title }}</h2>
          <p class="status">{{ t('common.subtaskCount', { n: group.items.length }) }}</p>
        </header>
        <ul class="checklist">
          <li v-for="inst in group.items" :key="inst.id" class="check-item">
            <button
              type="button"
              class="check-row"
              :class="{
                done: isDoneStatus(inst.status),
                active: inst.status === 'in_progress' || inst.status === 'returned',
                locked: !canActOn(inst) && !canWithdraw(inst),
              }"
              :disabled="busy === `i-${inst.id}` || (!canActOn(inst) && !canWithdraw(inst))"
              :aria-label="`${titleOf(inst)} — ${subtaskActionLabel(inst)}`"
              @click="onSubtaskClick(inst)"
            >
              <span class="mark" aria-hidden="true">{{ isDoneStatus(inst.status) ? '✓' : inst.status === 'in_progress' ? '●' : '○' }}</span>
              <span class="check-body">
                <span class="check-title">
                  <template v-if="dueOf(inst)">{{ dueOf(inst) }} </template>{{ titleOf(inst) }}
                </span>
                <span v-if="descriptionOf(inst)" class="check-desc">{{ descriptionOf(inst) }}</span>
                <span class="check-meta">
                  <template v-if="inst.snapshot?.with_parent">{{ t('common.withParentTag') }} · </template>
                  {{ subtaskActionLabel(inst) }}
                  <template v-if="rewardOf(inst)"> · {{ rewardOf(inst) }}</template>
                </span>
              </span>
            </button>
            <button
              v-if="speechSupported"
              type="button"
              class="speak-btn"
              :class="{ speaking: speakingKey === `i-${inst.id}` }"
              :aria-label="speakingKey === `i-${inst.id}` ? t('kid.taskActions.stopAloud') : t('kid.taskActions.readAloud')"
              @click.stop="readAloud(`i-${inst.id}`, titleOf(inst), descriptionOf(inst))"
            >
              {{ speakingKey === `i-${inst.id}` ? '⏹' : '🔊' }}
            </button>
          </li>
        </ul>
      </template>

      <!-- Single task card -->
      <template v-else>
        <div v-for="inst in group.items" :key="inst.id" class="sub">
          <div class="card-title-row">
            <h2>{{ titleOf(inst) }}</h2>
            <button
              v-if="speechSupported"
              type="button"
              class="speak-btn"
              :class="{ speaking: speakingKey === `card-${inst.id}` }"
              :aria-label="speakingKey === `card-${inst.id}` ? t('kid.taskActions.stopAloud') : t('kid.taskActions.readAloud')"
              @click.stop="readAloud(`card-${inst.id}`, titleOf(inst), descriptionOf(inst))"
            >
              {{ speakingKey === `card-${inst.id}` ? '⏹' : '🔊' }}
            </button>
          </div>
          <p v-if="descriptionOf(inst)" class="card-desc">{{ descriptionOf(inst) }}</p>
          <p class="status">{{ statusLabel(inst.status) }}</p>
          <p v-if="dueOf(inst)" class="status">{{ dueOf(inst) }}</p>
          <p v-if="inst.snapshot?.with_parent" class="with-parent">{{ t('common.withParentTag') }}</p>
          <p v-if="rewardOf(inst)" class="reward">{{ rewardOf(inst) }}</p>

          <ul v-if="stepsOf(inst).length" class="checklist">
            <li v-for="step in stepsOf(inst)" :key="step.key" class="check-item">
              <button
                type="button"
                class="check-row"
                :class="{ done: step.checked, locked: !canActOn(inst) || !step.id }"
                :disabled="!step.id || busy === `s-${step.id}` || !canActOn(inst)"
                :aria-label="step.title"
                @click="toggleStep(inst, step)"
              >
                <span class="mark" aria-hidden="true">{{ step.checked ? '✓' : '○' }}</span>
                <span class="check-body">
                  <span class="check-title">{{ step.title }}</span>
                  <span v-if="step.checked && canActOn(inst)" class="check-meta">{{ t('kid.taskActions.tapToUndo') }}</span>
                </span>
              </button>
              <button
                v-if="speechSupported"
                type="button"
                class="speak-btn"
                :class="{ speaking: speakingKey === step.key }"
                :aria-label="speakingKey === step.key ? t('kid.taskActions.stopAloud') : t('kid.taskActions.readAloud')"
                @click.stop="readAloud(step.key, step.title)"
              >
                {{ speakingKey === step.key ? '⏹' : '🔊' }}
              </button>
            </li>
          </ul>

          <div class="actions">
            <button
              v-if="inst.status === 'pending' || inst.status === 'returned'"
              type="button"
              class="big primary"
              :disabled="busy === `i-${inst.id}`"
              @click="startCard(inst)"
            >
              {{ inst.status === 'returned' ? t('kid.taskActions.restart') : t('kid.taskActions.start') }}
            </button>
            <button
              v-if="inst.status === 'in_progress'"
              type="button"
              class="big sun"
              :disabled="busy === `i-${inst.id}` || !allRequiredStepsChecked(inst)"
              @click="submitCard(inst)"
            >
              {{ t('kid.taskActions.done') }}
            </button>
            <button
              v-if="canWithdraw(inst)"
              type="button"
              class="big ghost"
              :disabled="busy === `i-${inst.id}`"
              @click="withdrawSubtask(inst)"
            >
              {{ t('kid.taskActions.tapToUndo') }}
            </button>
          </div>
        </div>
      </template>
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
.group-head {
  margin-bottom: 12px;
}
.group-head h2 {
  margin-bottom: 4px;
}
.group-head .status {
  margin-bottom: 0;
}
.sub {
  display: flex;
  flex-direction: column;
}
.checklist {
  list-style: none;
  margin: 0 0 14px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.group-head + .checklist {
  margin-bottom: 0;
}
.check-item {
  display: flex;
  align-items: stretch;
  gap: 8px;
}
.card-title-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;
}
.card-title-row h2 {
  flex: 1;
  margin: 0;
}
.card-desc {
  margin: 0 0 10px;
  font-size: 15px;
  line-height: 1.45;
  opacity: 0.9;
}
.check-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex: 1;
  min-width: 0;
  margin: 0;
  padding: 14px 14px;
  border: none;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.08);
  color: inherit;
  text-align: left;
  cursor: pointer;
  min-height: 52px;
  transition: background 0.15s ease, transform 0.1s ease;
}
.speak-btn {
  flex-shrink: 0;
  width: 48px;
  min-height: 52px;
  border: none;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 22px;
  cursor: pointer;
  line-height: 1;
}
.speak-btn.speaking {
  background: rgba(255, 196, 86, 0.35);
  animation: pulse-speak 1s ease-in-out infinite;
}
@keyframes pulse-speak {
  50% { transform: scale(1.06); }
}
.check-row:not(:disabled):active {
  transform: scale(0.98);
}
.check-row.active {
  background: rgba(255, 196, 86, 0.18);
}
.check-row.done {
  background: rgba(120, 200, 140, 0.18);
  opacity: 0.9;
}
.check-row.locked:disabled {
  cursor: default;
  opacity: 0.75;
}
.check-row:disabled:not(.locked):not(.done) {
  opacity: 0.5;
}
.check-row.done:disabled {
  cursor: wait;
  opacity: 0.9;
}
.mark {
  flex-shrink: 0;
  width: 1.4em;
  font-size: 20px;
  line-height: 1.3;
  font-weight: 700;
}
.check-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  flex: 1;
}
.check-title {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.35;
}
.check-desc {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.4;
  opacity: 0.88;
}
.check-meta {
  font-size: 13px;
  opacity: 0.8;
  line-height: 1.3;
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
.big.ghost {
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
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
