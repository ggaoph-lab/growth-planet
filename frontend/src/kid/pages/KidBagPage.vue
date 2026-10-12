<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { ItemChecklist, KidBag } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const bag = ref<KidBag | null>(null)
const loading = ref(true)
const error = ref('')
const busy = ref<number | null>(null)
const toast = ref('')

const resultOptions = computed(() => [
  { value: 'present', label: t('common.present') },
  { value: 'not_found', label: t('common.notFound') },
  { value: 'left_at_school', label: t('common.leftAtSchool') },
])

function kindLabel(kind: string): string {
  if (kind === 'before_school') return t('common.beforeSchoolCheck')
  if (kind === 'after_school') return t('common.afterSchoolCheck')
  return kind
}

const checklists = computed(() => bag.value?.checklists ?? [])

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    bag.value = await api.get<KidBag>('/kid/bag')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function setLine(cl: ItemChecklist, lineId: number, result: string): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = lineId
  try {
    await api.postIdempotent(`/kid/item-checklists/${cl.id}/lines/${lineId}`, { result })
    toast.value = t('common.recorded')
    setTimeout(() => (toast.value = ''), 1800)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.saveFailed')
  } finally {
    busy.value = null
  }
}

async function reportMissing(itemId: number): Promise<void> {
  try {
    await api.postIdempotent(`/kid/items/${itemId}/report-missing`)
    await load()
    toast.value = t('common.reportedMissing')
    setTimeout(() => (toast.value = ''), 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.operationFailed')
  }
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

    <section v-if="bag && !loading">
      <h2 class="section">{{ t('common.myItems') }}</h2>
      <article v-for="item in bag.items" :key="item.id" class="card">
        <div class="row">
          <div>
            <strong>{{ item.name }}</strong>
            <p class="meta">{{ item.location?.name ?? t('common.unknownLocation') }} · {{ item.status }}</p>
          </div>
          <button v-if="item.status === 'normal'" type="button" class="mini" @click="reportMissing(item.id)">{{ t('common.notFound') }}</button>
        </div>
      </article>

      <template v-for="cl in checklists" :key="cl.id">
        <h2 class="section">{{ kindLabel(cl.kind) }}</h2>
        <article class="card">
          <div v-for="line in cl.lines" :key="line.id" class="line">
            <span>{{ line.item?.name ?? t('common.item') }}</span>
            <div class="btns">
              <button
                v-for="opt in resultOptions"
                :key="opt.value"
                type="button"
                class="chip"
                :class="{ on: line.result === opt.value }"
                :disabled="busy === line.id"
                @click="setLine(cl, line.id, opt.value)"
              >
                {{ opt.label }}
              </button>
            </div>
          </div>
        </article>
      </template>
    </section>

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
  gap: 8px;
  margin-bottom: 16px;
}
.head h1 {
  margin: 0;
  font-size: 22px;
  flex: 1;
  text-align: center;
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
.section {
  font-size: 16px;
  margin: 20px 0 8px;
  opacity: 0.9;
}
.card {
  background: rgba(255, 255, 255, 0.08);
  border-radius: 16px;
  padding: 14px;
  margin-bottom: 12px;
}
.row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.meta {
  margin: 4px 0 0;
  font-size: 13px;
  opacity: 0.75;
}
.mini {
  border: none;
  background: rgba(255, 200, 100, 0.25);
  color: #fff;
  padding: 8px 12px;
  border-radius: 999px;
  font-size: 12px;
  cursor: pointer;
}
.line {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.line:last-child {
  border-bottom: none;
}
.btns {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chip {
  border: 1px solid rgba(255, 255, 255, 0.25);
  background: transparent;
  color: #fff;
  padding: 8px 12px;
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
}
.chip.on {
  background: var(--gp-accent, #5b8def);
  border-color: transparent;
}
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
}
.err,
.hint {
  text-align: center;
  opacity: 0.85;
}
</style>
