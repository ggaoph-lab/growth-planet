<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ChildLedger, DeductionPreset, LedgerEntry, ParentChild } from '@/shared/api/types'
import { ledgerEntryKind, ledgerEntryTitle } from '@/shared/ledgerLabels'
import { formatInTimezone } from '@/shared/time'
import { useSessionStore } from '@/stores/session'

const open = defineModel<boolean>({ required: true })
const props = defineProps<{ child: ParentChild | null }>()

const { t } = useI18n()
const session = useSessionStore()
const asset = ref<'point' | 'coin'>('point')
const ledger = ref<ChildLedger | null>(null)
const presets = ref<DeductionPreset[]>([])
const selectedIds = ref<number[]>([])
const loading = ref(false)
const submitting = ref(false)
const managing = ref(false)
const savingPreset = ref(false)
const form = reactive({ point: 0, coin: 0, note: '' })
const presetForm = reactive({ id: 0, title: '', point_amount: 1, coin_amount: 0, is_active: true })

const title = computed(() =>
  props.child ? t('parent.children.ledgerTitle', { name: props.child.nickname }) : '',
)

const activePresets = computed(() => presets.value.filter((p) => p.is_active))

const selectedPresets = computed(() =>
  activePresets.value.filter((p) => selectedIds.value.includes(p.id)),
)

const selectedTotals = computed(() => {
  const points = selectedPresets.value.reduce((sum, p) => sum + p.point_amount, 0) + (form.point || 0)
  const coins = selectedPresets.value.reduce((sum, p) => sum + p.coin_amount, 0) + (form.coin || 0)
  return { points, coins }
})

const hasSelection = computed(
  () => selectedIds.value.length > 0 || form.point > 0 || form.coin > 0,
)

watch(
  () => [open.value, props.child?.id, asset.value] as const,
  ([isOpen, childId]) => {
    if (isOpen && childId) {
      void load()
      void loadPresets()
    }
    if (!isOpen) {
      ledger.value = null
      selectedIds.value = []
      managing.value = false
      resetCustom()
      resetPresetForm()
    }
  },
)

function resetCustom(): void {
  form.point = 0
  form.coin = 0
  form.note = ''
}

function resetPresetForm(): void {
  presetForm.id = 0
  presetForm.title = ''
  presetForm.point_amount = 1
  presetForm.coin_amount = 0
  presetForm.is_active = true
}

