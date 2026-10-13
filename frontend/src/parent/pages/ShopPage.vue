<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { Reward, RewardKind, RewardRedemption, RewardStatus, Wish, WishStatus } from '@/shared/api/types'
import { useIsPhone } from '@/shared/useMediaQuery'

const { t } = useI18n()
const isPhone = useIsPhone()
const rewards = ref<Reward[]>([])
const redemptions = ref<RewardRedemption[]>([])
const wishes = ref<Wish[]>([])
const loading = ref(true)
const saving = ref(false)
const dialogOpen = ref(false)
const editing = ref<Reward | null>(null)
const tab = ref<'rewards' | 'redemptions' | 'wishes'>('rewards')
const imageFile = ref<File | null>(null)
const imagePreview = ref<string | null>(null)
const removeExistingImage = ref(false)

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
  clearImageSelection()
}

function clearImageSelection(): void {
  imageFile.value = null
  imagePreview.value = null
  removeExistingImage.value = false
}

function onImageChange(file: File | null): void {
  imageFile.value = file
  removeExistingImage.value = false
  if (!file) {
    imagePreview.value = editing.value?.image_url ?? null
    return
  }
  imagePreview.value = URL.createObjectURL(file)
}

function clearImage(): void {
  imageFile.value = null
  imagePreview.value = null
  if (editing.value?.image_url) removeExistingImage.value = true
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
  imageFile.value = null
  imagePreview.value = r.image_url ?? null
  removeExistingImage.value = false
  dialogOpen.value = true
}

function replaceReward(updated: Reward): void {
  const i = rewards.value.findIndex((r) => r.id === updated.id)
  if (i >= 0) rewards.value[i] = updated
  else rewards.value.unshift(updated)
}

