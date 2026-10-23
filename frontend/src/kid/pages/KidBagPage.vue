<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type {
  ChecklistCompleteResult,
  ItemEvent,
  ItemFlowHistory,
  KidBag,
  ParentItem,
} from '@/shared/api/types'
import { itemDisplay } from '@/shared/itemDisplay'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

type ZoneKey = 'mine' | `loc:${number}`

const { t, locale } = useI18n()
const router = useRouter()
const session = useSessionStore()
const bag = ref<KidBag | null>(peekWarmGet<KidBag>('/kid/bag') ?? null)
const loading = ref(bag.value == null)
const error = ref('')
const toast = ref('')
const completeBusy = ref(false)
const reopenBusy = ref(false)
const flowOpen = ref(false)
const flowLoading = ref(false)
const flow = ref<ItemFlowHistory | null>(null)
const activeChecklistId = ref<number | null>(null)
/** Draft zone for each item id */
const draft = ref<Record<number, ZoneKey>>({})
const draggingId = ref<number | null>(null)
const selectedId = ref<number | null>(null)
const dropTarget = ref<ZoneKey | null>(null)

const items = computed(() => bag.value?.items ?? [])
const locations = computed(() => bag.value?.locations ?? [])
const checklists = computed(() => bag.value?.checklists ?? [])

const activeChecklist = computed(() =>
  checklists.value.find((c) => c.id === activeChecklistId.value) ?? null,
)

const homeBoxes = computed(() =>
  locations.value.filter((l) => l.kind === 'home').sort((a, b) => a.sort - b.sort || a.id - b.id),
)
const bagBoxes = computed(() =>
  locations.value.filter((l) => l.kind === 'bag').sort((a, b) => a.sort - b.sort || a.id - b.id),
)
const schoolBoxes = computed(() =>
  locations.value.filter((l) => l.kind === 'school').sort((a, b) => a.sort - b.sort || a.id - b.id),
)

const boardZones = computed(() => {
  const zones: { key: ZoneKey; title: string; kind: 'mine' | 'home' | 'bag' | 'school' }[] = [
    { key: 'mine', title: t('common.myItems'), kind: 'mine' },
  ]
  for (const loc of homeBoxes.value) {
    zones.push({ key: `loc:${loc.id}`, title: loc.name, kind: 'home' })
  }
  for (const loc of bagBoxes.value) {
    zones.push({ key: `loc:${loc.id}`, title: loc.name, kind: 'bag' })
  }
  for (const loc of schoolBoxes.value) {
    zones.push({ key: `loc:${loc.id}`, title: loc.name, kind: 'school' })
  }
  return zones
})

function zoneKeyForItem(item: ParentItem): ZoneKey {
  const loc = item.location ?? locations.value.find((l) => l.id === item.location_id)
  if (!loc) return 'mine'
  if (loc.kind === 'bag' || loc.kind === 'school') return `loc:${loc.id}`
  if (loc.kind === 'home' && !loc.is_system) return `loc:${loc.id}`
  // system home / desk / other → 我的物品
  return 'mine'
}

function initDraft(): void {
  const next: Record<number, ZoneKey> = {}
  for (const item of items.value) {
    next[item.id] = zoneKeyForItem(item)
  }
  draft.value = next
}

function itemsInZone(key: ZoneKey): ParentItem[] {
  return items.value.filter((item) => draft.value[item.id] === key)
}

function kindLabel(kind: string): string {
  if (kind === 'before_school') return t('common.beforeSchoolCheck')
  if (kind === 'after_school') return t('common.afterSchoolCheck')
  return kind
}

function kindHint(kind: string): string {
  if (kind === 'before_school') return t('common.beforeSchoolDragHint')
  if (kind === 'after_school') return t('common.afterSchoolDragHint')
  return ''
}

function formatTargetDate(date: string): string {
  const raw = date.slice(0, 10)
  const d = new Date(`${raw}T12:00:00`)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleDateString(locale.value === 'zh-CN' ? 'zh-CN' : 'en', {
    month: 'numeric',
    day: 'numeric',
    weekday: 'short',
  })
}

function eventLabel(type: string): string {
  const map: Record<string, string> = {
    brought_home: t('common.eventBroughtHome'),
    checked: t('common.eventChecked'),
    moved: t('common.eventMoved'),
    reported_missing: t('common.eventReportedMissing'),
    found: t('common.eventFound'),
    marked_lost: t('common.eventMarkedLost'),
    damaged: t('common.eventDamaged'),
    correction: t('common.eventCorrection'),
  }
  return map[type] ?? type
}

