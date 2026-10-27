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
    <el-select v-model="childId" class="child-select" :placeholder="t('common.selectChild')">
      <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
    </el-select>

    <el-row :gutter="16" class="report-row">
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card shadow="never" class="report-card" :header="t('common.reportToday')">
          <ul v-if="daily">
            <li>{{ t('common.tasksCompleted') }}：{{ daily.tasks_completed }}</li>
            <li>{{ t('common.habitsLogged') }}：{{ daily.habits_logged }}</li>
            <li>{{ t('common.englishMinutes') }}：{{ daily.english_minutes }}</li>
          </ul>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card shadow="never" class="report-card" :header="t('common.reportWeek')">
          <ul v-if="weekly">
            <li>{{ t('common.tasksCompleted') }}：{{ weekly.tasks_completed }}</li>
            <li>{{ t('common.moviesCompleted') }}：{{ weekly.movies_completed }}</li>
            <li>{{ t('common.englishMinutes') }}：{{ weekly.english_minutes }}</li>
          </ul>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="24" :lg="8">
        <el-card shadow="never" class="report-card" :header="t('common.reportTotal')">
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
.page {
  width: 100%;
}
.child-select {
  width: min(100%, 280px);
}
.report-row {
  margin-top: 16px;
}
.report-row :deep(.el-col) {
  margin-bottom: 16px;
}
.report-card {
  border-radius: 16px;
  border: 1px solid #e8ebf3;
  min-height: 160px;
}
.page ul {
  margin: 0;
  padding-left: 18px;
  line-height: 1.8;
}
</style>