async function load(): Promise<void> {
  if (!props.child) return
  loading.value = true
  try {
    ledger.value = await api.get<ChildLedger>(`/parent/children/${props.child.id}/ledger`, { asset: asset.value })
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
    else ElMessage.error(t('common.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function loadPresets(): Promise<void> {
  try {
    presets.value = await api.get<DeductionPreset[]>('/parent/deduction-presets')
    selectedIds.value = selectedIds.value.filter((id) =>
      presets.value.some((p) => p.id === id && p.is_active),
    )
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  }
}

function amountText(entry: LedgerEntry): string {
  const sign = entry.amount > 0 ? '+' : ''
  return `${sign}${entry.amount}`
}

function whenText(entry: LedgerEntry): string {
  if (entry.created_at) return formatInTimezone(entry.created_at, session.timezone, 'YYYY-MM-DD HH:mm')
  return entry.business_date ?? '—'
}

function presetCost(preset: DeductionPreset): string {
  const parts: string[] = []
  if (preset.point_amount > 0) parts.push(`-${preset.point_amount} ${t('common.pointsLabel')}`)
  if (preset.coin_amount > 0) parts.push(`-${preset.coin_amount} ${t('common.growthCoin')}`)
  return parts.join(' · ')
}

function editPreset(preset: DeductionPreset): void {
  managing.value = true
  presetForm.id = preset.id
  presetForm.title = preset.title
  presetForm.point_amount = preset.point_amount
  presetForm.coin_amount = preset.coin_amount
  presetForm.is_active = preset.is_active
}

async function savePreset(): Promise<void> {
  if (!presetForm.title.trim()) {
    ElMessage.warning(t('parent.children.deductPresetNeedTitle'))
    return
  }
  if (!presetForm.point_amount && !presetForm.coin_amount) {
    ElMessage.warning(t('parent.children.deductPresetNeedAmount'))
    return
  }
  savingPreset.value = true
  try {
    const body = {
      title: presetForm.title.trim(),
      point_amount: presetForm.point_amount,
      coin_amount: presetForm.coin_amount,
      is_active: presetForm.is_active,
    }
    if (presetForm.id) {
      await api.put(`/parent/deduction-presets/${presetForm.id}`, body)
    } else {
      await api.post('/parent/deduction-presets', body)
    }
    ElMessage.success(t('parent.children.deductPresetSaved'))
    resetPresetForm()
    await loadPresets()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
    if (e instanceof ApiError) ElMessage.error(e.message)
    else ElMessage.error(t('common.saveFailed'))
  } finally {
    savingPreset.value = false
  }
}

async function removePreset(preset: DeductionPreset): Promise<void> {
  try {
    await ElMessageBox.confirm(
      t('parent.children.deductPresetDeleteConfirm', { title: preset.title }),
      t('parent.children.deductManage'),
      { type: 'warning', confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel') },
    )
    await api.delete(`/parent/deduction-presets/${preset.id}`)
    ElMessage.success(t('parent.children.deductPresetDeleted'))
    if (presetForm.id === preset.id) resetPresetForm()
    selectedIds.value = selectedIds.value.filter((id) => id !== preset.id)
    await loadPresets()
  } catch (e) {
    if (e === 'cancel' || e === 'close') return
    if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
    if (e instanceof ApiError) ElMessage.error(e.message)
    else ElMessage.error(t('common.operationFailed'))
  }
}

async function submitDeduct(): Promise<void> {
  if (!props.child) return
  if (!hasSelection.value) {
    ElMessage.warning(t('parent.children.deductNeedSelection'))
    return
  }
  if ((form.point > 0 || form.coin > 0) && !form.note.trim()) {
    ElMessage.warning(t('parent.children.deductNeedNote'))
    return
  }
  submitting.value = true
  try {
    await api.postIdempotent(`/parent/children/${props.child.id}/deduct`, {
      preset_ids: selectedIds.value.length ? selectedIds.value : undefined,
      point: form.point || undefined,
      coin: form.coin || undefined,
      note: form.note.trim() || undefined,
    })
    ElMessage.success(t('parent.children.deductSuccess'))
    selectedIds.value = []
    resetCustom()
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
    if (e instanceof ApiError) ElMessage.error(e.message)
    else ElMessage.error(t('common.operationFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <el-dialog v-model="open" :title="title" width="620px" destroy-on-close>
    <div class="asset-switch">
      <el-radio-group v-model="asset">
        <el-radio-button value="point">{{ t('parent.children.pointsTab') }}</el-radio-button>
        <el-radio-button value="coin">{{ t('parent.children.coinsTab') }}</el-radio-button>
      </el-radio-group>
    </div>

    <div v-loading="loading" class="body">
      <div v-if="ledger" class="balances">
        <span>{{ t('parent.children.availableBalance', { n: ledger.available ?? 0 }) }}</span>
        <span>{{ t('parent.children.heldBalance', { n: ledger.held ?? 0 }) }}</span>
      </div>

      <section class="deduct">
        <div class="deduct-head">
          <h3>{{ t('parent.children.deductTitle') }}</h3>
          <el-button link type="primary" @click="managing = !managing">
            {{ t('parent.children.deductManage') }}
          </el-button>
        </div>
        <p class="hint">{{ t('parent.children.deductHint') }}</p>

        <div v-if="managing" class="manage">
          <div class="preset-form">
            <el-input v-model="presetForm.title" :placeholder="t('parent.children.deductPresetTitle')" maxlength="128" />
            <el-input-number v-model="presetForm.point_amount" :min="0" :max="50" />
            <span class="unit">{{ t('common.pointsLabel') }}</span>
            <el-input-number v-model="presetForm.coin_amount" :min="0" :max="50" />
            <span class="unit">{{ t('common.growthCoin') }}</span>
            <el-switch
              v-model="presetForm.is_active"
              :active-text="t('parent.children.deductPresetToggle')"
              :inactive-text="t('parent.children.deductPresetInactive')"
            />
            <el-button type="primary" :loading="savingPreset" @click="savePreset">
              {{ presetForm.id ? t('parent.children.deductPresetSave') : t('parent.children.deductPresetAdd') }}
            </el-button>
            <el-button v-if="presetForm.id" @click="resetPresetForm">{{ t('common.cancel') }}</el-button>
          </div>
          <ul class="manage-list">
            <li v-for="preset in presets" :key="preset.id">
              <div>
                <strong :class="{ off: !preset.is_active }">{{ preset.title }}</strong>
                <span class="cost">{{ presetCost(preset) }}</span>
                <el-tag v-if="!preset.is_active" size="small" type="info">{{ t('parent.children.deductPresetInactive') }}</el-tag>
              </div>
              <div class="manage-ops">
                <el-button size="small" @click="editPreset(preset)">{{ t('common.edit') }}</el-button>
                <el-button size="small" type="danger" plain @click="removePreset(preset)">{{ t('common.delete') }}</el-button>
              </div>
            </li>
          </ul>
        </div>

        <el-empty
          v-if="activePresets.length === 0"
          :description="t('parent.children.deductEmptyPresets')"
          :image-size="64"
        />
        <el-checkbox-group v-else v-model="selectedIds" class="preset-list">
          <div v-for="preset in activePresets" :key="preset.id" class="preset-item">
            <el-checkbox :value="preset.id">
              <span class="preset-title">{{ preset.title }}</span>
              <span class="cost">{{ presetCost(preset) }}</span>
            </el-checkbox>
          </div>
        </el-checkbox-group>

        <details class="custom">
          <summary>{{ t('parent.children.deductCustom') }}</summary>
          <div class="deduct-row">
            <el-form-item :label="t('parent.children.deductPoints')">
              <el-input-number v-model="form.point" :min="0" :max="50" />
            </el-form-item>
            <el-form-item :label="t('parent.children.deductCoins')">
              <el-input-number v-model="form.coin" :min="0" :max="50" />
            </el-form-item>
          </div>
          <el-form-item :label="t('parent.children.deductNote')">
            <el-input
              v-model="form.note"
              type="textarea"
              :rows="2"
              maxlength="255"
              show-word-limit
              :placeholder="t('parent.children.deductNotePlaceholder')"
            />
          </el-form-item>
        </details>

        <p v-if="hasSelection" class="total">
          {{ t('parent.children.deductSelectedTotal', selectedTotals) }}
        </p>
        <el-button type="danger" :loading="submitting" :disabled="!hasSelection" @click="submitDeduct">
          {{ t('parent.children.deductSubmit') }}
        </el-button>
      </section>

      <section class="history">
        <h3>{{ t('parent.children.ledgerHistory') }}</h3>
        <el-empty v-if="!loading && !ledger?.entries?.length" :description="t('parent.children.ledgerEmpty')" />
        <ul v-else class="entries">
          <li v-for="entry in ledger?.entries ?? []" :key="entry.ulid">
            <div class="main">
              <div>
                <div class="note">{{ ledgerEntryTitle(entry) }}</div>
                <div class="meta">{{ whenText(entry) }} · {{ ledgerEntryKind(entry) }}</div>
              </div>
              <div class="amt" :class="{ plus: entry.amount > 0, minus: entry.amount < 0 }">{{ amountText(entry) }}</div>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </el-dialog>
</template>

<style scoped>
.asset-switch {
  margin-bottom: 14px;
}
.body {
  min-height: 180px;
}
.balances {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
  color: #6b6f80;
  font-size: 13px;
}
.deduct {
  margin-bottom: 20px;
  padding: 14px 16px;
  border-radius: 12px;
  background: #f7f8fc;
  border: 1px solid #e8ebf3;
}
.deduct-head,
.manage-ops {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.deduct h3,
.history h3 {
  margin: 0 0 12px;
  font-size: 14px;
}
.deduct-head h3 {
  margin: 0;
}
.hint {
  margin: 8px 0 12px;
  color: #6b6f80;
  font-size: 13px;
}
.manage {
  margin-bottom: 14px;
  padding: 12px;
  border-radius: 10px;
  background: #fff;
  border: 1px dashed #d7dbe8;
}
.preset-form {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}
.preset-form .el-input {
  width: min(220px, 100%);
}
.unit {
  font-size: 12px;
  color: #6b6f80;
}
.manage-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}
.manage-list li {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  border-radius: 8px;
  background: #f7f8fc;
}
.manage-list .off {
  opacity: 0.55;
  text-decoration: line-through;
}
.preset-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}
.preset-item {
  display: block;
  padding: 10px 12px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid #e8ebf3;
  cursor: pointer;
}
.preset-title {
  font-weight: 600;
  margin-right: 8px;
}
.cost {
  color: #d64545;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}
.custom {
  margin: 8px 0 12px;
}
.custom summary {
  cursor: pointer;
  color: #5b8def;
  font-size: 13px;
  margin-bottom: 8px;
}
.deduct-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 24px;
}
.total {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 600;
}
.entries {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
  max-height: calc(5 * 3.75rem + 4 * 8px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
}
.entries li {
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid #e8ebf3;
  background: #fff;
}
.main {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}
.note {
  font-weight: 600;
}
.meta {
  margin-top: 4px;
  font-size: 12px;
  color: #6b6f80;
}
.amt {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.amt.plus {
  color: #2f9e5b;
}
.amt.minus {
  color: #d64545;
}
</style>
