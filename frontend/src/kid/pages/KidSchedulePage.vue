<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidScheduleOverview, ResolvedDaySchedule, SchoolSchedule } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const loading = ref(true)
const error = ref('')
const overview = ref<KidScheduleOverview | null>(null)
const week = ref<SchoolSchedule | null>(null)
const showWeek = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    overview.value = await api.get<KidScheduleOverview>('/kid/schedule')
    week.value = await api.get<SchoolSchedule | null>('/kid/schedule/week')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('network.error')
  } finally {
    loading.value = false
  }
}

function goHome(): void {
  if (session.me?.child?.preferences.sounds) playTap()
  void router.push({ name: 'kid-home' })
}

function toggleWeek(): void {
  if (session.me?.child?.preferences.sounds) playTap()
  showWeek.value = !showWeek.value
}

function dayTitle(day: ResolvedDaySchedule, key: 'today' | 'tomorrow'): string {
  const label = t(`schedule.${key}`)
  if (!day.is_school_day) return `${label} · ${t('schedule.noSchool')}`
  return `${label} · ${day.date}`
}

const weekdays = [1, 2, 3, 4, 5, 6, 7] as const
const periodCount = computed(() => week.value?.period_count ?? 6)
const periodIndexes = computed(() => Array.from({ length: periodCount.value }, (_, i) => i + 1))

function cell(wd: number, p: number): string {
  return week.value?.periods.find((x) => x.weekday === wd && x.period_index === p)?.subject ?? ''
}

onMounted(() => {
  void load()
})
</script>

<template>
  <main class="page">
    <header class="top">
      <button type="button" class="back" @click="goHome">←</button>
      <h1>{{ t('schedule.kidTitle') }}</h1>
      <button type="button" class="link" @click="toggleWeek">
        {{ showWeek ? t('schedule.hideWeek') : t('schedule.showWeek') }}
      </button>
    </header>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

    <template v-if="overview && !loading">
      <section class="card">
        <h2>{{ dayTitle(overview.today, 'today') }}</h2>
        <p v-if="!overview.today.is_school_day" class="muted">{{ t('schedule.restDay') }}</p>
        <ol v-else class="periods">
          <li v-for="p in overview.today.periods" :key="p.period_index">
            <span class="idx">{{ p.period_index }}</span>
            <span class="subj">{{ p.subject }}</span>
          </li>
        </ol>
      </section>
      <section class="card">
        <h2>{{ dayTitle(overview.tomorrow, 'tomorrow') }}</h2>
        <p v-if="!overview.tomorrow.is_school_day" class="muted">{{ t('schedule.restDay') }}</p>
        <ol v-else class="periods">
          <li v-for="p in overview.tomorrow.periods" :key="p.period_index">
            <span class="idx">{{ p.period_index }}</span>
            <span class="subj">{{ p.subject }}</span>
          </li>
        </ol>
      </section>

      <section v-if="showWeek" class="card week">
        <h2>{{ t('schedule.weekView') }}</h2>
        <p v-if="!week" class="muted">{{ t('schedule.noWeekTable') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th v-for="wd in weekdays" :key="wd">{{ t(`schedule.weekdayShort.${wd}`) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in periodIndexes" :key="p">
                <th>{{ p }}</th>
                <td v-for="wd in weekdays" :key="`${wd}-${p}`">{{ cell(wd, p) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </main>
</template>

<style scoped>
.page {
  min-height: 100%;
  padding: 16px 16px 48px;
  color: #fff;
}
.top {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}
.top h1 {
  flex: 1;
  margin: 0;
  font-size: 1.35rem;
}
.back,
.link {
  border: none;
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  border-radius: 999px;
  padding: 8px 14px;
  font-size: 15px;
  cursor: pointer;
  touch-action: manipulation;
}
.card {
  background: rgba(255, 255, 255, 0.12);
  border-radius: 20px;
  padding: 16px;
  margin-bottom: 14px;
}
.card h2 {
  margin: 0 0 10px;
  font-size: 1.1rem;
}
.periods {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}
.periods li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(0, 0, 0, 0.15);
  font-size: 18px;
}
.idx {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(255, 255, 255, 0.2);
  font-size: 14px;
  font-weight: 700;
}
.muted {
  opacity: 0.75;
}
.error {
  color: #ffb4b4;
}
.table-wrap {
  overflow-x: auto;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  min-width: 480px;
}
th,
td {
  border: 1px solid rgba(255, 255, 255, 0.2);
  padding: 6px 4px;
  text-align: center;
}
thead th {
  background: rgba(0, 0, 0, 0.2);
}
</style>
