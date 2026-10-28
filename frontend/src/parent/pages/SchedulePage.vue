<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type {
  BagExtraRequirement,
  HomeworkSubject,
  ParentChild,
  ParentItem,
  ScheduleException,
  ScheduleExceptionType,
  SchoolPeriodCell,
  SchoolSchedule,
  SubjectItemRequirement,
} from '@/shared/api/types'
import { useMediaQuery } from '@/shared/useMediaQuery'

const SUBJECTS: HomeworkSubject[] = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他']
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const

const { t } = useI18n()
const useCompactGrid = useMediaQuery('(max-width: 1199px)')
const tab = ref<'week' | 'exceptions' | 'subjects' | 'extras'>('week')
const children = ref<ParentChild[]>([])
const childId = ref<number | undefined>()
const loading = ref(true)
const saving = ref(false)

const schedule = ref<SchoolSchedule | null>(null)
const periodCount = ref(6)
const title = ref('本学期课表')
/** grid[weekday][periodIndex] = subject */
const grid = ref<Record<number, Record<number, string>>>({})

const exceptions = ref<ScheduleException[]>([])
const subjectReqs = ref<SubjectItemRequirement[]>([])
const extras = ref<BagExtraRequirement[]>([])
const items = ref<ParentItem[]>([])

const exceptionDialog = ref(false)
const exceptionForm = ref({
  exception_date: '',
  type: 'no_school' as ScheduleExceptionType,
  follow_weekday: 5,
  note: '',
  custom_subjects: '',
  family_wide: false,
})

const subjectDialog = ref(false)
const subjectForm = ref({ subject: '数学' as string, item_id: undefined as number | undefined })

const extraDialog = ref(false)
const extraForm = ref({
  target_date: '',
  item_id: undefined as number | undefined,
  label: '',
})

const weekdayLabel = (d: number): string => t(`schedule.weekday.${d}`)

function emptyGrid(count: number): Record<number, Record<number, string>> {
  const g: Record<number, Record<number, string>> = {}
  for (const wd of WEEKDAYS) {
    g[wd] = {}
    for (let p = 1; p <= count; p++) g[wd][p] = ''
  }
  return g
}

function applySchedule(s: SchoolSchedule | null): void {
  schedule.value = s
  periodCount.value = s?.period_count ?? 6
  title.value = s?.title ?? '本学期课表'
  const g = emptyGrid(periodCount.value)
  for (const p of s?.periods ?? []) {
    const row = g[p.weekday] ?? (g[p.weekday] = {})
    row[p.period_index] = p.subject
  }
  grid.value = g
}

async function loadChildren(): Promise<void> {
  children.value = await api.get<ParentChild[]>('/parent/children')
  if (!childId.value) {
    childId.value = children.value.find((c) => c.status === 'active')?.id
  }
}

async function loadSchedule(): Promise<void> {
  if (!childId.value) return
  const s = await api.get<SchoolSchedule | null>(`/parent/children/${childId.value}/school-schedule`)
  applySchedule(s)
}

async function loadExceptions(): Promise<void> {
  if (!childId.value) return
  exceptions.value = await api.get<ScheduleException[]>('/parent/schedule-exceptions', {
    child_id: childId.value,
  })
}

async function loadSubjectReqs(): Promise<void> {
  if (!childId.value) return
  subjectReqs.value = await api.get<SubjectItemRequirement[]>('/parent/subject-item-requirements', {
    child_id: childId.value,
  })
}

async function loadExtras(): Promise<void> {
  if (!childId.value) return
  extras.value = await api.get<BagExtraRequirement[]>('/parent/bag-extra-requirements', {
    child_id: childId.value,
  })
}

async function loadItems(): Promise<void> {
  if (!childId.value) return
  items.value = await api.get<ParentItem[]>('/parent/items', { child_id: childId.value })
}

