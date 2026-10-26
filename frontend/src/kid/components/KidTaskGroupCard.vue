<script setup lang="ts">
import type { TaskInstance } from '@/shared/api/types'

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

defineProps<{
  group: TaskGroup
  compact?: boolean
  busy: string | null
  speakingKey: string | null
  speechSupported: boolean
  titleOf: (inst: TaskInstance) => string
  descriptionOf: (inst: TaskInstance) => string
  dueOf: (inst: TaskInstance) => string
  rewardOf: (inst: TaskInstance) => string | null
  stepsOf: (inst: TaskInstance) => StepLine[]
  statusLabel: (status: string) => string
  subtaskActionLabel: (inst: TaskInstance) => string
  isDoneStatus: (status: string) => boolean
  canActOn: (inst: TaskInstance) => boolean
  canWithdraw: (inst: TaskInstance) => boolean
  allRequiredStepsChecked: (inst: TaskInstance) => boolean
}>()

const emit = defineEmits<{
  subtaskClick: [inst: TaskInstance]
  readAloud: [key: string, title: string, description?: string]
  toggleStep: [inst: TaskInstance, step: StepLine]
  startCard: [inst: TaskInstance]
  submitCard: [inst: TaskInstance]
  withdraw: [inst: TaskInstance]
}>()
</script>

<template>
  <article class="card" :class="{ 'card--done': compact }">
    <!-- Grouped subtasks: one card, each row clickable -->
    <template v-if="group.isGroup">
      <header class="group-head">
        <h2>{{ group.title }}</h2>
        <p class="status">{{ $t('common.subtaskCount', { n: group.items.length }) }}</p>
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
            @click="emit('subtaskClick', inst)"
          >
            <span class="mark" aria-hidden="true">{{ isDoneStatus(inst.status) ? '✓' : inst.status === 'in_progress' ? '●' : '○' }}</span>
            <span class="check-body">
              <span class="check-title">
                <template v-if="dueOf(inst)">{{ dueOf(inst) }} </template>{{ titleOf(inst) }}
              </span>
              <span v-if="descriptionOf(inst) && !compact" class="check-desc">{{ descriptionOf(inst) }}</span>
              <span class="check-meta">
                <template v-if="inst.snapshot?.with_parent">{{ $t('common.withParentTag') }} · </template>
                {{ subtaskActionLabel(inst) }}
                <template v-if="rewardOf(inst) && !compact"> · {{ rewardOf(inst) }}</template>
              </span>
            </span>
          </button>
          <button
            v-if="speechSupported && !compact"
            type="button"
            class="speak-btn"
            :class="{ speaking: speakingKey === `i-${inst.id}` }"
            :aria-label="speakingKey === `i-${inst.id}` ? $t('kid.taskActions.stopAloud') : $t('kid.taskActions.readAloud')"
            @click.stop="emit('readAloud', `i-${inst.id}`, titleOf(inst), descriptionOf(inst))"
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
            v-if="speechSupported && !compact"
            type="button"
            class="speak-btn"
            :class="{ speaking: speakingKey === `card-${inst.id}` }"
            :aria-label="speakingKey === `card-${inst.id}` ? $t('kid.taskActions.stopAloud') : $t('kid.taskActions.readAloud')"
            @click.stop="emit('readAloud', `card-${inst.id}`, titleOf(inst), descriptionOf(inst))"
          >
            {{ speakingKey === `card-${inst.id}` ? '⏹' : '🔊' }}
          </button>
        </div>
        <p v-if="descriptionOf(inst) && !compact" class="card-desc">{{ descriptionOf(inst) }}</p>
        <p class="status">{{ statusLabel(inst.status) }}</p>
        <p v-if="dueOf(inst) && !compact" class="status">{{ dueOf(inst) }}</p>
        <p v-if="inst.snapshot?.with_parent && !compact" class="with-parent">{{ $t('common.withParentTag') }}</p>
        <p v-if="rewardOf(inst) && !compact" class="reward">{{ rewardOf(inst) }}</p>

        <ul v-if="stepsOf(inst).length && !compact" class="checklist">
          <li v-for="step in stepsOf(inst)" :key="step.key" class="check-item">
            <button
              type="button"
              class="check-row"
              :class="{ done: step.checked, locked: !canActOn(inst) || !step.id }"
              :disabled="!step.id || busy === `s-${step.id}` || !canActOn(inst)"
              :aria-label="step.title"
              @click="emit('toggleStep', inst, step)"
            >
              <span class="mark" aria-hidden="true">{{ step.checked ? '✓' : '○' }}</span>
              <span class="check-body">
                <span class="check-title">{{ step.title }}</span>
                <span v-if="step.checked && canActOn(inst)" class="check-meta">{{ $t('kid.taskActions.tapToUndo') }}</span>
              </span>
            </button>
            <button
              v-if="speechSupported"
              type="button"
              class="speak-btn"
              :class="{ speaking: speakingKey === step.key }"
              :aria-label="speakingKey === step.key ? $t('kid.taskActions.stopAloud') : $t('kid.taskActions.readAloud')"
              @click.stop="emit('readAloud', step.key, step.title)"
            >
              {{ speakingKey === step.key ? '⏹' : '🔊' }}
            </button>
          </li>
        </ul>

        <div class="actions">
          <button
            v-if="!compact && (inst.status === 'pending' || inst.status === 'returned')"
            type="button"
            class="big primary"
            :disabled="busy === `i-${inst.id}`"
            @click="emit('startCard', inst)"
          >
            {{ inst.status === 'returned' ? $t('kid.taskActions.restart') : $t('kid.taskActions.start') }}
          </button>
          <button
            v-if="!compact && inst.status === 'in_progress'"
            type="button"
            class="big sun"
            :disabled="busy === `i-${inst.id}` || !allRequiredStepsChecked(inst)"
            @click="emit('submitCard', inst)"
          >
            {{ $t('kid.taskActions.done') }}
          </button>
          <button
            v-if="canWithdraw(inst)"
            type="button"
            class="big ghost"
            :class="{ compact }"
            :disabled="busy === `i-${inst.id}`"
            @click="emit('withdraw', inst)"
          >
            {{ $t('kid.taskActions.tapToUndo') }}
          </button>
        </div>
      </div>
    </template>
  </article>
</template>

<style scoped>
.card {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  padding: 20px;
  margin-bottom: 14px;
  transform-origin: center bottom;
}
.card h2 {
  margin: 0 0 8px;
  font-size: 20px;
}
.card--done {
  padding: 12px 14px;
  margin-bottom: 0;
  background: rgba(120, 200, 140, 0.14);
  border: 1px solid rgba(120, 200, 140, 0.28);
}
.card--done h2 {
  margin: 0 0 4px;
  font-size: 16px;
}
.card--done .status {
  margin-bottom: 0;
  font-size: 13px;
}
.card--done .group-head {
  margin-bottom: 8px;
}
.card--done .checklist {
  gap: 6px;
  margin-bottom: 0;
}
.card--done .check-row {
  padding: 10px 12px;
  min-height: 44px;
  background: rgba(255, 255, 255, 0.06);
}
.card--done .check-title {
  font-size: 15px;
}
.card--done .mark {
  font-size: 18px;
}
.card--done .actions {
  margin-top: 8px;
}
.card--done .big {
  min-height: 44px;
  font-size: 15px;
  border-radius: 12px;
}
.card--done .big.compact {
  min-height: 44px;
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
</style>