async function save(): Promise<void> {
  if (!form.value.name.trim()) {
    ElMessage.warning(t('common.fillRewardName'))
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      const updated = await api.put<Reward>(`/parent/rewards/${editing.value.id}`, {
        name: form.value.name.trim(),
        description: form.value.description.trim() || null,
        kind: form.value.kind,
        coin_price: form.value.coin_price,
        stock: form.value.stock,
        requires_approval: form.value.requires_approval,
        status: form.value.status,
      })
      let finalReward = updated
      if (imageFile.value) {
        const body = new FormData()
        body.append('image', imageFile.value)
        finalReward = await api.upload<Reward>(`/parent/rewards/${editing.value.id}/image`, body)
      } else if (removeExistingImage.value) {
        finalReward = await api.delete<Reward>(`/parent/rewards/${editing.value.id}/image`)
      }
      replaceReward(finalReward)
      ElMessage.success(t('common.saved'))
    } else {
      const body = new FormData()
      body.append('name', form.value.name.trim())
      if (form.value.description.trim()) body.append('description', form.value.description.trim())
      body.append('kind', form.value.kind)
      body.append('coin_price', String(form.value.coin_price))
      if (form.value.stock != null) body.append('stock', String(form.value.stock))
      body.append('requires_approval', form.value.requires_approval ? '1' : '0')
      body.append('status', form.value.status)
      if (imageFile.value) body.append('image', imageFile.value)
      rewards.value.unshift(await api.upload<Reward>('/parent/rewards', body))
      ElMessage.success(t('common.rewardCreated'))
    }
    dialogOpen.value = false
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

async function removeReward(reward: Reward): Promise<void> {
  try {
    await ElMessageBox.confirm(t('common.confirmDeleteReward', { name: reward.name }), t('common.delete'), {
      type: 'warning',
      confirmButtonText: t('common.delete'),
      cancelButtonText: t('common.cancel'),
    })
    await api.delete(`/parent/rewards/${reward.id}`)
    rewards.value = rewards.value.filter((r) => r.id !== reward.id)
    ElMessage.success(t('common.rewardDeleted'))
  } catch (e) {
    reportError(e)
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
        <el-table v-if="!isPhone" :data="rewards" stripe :empty-text="t('common.noRewardsYet')">
          <el-table-column :label="t('common.rewardImage')" width="72">
            <template #default="{ row }">
              <img v-if="(row as Reward).image_url" class="thumb" :src="(row as Reward).image_url!" :alt="(row as Reward).name" />
              <span v-else class="thumb empty">—</span>
            </template>
          </el-table-column>
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
          <el-table-column :label="t('common.actions')" width="140">
            <template #default="scope">
              <el-button link type="primary" @click="openEdit(scope.row as Reward)">{{ t('common.edit') }}</el-button>
              <el-button link type="danger" @click="removeReward(scope.row as Reward)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="rewards.length === 0" :description="t('common.noRewardsYet')" />
          <article v-for="row in rewards" :key="row.id" class="phone-card">
            <img v-if="row.image_url" class="thumb" :src="row.image_url" :alt="row.name" />
            <span v-else class="thumb empty">—</span>
            <div class="phone-info">
              <div class="phone-title">{{ row.name }}</div>
              <div class="phone-meta">
                {{ kindLabel(row.kind) }} · 🪙 {{ row.coin_price }} · {{ row.stock ?? t('common.unlimited') }}
              </div>
              <el-tag size="small" :type="row.status === 'on_shelf' ? 'success' : row.status === 'draft' ? 'info' : 'warning'">
                {{ statusLabel(row.status) }}
              </el-tag>
            </div>
            <div class="phone-ops">
              <el-button size="small" @click="openEdit(row)">{{ t('common.edit') }}</el-button>
              <el-button size="small" type="danger" @click="removeReward(row)">{{ t('common.delete') }}</el-button>
            </div>
          </article>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.shop.wishes.tab')" name="wishes">
        <el-table v-if="!isPhone" :data="wishes" stripe :empty-text="t('parent.shop.wishes.empty')">
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
        <div v-else class="phone-list">
          <el-empty v-if="wishes.length === 0" :description="t('parent.shop.wishes.empty')" />
          <article v-for="row in wishes" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.name }}</div>
              <div class="phone-meta">
                {{ row.child?.nickname ?? row.child_id }} · {{ row.coin_price ?? '—' }}
              </div>
              <el-tag size="small" :type="row.status === 'converted' ? 'success' : row.status === 'rejected' ? 'info' : 'warning'">
                {{ wishStatusLabel(row.status) }}
              </el-tag>
            </div>
            <div v-if="row.status === 'pending'" class="phone-ops">
              <el-button size="small" type="primary" @click="convertWish(row)">{{ t('parent.shop.wishes.convert') }}</el-button>
              <el-button size="small" type="danger" @click="rejectWish(row)">{{ t('common.reject') }}</el-button>
            </div>
          </article>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('common.redemptionHistory')" name="redemptions">
        <el-table v-if="!isPhone" :data="redemptions" stripe :empty-text="t('common.noRedemptions')">
          <el-table-column :label="t('common.name')" min-width="120">
            <template #default="{ row }">{{ row.reward_snapshot?.name ?? `#${row.reward_id}` }}</template>
          </el-table-column>
          <el-table-column prop="coin_price" :label="t('common.cost')" width="80" />
          <el-table-column :label="t('common.status')" width="90">
            <template #default="{ row }">{{ redemptionStatusLabel(row.status) }}</template>
          </el-table-column>
          <el-table-column prop="created_at" :label="t('common.time')" min-width="140" />
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="redemptions.length === 0" :description="t('common.noRedemptions')" />
          <article v-for="row in redemptions" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.reward_snapshot?.name ?? `#${row.reward_id}` }}</div>
              <div class="phone-meta">
                🪙 {{ row.coin_price }} · {{ redemptionStatusLabel(row.status) }} · {{ row.created_at }}
              </div>
            </div>
          </article>
        </div>
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
        <el-form-item :label="t('common.rewardImage')">
          <div class="image-field">
            <img v-if="imagePreview" class="preview" :src="imagePreview" alt="" />
            <el-upload
              :auto-upload="false"
              :show-file-list="false"
              accept="image/jpeg,image/png,image/webp,image/gif"
              :on-change="(file) => onImageChange(file.raw ?? null)"
            >
              <el-button>{{ t('common.rewardImagePick') }}</el-button>
            </el-upload>
            <el-button v-if="imagePreview" link type="danger" @click="clearImage">{{ t('common.rewardImageRemove') }}</el-button>
            <p class="hint">{{ t('common.rewardImageHint') }}</p>
          </div>
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
.thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 8px;
  display: block;
}
.thumb.empty {
  display: grid;
  place-items: center;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-placeholder);
  font-size: 12px;
}
.image-field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  width: 100%;
}
.preview {
  width: 120px;
  height: 120px;
  object-fit: cover;
  border-radius: 12px;
  background: var(--el-fill-color-light);
}
.hint {
  margin: 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.phone-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.phone-card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--el-border-color-lighter);
}
.phone-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.phone-title {
  font-weight: 600;
  font-size: 15px;
}
.phone-meta {
  color: #6b6f80;
  font-size: 13px;
}
.phone-ops {
  width: 100%;
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  flex-wrap: wrap;
}
</style>
