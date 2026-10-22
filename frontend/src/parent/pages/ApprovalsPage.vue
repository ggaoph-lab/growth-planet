<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ApprovalRequest, ApprovalType } from '@/shared/api/types'

const { t } = useI18n()
const items = ref<ApprovalRequest[]>([])
const loading = ref(true)
const acting = ref<number | null>(null)

function typeLabel(type: ApprovalType): string {
  const key = `parent.approvals.types.${type}`
  return t(key)
}

function taskHeading(item: ApprovalRequest): string {
  const summary = item.summary
  if (!summary?.title) return ''
  return summary.group_title ? `${summary.group_title} · ${summary.title}` : summary.title
}

function rewardLine(item: ApprovalRequest): string {
  const xp = item.summary?.xp_reward ?? 0
  const points = item.summary?.point_reward ?? 0
  const coins = item.summary?.coin_reward ?? 0
  if (!xp && !points && !coins) return ''
  return t('parent.approvals.rewardAfter', { xp, points, coins })
}

function isRedemption(item: ApprovalRequest): boolean {
  return item.type === 'redemption'
}

function kindLabel(item: ApprovalRequest): string {
  const kind = item.summary?.kind
  if (!kind) return ''
  return t(`parent.shop.kind.${kind}`)
}

function redeemCost(item: ApprovalRequest): string {
  const coins = item.summary?.coin_price ?? 0
  if (!coins) return ''
  return t('parent.approvals.redeemCost', { coins })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    items.value = await api.get<ApprovalRequest[]>('/parent/approvals', { status: 'PENDING' })
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(e.message)
}

async function decide(item: ApprovalRequest, action: 'approve' | 'reject' | 'return'): Promise<void> {
  let note: string | undefined
  if (action === 'return' || action === 'reject') {
    try {
      const { value } = await ElMessageBox.prompt(
        action === 'return' ? t('common.returnPrompt') : t('common.rejectPrompt'),
        action === 'return' ? t('common.return') : t('common.reject'),
        { inputType: 'textarea', confirmButtonText: t('common.confirm'), cancelButtonText: t('common.cancel') },
      )
      note = value || undefined
    } catch {
      return
    }
  } else if (isRedemption(item)) {
    try {
      await ElMessageBox.confirm(
        t('parent.approvals.fulfillConfirm', {
          name: item.summary?.title || typeLabel(item.type),
          coins: item.summary?.coin_price ?? 0,
        }),
        t('parent.approvals.fulfillTitle'),
        {
          confirmButtonText: t('parent.approvals.fulfill'),
          cancelButtonText: t('common.cancel'),
        },
      )
    } catch {
      return
    }
  } else {
    const xp = item.summary?.xp_reward ?? 0
    const points = item.summary?.point_reward ?? 0
    const coins = item.summary?.coin_reward ?? 0
    const message = item.type === 'task_completion' && (xp || points || coins)
      ? t('parent.approvals.approveTask', { xp, points, coins })
      : t('common.approvalConfirm')
    try {
      await ElMessageBox.confirm(message, t('common.approveTitle'), {
        confirmButtonText: t('common.approve'),
        cancelButtonText: t('common.cancel'),
      })
    } catch {
      return
    }
  }

  acting.value = item.id
  try {
    await api.postIdempotent(`/parent/approvals/${item.id}/${action}`, {
      lock_version: item.lock_version,
      note,
    })
    ElMessage.success(t('common.processed'))
    items.value = items.value.filter((a) => a.id !== item.id)
  } catch (e) {
    reportError(e)
    await load()
  } finally {
    acting.value = null
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <el-empty v-if="!loading && items.length === 0" :description="t('common.pendingApprovalsEmpty')" />
    <el-card v-for="item in items" :key="item.id" shadow="never" class="card">
      <div class="head">
        <el-tag type="warning" size="small">{{ t('growth.pending') }}</el-tag>
        <span class="type">{{ typeLabel(item.type) }}</span>
        <span class="meta">{{ item.child_name || t('common.childId') + ' ' + item.child_id }}</span>
      </div>
      <p v-if="taskHeading(item)" class="title">{{ taskHeading(item) }}</p>
      <p v-if="kindLabel(item)" class="sub">{{ kindLabel(item) }}</p>
      <p v-if="item.summary?.description" class="sub">{{ item.summary.description }}</p>
      <p v-if="isRedemption(item) && redeemCost(item)" class="sub">{{ redeemCost(item) }}</p>
      <p v-else-if="rewardLine(item)" class="sub">{{ rewardLine(item) }}</p>
      <p v-else-if="!taskHeading(item)" class="sub">{{ t('common.subjectLink', { type: item.subject_type, id: item.subject_id }) }}</p>
      <div class="actions">
        <el-button v-if="isRedemption(item)" type="success" :loading="acting === item.id" @click="decide(item, 'approve')">{{ t('parent.approvals.fulfill') }}</el-button>
        <el-button v-else type="success" :loading="acting === item.id" @click="decide(item, 'approve')">{{ t('common.approve') }}</el-button>
        <el-button v-if="!isRedemption(item)" type="warning" :loading="acting === item.id" @click="decide(item, 'return')">{{ t('common.return') }}</el-button>
        <el-button type="danger" plain :loading="acting === item.id" @click="decide(item, 'reject')">{{ t('common.reject') }}</el-button>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.page {
  max-width: 720px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.card {
  border-radius: 14px;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.type,
.title {
  font-weight: 600;
}
.title {
  margin: 0 0 6px;
}
.meta {
  color: #9a9db0;
  font-size: 13px;
}
.sub {
  margin: 0 0 12px;
  color: #6b6f80;
  font-size: 14px;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
