<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type { TaskInstance } from '@/shared/api/types'
import KidTaskGroupCard from '@/kid/components/KidTaskGroupCard.vue'
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

interface TaskGroup {
  key: string
  title: string
  isGroup: boolean
  items: TaskInstance[]
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

/** Whole card is settled when every instance is submitted/completed. */
function isGroupDone(group: TaskGroup): boolean {
  return group.items.length > 0 && group.items.every((inst) => isDoneStatus(inst.status))
}

const activeGroups = computed(() => groups.value.filter((g) => !isGroupDone(g)))
const doneGroups = computed(() => groups.value.filter((g) => isGroupDone(g)))
const doneItemCount = computed(() => doneGroups.value.reduce((n, g) => n + g.items.length, 0))

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

    <div v-if="instances.length" class="task-board">
      <TransitionGroup name="task-fly" tag="div" class="active-stack">
        <KidTaskGroupCard
          v-for="group in activeGroups"
          :key="group.key"
          :group="group"
          :busy="busy"
          :speaking-key="speakingKey"
          :speech-supported="speechSupported"
          :title-of="titleOf"
          :description-of="descriptionOf"
          :due-of="dueOf"
          :reward-of="rewardOf"
          :steps-of="stepsOf"
          :status-label="statusLabel"
          :subtask-action-label="subtaskActionLabel"
          :is-done-status="isDoneStatus"
          :can-act-on="canActOn"
          :can-withdraw="canWithdraw"
          :all-required-steps-checked="allRequiredStepsChecked"
          @subtask-click="onSubtaskClick"
          @read-aloud="readAloud"
          @toggle-step="toggleStep"
          @start-card="startCard"
          @submit-card="submitCard"
          @withdraw="withdrawSubtask"
        />
      </TransitionGroup>

      <section
        class="done-dock"
        :class="{ 'is-empty': doneGroups.length === 0 }"
        :aria-label="t('kid.tasksDone')"
      >
        <header class="done-dock-head">
          <h2>{{ t('kid.tasksDone') }}</h2>
          <span v-if="doneItemCount" class="done-count">{{ t('kid.tasksDoneCount', { n: doneItemCount }) }}</span>
        </header>
        <p v-if="doneGroups.length === 0" class="done-hint">{{ t('kid.tasksDoneHint') }}</p>
        <TransitionGroup name="shrink-in" tag="div" class="done-stack">
          <KidTaskGroupCard
            v-for="group in doneGroups"
            :key="group.key"
            compact
            :group="group"
            :busy="busy"
            :speaking-key="speakingKey"
            :speech-supported="speechSupported"
            :title-of="titleOf"
            :description-of="descriptionOf"
            :due-of="dueOf"
            :reward-of="rewardOf"
            :steps-of="stepsOf"
            :status-label="statusLabel"
            :subtask-action-label="subtaskActionLabel"
            :is-done-status="isDoneStatus"
            :can-act-on="canActOn"
            :can-withdraw="canWithdraw"
            :all-required-steps-checked="allRequiredStepsChecked"
            @subtask-click="onSubtaskClick"
            @read-aloud="readAloud"
            @toggle-step="toggleStep"
            @start-card="startCard"
            @submit-card="submitCard"
            @withdraw="withdrawSubtask"
          />
        </TransitionGroup>
      </section>
    </div>
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
.task-board {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.active-stack {
  position: relative;
  display: flex;
  flex-direction: column;
}
/* Exit faster than enter; transform + opacity only (ui-ux skill). */
.task-fly-move {
  transition: transform 0.25s ease;
}
.task-fly-leave-active {
  position: absolute;
  left: 0;
  right: 0;
  z-index: 1;
  pointer-events: none;
  transition: transform 0.2s ease-in, opacity 0.2s ease-in;
  transform-origin: center bottom;
}
.task-fly-leave-to {
  transform: scale(0.42) translateY(28px);
  opacity: 0;
}
.done-dock {
  padding: 14px;
  border-radius: 20px;
  border: 2px dashed rgba(255, 255, 255, 0.28);
  background: rgba(0, 0, 0, 0.16);
  min-height: 88px;
  transition: border-color 0.25s ease, background 0.25s ease;
}
.done-dock:not(.is-empty) {
  border-style: solid;
  border-color: rgba(120, 200, 140, 0.4);
  background: rgba(120, 200, 140, 0.1);
}
.done-dock-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
}
.done-dock-head h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.02em;
  opacity: 0.92;
}
.done-count {
  flex-shrink: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(120, 200, 140, 0.28);
  color: #d8ffe4;
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
}
.done-hint {
  margin: 0;
  text-align: center;
  font-size: 14px;
  opacity: 0.65;
  padding: 12px 8px 6px;
}
.done-stack {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.shrink-in-enter-active {
  transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease-out;
  transform-origin: center top;
}
.shrink-in-enter-from {
  transform: scale(0.55);
  opacity: 0;
}
.shrink-in-leave-active {
  transition: transform 0.18s ease-in, opacity 0.18s ease-in;
  transform-origin: center top;
}
.shrink-in-leave-to {
  transform: scale(0.7);
  opacity: 0;
}
.shrink-in-move {
  transition: transform 0.25s ease;
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