function formatWhen(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(locale.value === 'zh-CN' ? 'zh-CN' : 'en', {
    month: 'numeric',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function flowLine(ev: ItemEvent): string {
  const from = ev.from_location?.name
  const to = ev.to_location?.name
  if (from && to && from !== to) return `${from} → ${to}`
  if (to) return to
  if (from) return from
  return t('common.unknownLocation')
}

function resolveLocationId(zone: ZoneKey): number | null {
  if (zone === 'mine') return null
  const id = Number(zone.slice(4))
  return Number.isFinite(id) ? id : null
}

function canComplete(): boolean {
  const cl = activeChecklist.value
  if (!cl || cl.status !== 'open') return false
  const lines = cl.lines ?? []
  if (lines.length === 0) return false
  return lines.every((line) => draft.value[line.item_id] != null)
}

function onDragStart(itemId: number, e: DragEvent): void {
  if (!activeChecklist.value || activeChecklist.value.status !== 'open') {
    e.preventDefault()
    return
  }
  draggingId.value = itemId
  e.dataTransfer?.setData('text/plain', String(itemId))
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
  if (session.me?.child?.preferences.sounds) playTap()
}

function onDragEnd(): void {
  draggingId.value = null
  dropTarget.value = null
}

function onDragOver(zone: ZoneKey, e: DragEvent): void {
  if (!activeChecklist.value || activeChecklist.value.status !== 'open') return
  e.preventDefault()
  dropTarget.value = zone
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
}

function onDragLeave(zone: ZoneKey): void {
  if (dropTarget.value === zone) dropTarget.value = null
}

function moveItemToZone(itemId: number, zone: ZoneKey): void {
  if (!activeChecklist.value || activeChecklist.value.status !== 'open') return
  if (!items.value.some((i) => i.id === itemId)) return
  draft.value = { ...draft.value, [itemId]: zone }
  selectedId.value = null
  if (session.me?.child?.preferences.sounds) playTap()
}

function onDrop(zone: ZoneKey, e: DragEvent): void {
  e.preventDefault()
  const raw = e.dataTransfer?.getData('text/plain') || String(draggingId.value ?? '')
  const itemId = Number(raw)
  dropTarget.value = null
  draggingId.value = null
  if (!Number.isFinite(itemId)) return
  moveItemToZone(itemId, zone)
}

function onItemTap(item: ParentItem, e: MouseEvent): void {
  // Long-press / drag path uses native DnD; short tap selects or opens flow when locked
  if (!activeChecklist.value || activeChecklist.value.status !== 'open') {
    void openFlow(item)
    return
  }
  e.preventDefault()
  if (selectedId.value === item.id) {
    selectedId.value = null
    return
  }
  if (session.me?.child?.preferences.sounds) playTap()
  selectedId.value = item.id
}

function onZoneTap(zone: ZoneKey): void {
  if (selectedId.value == null) return
  moveItemToZone(selectedId.value, zone)
}

async function load(force = false): Promise<void> {
  if (force || bag.value == null) loading.value = true
  error.value = ''
  try {
    bag.value = await warmGet<KidBag>('/kid/bag', undefined, { force })
    const open = bag.value.checklists.find((c) => c.status === 'open')
    const prefer =
      bag.value.checklists.find((c) => c.id === activeChecklistId.value) ??
      open ??
      bag.value.checklists[0] ??
      null
    activeChecklistId.value = prefer?.id ?? null
    initDraft()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function completeChecklist(): Promise<void> {
  const cl = activeChecklist.value
  if (!cl || !canComplete() || completeBusy.value) return
  if (session.me?.child?.preferences.sounds) playTap()
  completeBusy.value = true
  error.value = ''
  try {
    const placements = items.value.map((item) => ({
      item_id: item.id,
      location_id: resolveLocationId(draft.value[item.id] ?? 'mine'),
    }))
    const result = await api.postIdempotent<ChecklistCompleteResult>(
      `/kid/item-checklists/${cl.id}/complete`,
      { placements },
    )
    toast.value = result.reward
      ? t('common.checklistRewardClaimed', { coins: result.reward.coins, xp: result.reward.xp })
      : t('common.checklistCompleted')
    setTimeout(() => (toast.value = ''), 2500)
    await load(true)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.saveFailed')
  } finally {
    completeBusy.value = false
  }
}

async function reopenChecklist(): Promise<void> {
  const cl = activeChecklist.value
  if (!cl || cl.status === 'open' || reopenBusy.value) return
  if (session.me?.child?.preferences.sounds) playTap()
  reopenBusy.value = true
  error.value = ''
  try {
    await api.postIdempotent(`/kid/item-checklists/${cl.id}/reopen`, {})
    toast.value = t('common.checklistReopened')
    setTimeout(() => (toast.value = ''), 2500)
    await load(true)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.saveFailed')
  } finally {
    reopenBusy.value = false
  }
}

async function openFlow(item: ParentItem): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  flowOpen.value = true
  flowLoading.value = true
  flow.value = null
  error.value = ''
  try {
    flow.value = await api.get<ItemFlowHistory>(`/kid/items/${item.id}/events`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
    flowOpen.value = false
  } finally {
    flowLoading.value = false
  }
}

function closeFlow(): void {
  flowOpen.value = false
  flow.value = null
}

function selectChecklist(id: number): void {
  if (session.me?.child?.preferences.sounds) playTap()
  activeChecklistId.value = id
  selectedId.value = null
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.bag') }}</h1>
      <span class="spacer" />
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <template v-if="bag && !loading">
      <div v-if="checklists.length" class="mode-row">
        <button
          v-for="cl in checklists"
          :key="cl.id"
          type="button"
          class="mode-chip"
          :class="{ on: cl.id === activeChecklistId, done: cl.status !== 'open' }"
          @click="selectChecklist(cl.id)"
        >
          {{ kindLabel(cl.kind) }}
          <span v-if="cl.status !== 'open'" class="done-dot">✓</span>
        </button>
      </div>

      <p v-if="activeChecklist" class="hint-inline">
        {{ kindHint(activeChecklist.kind) }}
        · {{ t('common.checklistTargetDate', { date: formatTargetDate(activeChecklist.target_date) }) }}
      </p>
      <p v-else-if="!items.length" class="hint-inline">{{ t('common.noItemsYet') }}</p>

      <section class="board" :class="{ locked: !activeChecklist || activeChecklist.status !== 'open' }">
        <div
          v-for="zone in boardZones"
          :key="zone.key"
          class="box"
          :class="[
            `kind-${zone.kind}`,
            {
              over: dropTarget === zone.key,
              empty: itemsInZone(zone.key).length === 0,
              targetable: selectedId != null,
            },
          ]"
          @dragover="onDragOver(zone.key, $event)"
          @dragleave="onDragLeave(zone.key)"
          @drop="onDrop(zone.key, $event)"
          @click="onZoneTap(zone.key)"
        >
          <header class="box-head">
            <strong>{{ zone.title }}</strong>
            <span class="count">{{ itemsInZone(zone.key).length }}</span>
          </header>
          <div class="box-body">
            <button
              v-for="item in itemsInZone(zone.key)"
              :key="item.id"
              type="button"
              class="chip"
              :class="{ dragging: draggingId === item.id, selected: selectedId === item.id }"
              :draggable="!!activeChecklist && activeChecklist.status === 'open'"
              @dragstart="onDragStart(item.id, $event)"
              @dragend="onDragEnd"
              @click.stop="onItemTap(item, $event)"
            >
              <template v-for="label in [itemDisplay(item.name)]" :key="item.id">
                <span class="chip-emoji" aria-hidden="true">{{ label.emoji }}</span>
                <span class="chip-zh">{{ label.zh }}</span>
                <span v-if="label.en" class="chip-en">{{ label.en }}</span>
              </template>
            </button>
            <p v-if="itemsInZone(zone.key).length === 0" class="box-empty">
              {{ selectedId != null ? t('common.tapToPlaceHere') : t('common.dropItemsHere') }}
            </p>
          </div>
        </div>
      </section>

      <p v-if="selectedId != null" class="hint-inline center pick">{{ t('common.tapBoxToPlace') }}</p>

      <div v-if="activeChecklist?.status === 'open'" class="complete-block">
        <button
          type="button"
          class="complete-btn"
          :disabled="!canComplete() || completeBusy"
          @click="completeChecklist"
        >
          {{ t('common.completeChecklist') }}
        </button>
        <p class="hint-inline center">{{ t('common.dragThenCompleteHint') }}</p>
      </div>
      <div v-else-if="activeChecklist" class="complete-block">
        <p class="done-badge">{{ t('common.checklistAlreadyDone') }}</p>
        <button
          type="button"
          class="complete-btn"
          :disabled="reopenBusy"
          @click="reopenChecklist"
        >
          {{ t('common.recheckChecklist') }}
        </button>
      </div>
    </template>

    <div v-if="flowOpen" class="sheet" role="dialog" aria-modal="true" @click.self="closeFlow">
      <div class="sheet-panel">
        <header class="sheet-head">
          <div>
            <h2>{{ flow?.item.name ?? t('common.itemFlowTitle') }}</h2>
            <p class="meta">{{ t('common.itemFlowTitle') }}</p>
          </div>
          <button type="button" class="back" @click="closeFlow">{{ t('common.close') }}</button>
        </header>
        <p v-if="flowLoading" class="hint">{{ t('common.loading') }}</p>
        <ul v-else-if="flow?.events.length" class="flow-list">
          <li v-for="ev in flow.events" :key="ev.id" class="flow-item">
            <div class="flow-top">
              <strong>{{ eventLabel(ev.event_type) }}</strong>
              <span class="when">{{ formatWhen(ev.occurred_at) }}</span>
            </div>
            <p class="meta">{{ flowLine(ev) }}</p>
            <p v-if="ev.note" class="note">{{ ev.note }}</p>
          </li>
        </ul>
        <p v-else class="hint">{{ t('common.itemFlowEmpty') }}</p>
      </div>
    </div>

    <div v-if="toast" class="toast">{{ toast }}</div>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 40px;
  max-width: 1400px;
  margin: 0 auto;
}
.head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.head h1 {
  margin: 0;
  font-size: 22px;
  flex: 1;
  text-align: center;
}
.spacer { width: 72px; }
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
}
.mode-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}
.mode-chip {
  border: 1px solid rgba(255, 255, 255, 0.25);
  background: transparent;
  color: #fff;
  padding: 8px 14px;
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.mode-chip.on {
  background: var(--gp-accent, #5b8def);
  border-color: transparent;
}
.mode-chip.done { opacity: 0.7; }
.done-dot { color: #9be7a8; }
.hint-inline {
  margin: 0 0 12px;
  font-size: 13px;
  opacity: 0.75;
}
.hint-inline.center { text-align: center; margin-top: 8px; }
.hint-inline.pick { color: #ffe08a; opacity: 1; }
.board {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  align-items: flex-start;
}
.board.locked .chip {
  cursor: pointer;
}
.box {
  width: 400px;
  height: 400px;
  max-width: 100%;
  overflow: hidden;
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.06);
  border: 3px dashed rgba(255, 255, 255, 0.22);
  display: flex;
  flex-direction: column;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.box.kind-mine { border-color: rgba(255, 210, 120, 0.45); }
.box.kind-home { border-color: rgba(120, 200, 255, 0.45); }
.box.kind-bag { border-color: rgba(160, 140, 255, 0.5); }
.box.kind-school { border-color: rgba(120, 220, 160, 0.5); }
.box.over,
.box.targetable {
  background: rgba(255, 255, 255, 0.1);
  border-style: solid;
}
.chip.selected {
  outline: 3px solid #fff;
  background: var(--gp-accent, #5b8def);
}
.box-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 22px 24px 10px;
  color: #fff;
}
.box-head strong { font-size: 22px; }
.count {
  font-size: 16px;
  opacity: 0.7;
}
.box-body {
  flex: 1;
  min-height: 0;
  padding: 12px 16px 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-content: flex-start;
  overflow: auto;
}
.box-empty {
  margin: 24px 8px;
  width: 100%;
  text-align: center;
  font-size: 18px;
  opacity: 0.45;
}
.chip {
  border: none;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  padding: 16px 18px;
  border-radius: 18px;
  min-width: 112px;
  min-height: 118px;
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: grab;
  touch-action: none;
}
.chip-emoji {
  font-size: 30px;
  line-height: 1;
}
.chip-zh {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
  text-align: center;
}
.chip-en {
  font-size: 13px;
  font-weight: 500;
  opacity: 0.78;
  line-height: 1.2;
  text-align: center;
}
.chip.dragging { opacity: 0.45; }
.complete-block {
  margin-top: 16px;
}
.complete-btn {
  width: 100%;
  border: none;
  background: var(--gp-accent, #5b8def);
  color: #fff;
  padding: 14px;
  border-radius: 14px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}
.complete-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.done-badge {
  margin: 0 0 12px;
  text-align: center;
  font-size: 14px;
  color: #9be7a8;
}
.sheet {
  position: fixed;
  inset: 0;
  background: rgba(8, 12, 28, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 40;
  padding: 16px;
}
.sheet-panel {
  width: min(560px, 100%);
  max-height: min(72vh, 640px);
  overflow: auto;
  background: #1a2440;
  color: #fff;
  border-radius: 20px 20px 16px 16px;
  padding: 16px;
}
.sheet-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}
.sheet-head h2 { margin: 0; font-size: 18px; }
.flow-list { list-style: none; margin: 0; padding: 0; }
.flow-item {
  padding: 12px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.flow-item:last-child { border-bottom: none; }
.flow-top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: baseline;
}
.when { font-size: 12px; opacity: 0.7; }
.meta { margin: 4px 0 0; font-size: 13px; opacity: 0.75; }
.note { margin: 6px 0 0; font-size: 13px; opacity: 0.85; }
.toast {
  position: fixed;
  left: 50%;
  bottom: calc(32px + env(safe-area-inset-bottom));
  transform: translateX(-50%);
  padding: 12px 20px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.95);
  color: var(--gp-space-dark);
  font-weight: 600;
  z-index: 50;
}
.err, .hint { text-align: center; opacity: 0.85; }
</style>
