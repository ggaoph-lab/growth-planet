<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type { ApprovalRequest, Dashboard } from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'
import { auditLabel } from '@/shared/auditLabels'
import { formatInTimezone } from '@/shared/time'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()
const data = ref<Dashboard | null>(peekWarmGet<Dashboard>('/parent/dashboard') ?? null)
const pendingApprovals = ref(peekWarmGet<ApprovalRequest[]>('/parent/approvals', { status: 'PENDING' })?.length ?? 0)
const loading = ref(data.value == null)

function childStatusLabel(status: string): string {
  if (status === 'paused') return t('common.statusListedPaused')
  if (status === 'archived') return t('common.statusListedArchived')
  return status
}

onMounted(async () => {
  try {
    const [dash, approvals] = await Promise.all([
      warmGet<Dashboard>('/parent/dashboard'),
      warmGet<ApprovalRequest[]>('/parent/approvals', { status: 'PENDING' }),
    ])
    data.value = dash
    pendingApprovals.value = approvals.length
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div v-loading="loading" class="dashboard">
    <template v-if="data">
      <section class="hero">
        <div>
          <div class="family">{{ data.family.name }}</div>
          <div class="meta">{{ t('parent.dashboard.timezoneToday', { date: data.today, tz: data.family.timezone }) }}</div>
        </div>
        <div class="stat">
          <div class="num">{{ data.active_devices }}</div>
          <div class="label">{{ t('parent.dashboard.devicesAuthorized') }}</div>
        </div>
      </section>

      <section class="block">
        <div class="block-head">
          <h2>{{ t('parent.dashboard.children') }}</h2>
          <RouterLink :to="{ name: 'parent-children' }">{{ t('common.manage') }}</RouterLink>
        </div>
        <el-empty v-if="data.children.length === 0" :description="t('parent.dashboard.noChildren')">
          <RouterLink :to="{ name: 'parent-children' }"><el-button type="primary">{{ t('parent.dashboard.createChild') }}</el-button></RouterLink>
        </el-empty>
        <div v-else class="kids">
          <div v-for="c in data.children" :key="c.id" class="kid" :style="{ borderColor: c.theme_color }">
            <span class="avatar" :style="{ background: c.theme_color }">{{ avatarEmoji(c.avatar) }}</span>
            <div>
              <div class="name">{{ c.nickname }}</div>
              <div class="meta">
                {{ t('parent.children.yearsOld', { n: c.age }) }} · {{ t('parent.children.gradeN', { n: c.grade }) }}
                <el-tag v-if="c.status !== 'active'" size="small" type="info" class="tag">{{ childStatusLabel(c.status) }}</el-tag>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="pendingApprovals > 0" class="block alert-block">
        <RouterLink :to="{ name: 'parent-approvals' }" class="approval-link">
          <span class="approval-num">{{ pendingApprovals }}</span>
          <span>{{ t('common.pendingLink', { n: pendingApprovals }) }}</span>
        </RouterLink>
      </section>

      <section class="block">
        <div class="block-head">
          <h2>{{ t('parent.dashboard.recent') }}</h2>
          <RouterLink :to="{ name: 'parent-audit' }">{{ t('common.viewAll') }}</RouterLink>
        </div>
        <ul class="activity">
          <li v-for="a in data.recent_activity" :key="a.id">
            <span>{{ auditLabel(a.action) }}</span>
            <time>{{ formatInTimezone(a.created_at, session.timezone, 'MM-DD HH:mm') }}</time>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 1100px;
}
.hero {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 20px 24px;
  border-radius: 16px;
  color: #fff;
  background: linear-gradient(135deg, var(--gp-space-mid), #5b8def);
}
.family {
  font-size: 22px;
  font-weight: 700;
}
.hero .meta {
  opacity: 0.85;
  margin-top: 4px;
}
.stat {
  text-align: center;
}
.num {
  font-size: 28px;
  font-weight: 700;
}
.label {
  font-size: 12px;
  opacity: 0.85;
}
.block {
  background: #fff;
  border-radius: 14px;
  padding: 16px 20px;
}
.block-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.block-head h2 {
  font-size: 16px;
  margin: 0 0 12px;
}
.block-head a {
  color: var(--el-color-primary);
  text-decoration: none;
  font-size: 14px;
}
.kids {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.kid {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 2px solid;
  border-radius: 14px;
}
.avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 26px;
}
.name {
  font-weight: 600;
}
.kid .meta {
  color: #6b6f80;
  font-size: 13px;
}
.tag {
  margin-left: 6px;
}
.activity {
  list-style: none;
  padding: 0;
  margin: 0;
}
.activity li {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid #f0f1f5;
  font-size: 14px;
}
.activity time {
  color: #9a9db0;
}
.alert-block {
  padding: 0;
  overflow: hidden;
}
.approval-link {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 20px;
  text-decoration: none;
  color: #1f2333;
  background: linear-gradient(90deg, #fff7e6, #fff);
}
.approval-num {
  display: grid;
  place-items: center;
  min-width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--el-color-warning);
  color: #fff;
  font-weight: 700;
  font-size: 18px;
}
</style>
