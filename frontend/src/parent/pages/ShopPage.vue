<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { Reward, RewardKind, RewardRedemption, RewardStatus, Wish, WishStatus } from '@/shared/api/types'

const { t } = useI18n()
const rewards = ref<Reward[]>([])
const redemptions = ref<RewardRedemption[]>([])
const wishes = ref<Wish[]>([])
const loading = ref(true)
const saving = ref(false)
const dialogOpen = ref(false)
const editing = ref<Reward | null>(null)
const tab = ref<'rewards' | 'redemptions' | 'wishes'>('rewards')

function kindLabel(kind: RewardKind): string {
  return t(`parent.shop.kind.${kind}`)
}

function statusLabel(status: RewardStatus): string {
  return t(`parent.shop.status.${status}`)
}

function redemptionStatusLabel(status: string): string {
  const key = `parent.shop.redemptionStatus.${status}`
  return t(key)
}

function wishStatusLabel(status: WishStatus): string {
  return t(`parent.shop.wishes.status.${status}`)
}

const form = ref({
  name: '',
  description: '',
  kind: 'physical' as RewardKind,
  coin_price: 10,
  stock: null as number | null,
  requires_approval: true,
  status: 'draft' as RewardStatus,
})

function resetForm(): void {
  form.value = {
    name: '',
    description: '',
    kind: 'physical',
    coin_price: 10,
    stock: null,
    requires_approval: true,
    status: 'draft',
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    ;[rewards.value, redemptions.value, wishes.value] = await Promise.all([
      api.get<Reward[]>('/parent/rewards'),
      api.get<RewardRedemption[]>('/parent/redemptions'),
      api.get<Wish[]>('/parent/wishes'),
    ])
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
}

function openCreate(): void {
  editing.value = null
  resetForm()
  dialogOpen.value = true
}

function openEdit(r: Reward): void {
  editing.value = r
  form.value = {
    name: r.name,
    description: r.description ?? '',
    kind: r.kind,
    coin_price: r.coin_price,
    stock: r.stock,
    requires_approval: r.requires_approval,
    status: r.status,
  }
  dialogOpen.value = true
}

async function save(): Promise<void> {
  if (!form.value.name.trim()) {
    ElMessage.warning(t('common.fillRewardName'))
    return
  }
  saving.value = true
  try {
    const payload = {
      name: form.value.name.trim(),
      description: form.value.description.trim() || null,
      kind: form.value.kind,
      coin_price: form.value.coin_price,
      stock: form.value.stock,
      requires_approval: form.value.requires_approval,
      status: form.value.status,
    }
    if (editing.value) {
      const updated = await api.put<Reward>(`/parent/rewards/${editing.value.id}`, payload)
      const i = rewards.value.findIndex((r) => r.id === updated.id)
      if (i >= 0) rewards.value[i] = updated
      ElMessage.success(t('common.saved'))
    } else {
      rewards.value.unshift(await api.post<Reward>('/parent/rewards', payload))
      ElMessage.success(t('common.rewardCreated'))
    }
    dialogOpen.value = false
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

function replaceWish(updated: Wish): void {
  const i = wishes.value.findIndex((w) => w.id === updated.id)
  if (i >= 0) wishes.value[i] = updated
}

async function convertWish(wish: Wish): Promise<void> {
  try {
    let price = wish.coin_price
    if (price == null) {
      const { value } = await ElMessageBox.prompt(t('parent.shop.wishes.fillPrice'), t('parent.shop.wishes.convert'), {
        inputPattern: /^[1-9]\d*$/,
        inputErrorMessage: t('parent.shop.wishes.fillPrice'),
        confirmButtonText: t('common.save'),
        cancelButtonText: t('common.cancel'),
      })
      price = Number(value)
    }
    const updated = await api.postIdempotent<Wish>(`/parent/wishes/${wish.id}/convert`, { coin_price: price })
    replaceWish(updated)
    if (updated.reward) rewards.value.unshift(updated.reward)
    ElMessage.success(t('parent.shop.wishes.converted'))
  } catch (e) {
    reportError(e)
  }
}

async function rejectWish(wish: Wish): Promise<void> {
  try {
    await ElMessageBox.confirm(t('parent.shop.wishes.rejectConfirm'), t('parent.shop.wishes.rejectTitle'), {
      type: 'warning',
      confirmButtonText: t('common.reject'),
      cancelButtonText: t('common.cancel'),
    })
    replaceWish(await api.postIdempotent<Wish>(`/parent/wishes/${wish.id}/reject`))
    ElMessage.success(t('parent.shop.wishes.rejected'))
  } catch (e) {
    reportError(e)
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <el-tabs v-model="tab">
      <el-tab-pane :label="t('common.rewardProducts')" name="rewards">
        <div class="toolbar">
          <el-button type="primary" @click="openCreate">{{ t('common.createReward') }}</el-button>
        </div>
        <el-table :data="rewards" stripe :empty-text="t('common.noRewardsYet')">
          <el-table-column prop="name" :label="t('common.name')" min-width="120" />
          <el-table-column :label="t('common.type')" width="100">
            <template #default="scope">{{ kindLabel((scope.row as Reward).kind) }}</template>
          </el-table-column>
          <el-table-column prop="coin_price" :label="t('common.price')" width="90" />
          <el-table-column :label="t('common.stock')" width="80">
            <template #default="{ row }">{{ row.stock ?? t('common.unlimited') }}</template>
          </el-table-column>
          <el-table-column :label="t('common.status')" width="80">
            <template #default="{ row }">
              <el-tag size="small" :type="row.status === 'on_shelf' ? 'success' : row.status === 'draft' ? 'info' : 'warning'">
                {{ statusLabel(row.status as RewardStatus) }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('common.actions')" width="80">
            <template #default="scope">
              <el-button link type="primary" @click="openEdit(scope.row as Reward)">{{ t('common.edit') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.shop.wishes.tab')" name="wishes">
        <el-table :data="wishes" stripe :empty-text="t('parent.shop.wishes.empty')">
          <el-table-column :label="t('parent.shop.wishes.child')" width="100">
            <template #default="{ row }">{{ (row as Wish).child?.nickname ?? (row as Wish).child_id }}</template>
          </el-table-column>
          <el-table-column prop="name" :label="t('common.name')" min-width="120" />
          <el-table-column :label="t('common.price')" width="90">
            <template #default="{ row }">{{ (row as Wish).coin_price ?? '—' }}</template>
          </el-table-column>
          <el-table-column :label="t('common.status')" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="(row as Wish).status === 'converted' ? 'success' : (row as Wish).status === 'rejected' ? 'info' : 'warning'">
                {{ wishStatusLabel((row as Wish).status) }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('common.actions')" width="160">
            <template #default="{ row }">
              <template v-if="(row as Wish).status === 'pending'">
                <el-button link type="primary" @click="convertWish(row as Wish)">{{ t('parent.shop.wishes.convert') }}</el-button>
                <el-button link type="danger" @click="rejectWish(row as Wish)">{{ t('common.reject') }}</el-button>
              </template>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('common.redemptionHistory')" name="redemptions">
        <el-table :data="redemptions" stripe :empty-text="t('common.noRedemptions')">
          <el-table-column :label="t('common.name')" min-width="120">
            <template #default="{ row }">{{ row.reward_snapshot?.name ?? `#${row.reward_id}` }}</template>
          </el-table-column>
          <el-table-column prop="coin_cost" :label="t('common.cost')" width="80" />
          <el-table-column :label="t('common.status')" width="90">
            <template #default="{ row }">{{ redemptionStatusLabel(row.status) }}</template>
          </el-table-column>
          <el-table-column prop="created_at" :label="t('common.time')" min-width="140" />
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <el-dialog v-model="dialogOpen" :title="editing ? t('common.editReward') : t('common.createReward')" width="440px" destroy-on-close>
      <el-form label-width="88px">
        <el-form-item :label="t('common.name')" required>
          <el-input v-model="form.name" maxlength="128" />
        </el-form-item>
        <el-form-item :label="t('common.description')">
          <el-input v-model="form.description" type="textarea" :rows="2" />
        </el-form-item>
        <el-form-item :label="t('common.type')">
          <el-select v-model="form.kind">
            <el-option v-for="k in ['physical', 'family_activity', 'virtual_decoration'] as const" :key="k" :label="kindLabel(k)" :value="k" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.price')">
          <el-input-number v-model="form.coin_price" :min="1" />
        </el-form-item>
        <el-form-item :label="t('common.stock')">
          <el-input-number v-model="form.stock" :min="0" :placeholder="t('common.stockPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('common.needsApproval')">
          <el-switch v-model="form.requires_approval" />
        </el-form-item>
        <el-form-item :label="t('common.status')">
          <el-select v-model="form.status">
            <el-option v-for="s in ['draft', 'on_shelf', 'off_shelf'] as const" :key="s" :label="statusLabel(s)" :value="s" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogOpen = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="save">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page {
  max-width: 960px;
}
.toolbar {
  margin-bottom: 12px;
}
</style>