async function loadAll(): Promise<void> {
  loading.value = true
  try {
    await loadChildren()
    if (!childId.value) return
    await Promise.all([loadSchedule(), loadExceptions(), loadSubjectReqs(), loadExtras(), loadItems()])
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError) ElMessage.error(Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
}

watch(childId, () => {
  void loadAll()
})

watch(periodCount, (n) => {
  const g = emptyGrid(n)
  for (const wd of WEEKDAYS) {
    const row = g[wd] ?? (g[wd] = {})
    for (let p = 1; p <= n; p++) {
      row[p] = grid.value[wd]?.[p] ?? ''
    }
  }
  grid.value = g
})

const periodIndexes = computed(() => Array.from({ length: periodCount.value }, (_, i) => i + 1))

async function saveWeek(): Promise<void> {
  if (!childId.value) return
  saving.value = true
  try {
    const periods: SchoolPeriodCell[] = []
    for (const wd of WEEKDAYS) {
      for (const p of periodIndexes.value) {
        const subject = (grid.value[wd]?.[p] ?? '').trim()
        if (subject) {
          periods.push({ weekday: wd, period_index: p, subject })
        }
      }
    }
    const saved = await api.put<SchoolSchedule>(`/parent/children/${childId.value}/school-schedule`, {
      title: title.value,
      period_count: periodCount.value,
      effective_from: schedule.value?.effective_from ?? new Date().toISOString().slice(0, 10),
      periods,
    })
    applySchedule(saved)
    ElMessage.success(t('schedule.saved'))
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

async function createException(): Promise<void> {
  if (!childId.value || !exceptionForm.value.exception_date) return
  try {
    const body: Record<string, unknown> = {
      child_id: exceptionForm.value.family_wide ? null : childId.value,
      exception_date: exceptionForm.value.exception_date,
      type: exceptionForm.value.type,
      note: exceptionForm.value.note || null,
    }
    if (exceptionForm.value.type === 'makeup_day') {
      body.follow_weekday = exceptionForm.value.follow_weekday
    }
    if (exceptionForm.value.type === 'custom') {
      body.custom_periods = exceptionForm.value.custom_subjects
        .split(/[,，、\s]+/)
        .map((s) => s.trim())
        .filter(Boolean)
        .map((subject, i) => ({ period_index: i + 1, subject }))
    }
    await api.post('/parent/schedule-exceptions', body)
    exceptionDialog.value = false
    exceptionForm.value = {
      exception_date: '',
      type: 'no_school',
      follow_weekday: 5,
      note: '',
      custom_subjects: '',
      family_wide: false,
    }
    await loadExceptions()
    ElMessage.success(t('common.saved'))
  } catch (e) {
    reportError(e)
  }
}

async function removeException(id: number): Promise<void> {
  try {
    await api.delete(`/parent/schedule-exceptions/${id}`)
    await loadExceptions()
  } catch (e) {
    reportError(e)
  }
}

async function createSubjectReq(): Promise<void> {
  if (!childId.value || !subjectForm.value.item_id) return
  try {
    await api.post('/parent/subject-item-requirements', {
      child_id: childId.value,
      subject: subjectForm.value.subject,
      item_id: subjectForm.value.item_id,
    })
    subjectDialog.value = false
    subjectForm.value = { subject: '数学', item_id: undefined }
    await loadSubjectReqs()
  } catch (e) {
    reportError(e)
  }
}

async function removeSubjectReq(id: number): Promise<void> {
  try {
    await api.delete(`/parent/subject-item-requirements/${id}`)
    await loadSubjectReqs()
  } catch (e) {
    reportError(e)
  }
}

async function createExtra(): Promise<void> {
  if (!childId.value || !extraForm.value.target_date) return
  try {
    await api.post('/parent/bag-extra-requirements', {
      child_id: childId.value,
      target_date: extraForm.value.target_date,
      item_id: extraForm.value.item_id ?? null,
      label: extraForm.value.label || null,
    })
    extraDialog.value = false
    extraForm.value = { target_date: '', item_id: undefined, label: '' }
    await loadExtras()
  } catch (e) {
    reportError(e)
  }
}

async function removeExtra(id: number): Promise<void> {
  try {
    await api.delete(`/parent/bag-extra-requirements/${id}`)
    await loadExtras()
  } catch (e) {
    reportError(e)
  }
}

onMounted(() => {
  void loadAll()
})
</script>

<template>
  <div class="page" v-loading="loading">
    <header class="head">
      <h1>{{ t('schedule.title') }}</h1>
      <el-select v-model="childId" :placeholder="t('common.selectChild')" style="width: 160px">
        <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
      </el-select>
    </header>

    <el-tabs v-model="tab">
      <el-tab-pane :label="t('schedule.tabWeek')" name="week">
        <div class="toolbar">
          <el-input v-model="title" :placeholder="t('schedule.scheduleTitle')" style="max-width: 220px" />
          <el-input-number v-model="periodCount" :min="1" :max="12" />
          <span class="hint">{{ t('schedule.periodCount') }}</span>
          <el-button type="primary" :loading="saving" @click="saveWeek">{{ t('common.save') }}</el-button>
        </div>
        <div v-if="useCompactGrid" class="day-grid">
          <section v-for="wd in WEEKDAYS" :key="wd" class="day-card">
            <h2>{{ weekdayLabel(wd) }}</h2>
            <div v-for="p in periodIndexes" :key="p" class="day-period">
              <label :for="`schedule-${wd}-${p}`">{{ t('schedule.period') }} {{ p }}</label>
              <el-select
                :id="`schedule-${wd}-${p}`"
                :model-value="grid[wd]?.[p] ?? ''"
                clearable filterable allow-create default-first-option
                @update:model-value="(v: string) => { if (!grid[wd]) grid[wd] = {}; grid[wd]![p] = v ?? '' }"
              >
                <el-option v-for="s in SUBJECTS" :key="s" :label="s" :value="s" />
              </el-select>
            </div>
          </section>
        </div>
        <div v-else class="grid-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>{{ t('schedule.period') }}</th>
                <th v-for="wd in WEEKDAYS" :key="wd">{{ weekdayLabel(wd) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in periodIndexes" :key="p">
                <th>{{ p }}</th>
                <td v-for="wd in WEEKDAYS" :key="`${wd}-${p}`">
                  <el-select
                    :model-value="grid[wd]?.[p] ?? ''"
                    clearable
                    filterable
                    allow-create
                    default-first-option
                    size="small"
                    style="width: 100%"
                    @update:model-value="(v: string) => { if (!grid[wd]) grid[wd] = {}; grid[wd]![p] = v ?? '' }"
                  >
                    <el-option v-for="s in SUBJECTS" :key="s" :label="s" :value="s" />
                  </el-select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('schedule.tabExceptions')" name="exceptions">
        <el-button type="primary" @click="exceptionDialog = true">{{ t('schedule.addException') }}</el-button>
        <el-table :data="exceptions" style="margin-top: 12px" empty-text="-">
          <el-table-column prop="exception_date" :label="t('schedule.date')" width="120" />
          <el-table-column :label="t('schedule.exceptionType')" width="120">
            <template #default="{ row }">{{ t(`schedule.exception.${row.type}`) }}</template>
          </el-table-column>
          <el-table-column :label="t('schedule.scope')" width="100">
            <template #default="{ row }">
              {{ row.child_id ? t('schedule.childOnly') : t('schedule.familyWide') }}
            </template>
          </el-table-column>
          <el-table-column prop="note" :label="t('common.note')" />
          <el-table-column width="90">
            <template #default="{ row }">
              <el-button link type="danger" @click="removeException(row.id)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('schedule.tabSubjects')" name="subjects">
        <el-button type="primary" @click="subjectDialog = true">{{ t('schedule.addSubjectItem') }}</el-button>
        <el-table :data="subjectReqs" style="margin-top: 12px" empty-text="-">
          <el-table-column prop="subject" :label="t('schedule.subject')" width="120" />
          <el-table-column :label="t('schedule.item')">
            <template #default="{ row }">{{ row.item?.name ?? row.item_id }}</template>
          </el-table-column>
          <el-table-column width="90">
            <template #default="{ row }">
              <el-button link type="danger" @click="removeSubjectReq(row.id)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('schedule.tabExtras')" name="extras">
        <el-button type="primary" @click="extraDialog = true">{{ t('schedule.addExtra') }}</el-button>
        <el-table :data="extras" style="margin-top: 12px" empty-text="-">
          <el-table-column prop="target_date" :label="t('schedule.date')" width="120" />
          <el-table-column :label="t('schedule.item')">
            <template #default="{ row }">{{ row.item?.name ?? row.label ?? '—' }}</template>
          </el-table-column>
          <el-table-column prop="note" :label="t('common.note')" />
          <el-table-column width="90">
            <template #default="{ row }">
              <el-button link type="danger" @click="removeExtra(row.id)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <el-dialog v-model="exceptionDialog" :title="t('schedule.addException')" width="420px">
      <el-form label-position="top">
        <el-form-item :label="t('schedule.date')">
          <el-date-picker v-model="exceptionForm.exception_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('schedule.exceptionType')">
          <el-select v-model="exceptionForm.type" style="width: 100%">
            <el-option :label="t('schedule.exception.no_school')" value="no_school" />
            <el-option :label="t('schedule.exception.makeup_day')" value="makeup_day" />
            <el-option :label="t('schedule.exception.custom')" value="custom" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="exceptionForm.type === 'makeup_day'" :label="t('schedule.followWeekday')">
          <el-select v-model="exceptionForm.follow_weekday" style="width: 100%">
            <el-option v-for="wd in WEEKDAYS" :key="wd" :label="weekdayLabel(wd)" :value="wd" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="exceptionForm.type === 'custom'" :label="t('schedule.customSubjects')">
          <el-input v-model="exceptionForm.custom_subjects" :placeholder="t('schedule.customSubjectsHint')" />
        </el-form-item>
        <el-form-item :label="t('common.note')">
          <el-input v-model="exceptionForm.note" />
        </el-form-item>
        <el-form-item>
          <el-checkbox v-model="exceptionForm.family_wide">{{ t('schedule.familyWide') }}</el-checkbox>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="exceptionDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createException">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="subjectDialog" :title="t('schedule.addSubjectItem')" width="400px">
      <el-form label-position="top">
        <el-form-item :label="t('schedule.subject')">
          <el-select v-model="subjectForm.subject" filterable allow-create style="width: 100%">
            <el-option v-for="s in SUBJECTS" :key="s" :label="s" :value="s" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('schedule.item')">
          <el-select v-model="subjectForm.item_id" filterable style="width: 100%">
            <el-option v-for="it in items" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="subjectDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createSubjectReq">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="extraDialog" :title="t('schedule.addExtra')" width="400px">
      <el-form label-position="top">
        <el-form-item :label="t('schedule.date')">
          <el-date-picker v-model="extraForm.target_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('schedule.item')">
          <el-select v-model="extraForm.item_id" clearable filterable style="width: 100%">
            <el-option v-for="it in items" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('schedule.label')">
          <el-input v-model="extraForm.label" :placeholder="t('schedule.labelHint')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="extraDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createExtra">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page {
  padding: 8px 4px 32px;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.head h1 {
  margin: 0;
  font-size: 1.4rem;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.hint {
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.grid-wrap {
  overflow-x: auto;
}
.grid {
  width: 100%;
  border-collapse: collapse;
  min-width: 720px;
}
.grid th,
.grid td {
  border: 1px solid var(--el-border-color);
  padding: 4px;
  text-align: center;
}
.grid thead th {
  background: var(--el-fill-color-light);
  font-weight: 600;
  white-space: nowrap;
}
.grid tbody th {
  width: 48px;
  background: var(--el-fill-color-lighter);
}
.day-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 12px;
}
.day-card {
  min-width: 0;
  padding: 14px;
  border: 1px solid var(--el-border-color);
  border-radius: 14px;
  background: var(--el-bg-color);
}
.day-card h2 { margin: 0 0 10px; font-size: 16px; }
.day-period {
  display: grid;
  grid-template-columns: minmax(70px, auto) minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}
.day-period .el-select { width: 100%; min-width: 0; }
@media (max-width: 767px) {
  .toolbar > * { max-width: 100%; }
  .toolbar .el-input { flex: 1 1 180px; }
}
</style>
