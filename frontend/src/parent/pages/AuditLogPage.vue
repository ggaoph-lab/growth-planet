<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { requestPage } from '@/shared/api/client'
import type { AuditEntry } from '@/shared/api/types'
import { auditLabel } from '@/shared/auditLabels'
import { formatInTimezone } from '@/shared/time'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()
const entries = ref<AuditEntry[]>([])
const page = ref(1)
const total = ref(0)
const perPage = 20
const loading = ref(true)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await requestPage<AuditEntry>('/parent/audit-logs', { page: page.value, per_page: perPage })
    entries.value = result.data
    total.value = result.meta.total
  } finally {
    loading.value = false
  }
}

function actorText(e: AuditEntry): string {
  if (e.actor) return e.actor
  return e.actor_role === null ? t('parent.audit.system') : '—'
}

function onPage(p: number): void {
  page.value = p
  void load()
}

onMounted(load)
</script>

<template>
  <div class="audit">
    <p class="desc">{{ t('parent.audit.desc') }}</p>
    <div v-loading="loading" class="list">
      <el-empty v-if="!loading && entries.length === 0" :description="t('parent.audit.empty')" />
      <div v-for="e in entries" :key="e.id" class="row">
        <div class="main">
          <div class="action">{{ auditLabel(e.action) }}</div>
          <div class="meta">{{ actorText(e) }}<template v-if="e.ip"> · {{ e.ip }}</template></div>
        </div>
        <time>{{ formatInTimezone(e.created_at, session.timezone, 'YYYY-MM-DD HH:mm:ss') }}</time>
      </div>
    </div>
    <el-pagination v-if="total > perPage" layout="prev, pager, next" :total="total" :page-size="perPage" :current-page="page" class="pager" @current-change="onPage" />
  </div>
</template>

<style scoped>
.audit {
  max-width: 900px;
}
.desc {
  color: #6b6f80;
  font-size: 13px;
  margin: 0 0 12px;
}
.list {
  background: #fff;
  border-radius: 14px;
  padding: 4px 20px;
  min-height: 120px;
}
.row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid #f0f1f5;
}
.row:last-child {
  border-bottom: none;
}
.action {
  font-weight: 600;
}
.meta {
  color: #6b6f80;
  font-size: 12px;
  margin-top: 2px;
}
time {
  color: #9a9db0;
  font-size: 13px;
  white-space: nowrap;
}
.pager {
  margin-top: 16px;
  justify-content: center;
}
</style>
