<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { GrowthDailyReport, GrowthSummaryReport, GrowthWeeklyReport, ParentChild } from '@/shared/api/types'

const { t } = useI18n()
const children = ref<ParentChild[]>([])
const childId = ref<number | undefined>()
const daily = ref<GrowthDailyReport | null>(null)
const weekly = ref<GrowthWeeklyReport | null>(null)
const growth = ref<GrowthSummaryReport | null>(null)

async function loadChildren(): Promise<void> {
  children.value = await api.get<ParentChild[]>('/parent/children')
  childId.value ??= children.value.find((c) => c.status === 'active')?.id
}

async function loadReports(): Promise<void> {
  if (!childId.value) return
  try {
    const [d, w, g] = await Promise.all([
      api.get<GrowthDailyReport>('/parent/reports/daily', { child_id: childId.value }),
      api.get<GrowthWeeklyReport>('/parent/reports/weekly', { child_id: childId.value }),
      api.get<GrowthSummaryReport>('/parent/reports/growth', { child_id: childId.value }),
    ])
    daily.value = d
    weekly.value = w
    growth.value = g
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  }
}

onMounted(async () => {
  await loadChildren()
  await loadReports()
})

watch(childId, loadReports)
</script>

<template>
  <div class="page">
    <el-select v-model="childId" :placeholder="t('common.selectChild')" style="max-width: 220px">
      <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
    </el-select>

    <el-row :gutter="12" class="report-row">
      <el-col :xs="24" :sm="12" :md="8">
        <el-card shadow="never" :header="t('common.reportToday')">
          <ul v-if="daily">
            <li>{{ t('common.tasksCompleted') }}：{{ daily.tasks_completed }}</li>
            <li>{{ t('common.habitsLogged') }}：{{ daily.habits_logged }}</li>
            <li>{{ t('common.englishMinutes') }}：{{ daily.english_minutes }}</li>
          </ul>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8">
        <el-card shadow="never" :header="t('common.reportWeek')">
          <ul v-if="weekly">
            <li>{{ t('common.tasksCompleted') }}：{{ weekly.tasks_completed }}</li>
            <li>{{ t('common.moviesCompleted') }}：{{ weekly.movies_completed }}</li>
            <li>{{ t('common.englishMinutes') }}：{{ weekly.english_minutes }}</li>
          </ul>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="24" :md="8">
        <el-card shadow="never" :header="t('common.reportTotal')">
          <ul v-if="growth">
            <li>{{ t('common.tasksTotal') }}：{{ growth.tasks_completed_total }}</li>
            <li>{{ t('common.habitsTotal') }}：{{ growth.habit_records_total }}</li>
            <li>{{ t('common.moviesTotal') }}：{{ growth.movies_completed_total }}</li>
            <li>{{ t('common.englishLogsTotal') }}：{{ growth.english_logs_approved_total }}</li>
          </ul>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped>
.report-row {
  margin-top: 12px;
}
.report-row :deep(.el-col) {
  margin-bottom: 12px;
}
.page ul { margin: 0; padding-left: 18px; line-height: 1.8; }
</style>
