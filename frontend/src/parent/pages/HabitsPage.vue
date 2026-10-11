<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ParentChild, ParentHabit } from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'

const { t } = useI18n()
const habits = ref<ParentHabit[]>([])
const children = ref<ParentChild[]>([])
const loading = ref(true)
const saving = ref(false)
const dialogOpen = ref(false)
const editing = ref<ParentHabit | null>(null)

const childMap = computed(() => new Map(children.value.map((c) => [c.id, c])))

const form = ref({
  child_id: undefined as number | undefined,
  title: '',
  icon: '⭐',
  frequency: 'daily',
  target_per_period: 1,
  xp_reward: 3,
  coin_reward: 1,
  is_active: true,
})

function resetForm(): void {
  form.value = {
    child_id: children.value.find((c) => c.status === 'active')?.id,
    title: '',
    icon: '⭐',
    frequency: 'daily',
    target_per_period: 1,
    xp_reward: 3,
    coin_reward: 1,
    is_active: true,
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    ;[habits.value, children.value] = await Promise.all([
      api.get<ParentHabit[]>('/parent/habits'),
      api.get<ParentChild[]>('/parent/children'),
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

function openEdit(h: ParentHabit): void {
  editing.value = h
  form.value = {
    child_id: h.child_id,
    title: h.title,
    icon: h.icon ?? '⭐',
    frequency: h.frequency,
    target_per_period: h.target_per_period,
    xp_reward: h.xp_reward,
    coin_reward: h.coin_reward,
    is_active: h.is_active,
  }
  dialogOpen.value = true
}

async function save(): Promise<void> {
  if (!form.value.title.trim() || !form.value.child_id) {
    ElMessage.warning(t('common.fillTitleAndChild'))
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      const updated = await api.put<ParentHabit>(`/parent/habits/${editing.value.id}`, {
        title: form.value.title.trim(),
        icon: form.value.icon || null,
        xp_reward: form.value.xp_reward,
        coin_reward: form.value.coin_reward,
        is_active: form.value.is_active,
      })
      const i = habits.value.findIndex((h) => h.id === updated.id)
      if (i >= 0) habits.value[i] = updated
      ElMessage.success(t('common.saved'))
    } else {
      const created = await api.post<ParentHabit>('/parent/habits', {
        child_id: form.value.child_id,
        title: form.value.title.trim(),
        icon: form.value.icon || null,
        frequency: form.value.frequency,
        target_per_period: form.value.target_per_period,
        xp_reward: form.value.xp_reward,
        coin_reward: form.value.coin_reward,
      })
      habits.value.unshift(created)
      ElMessage.success(t('common.habitCreated'))
    }
    dialogOpen.value = false
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <div class="toolbar">
      <el-button type="primary" @click="openCreate">{{ t('common.createHabit') }}</el-button>
    </div>
    <el-table :data="habits" stripe :empty-text="t('common.noHabitsYet')">
      <el-table-column label="" width="48">
        <template #default="{ row }">{{ row.icon ?? '⭐' }}</template>
      </el-table-column>
      <el-table-column prop="title" :label="t('common.title')" min-width="120" />
      <el-table-column :label="t('parent.dashboard.children')" width="120">
        <template #default="{ row }">
          <span v-if="childMap.get(row.child_id)">{{ avatarEmoji(childMap.get(row.child_id)!.avatar) }} {{ childMap.get(row.child_id)!.nickname }}</span>
          <span v-else>{{ row.child_id }}</span>
        </template>
      </el-table-column>
      <el-table-column :label="t('growth.coins')" width="100">
        <template #default="{ row }">{{ row.xp_reward }} XP · {{ row.coin_reward }} {{ t('common.coinShort') }}</template>
      </el-table-column>
      <el-table-column :label="t('common.status')" width="80">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? t('common.enabled') : t('common.disabledLabel') }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('common.actions')" width="80">
        <template #default="scope">
          <el-button link type="primary" @click="openEdit(scope.row as ParentHabit)">{{ t('common.edit') }}</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogOpen" :title="editing ? t('common.editHabit') : t('common.createHabit')" width="420px" destroy-on-close>
      <el-form label-width="88px">
        <el-form-item v-if="!editing" :label="t('parent.dashboard.children')" required>
          <el-select v-model="form.child_id" :placeholder="t('common.selectChild')">
            <el-option v-for="c in children.filter((x) => x.status === 'active')" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.title')" required>
          <el-input v-model="form.title" maxlength="128" />
        </el-form-item>
        <el-form-item label="Icon">
          <el-input v-model="form.icon" maxlength="32" />
        </el-form-item>
        <el-form-item v-if="!editing" :label="t('common.frequency')">
          <el-select v-model="form.frequency">
            <el-option :label="t('common.daily')" value="daily" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.xpLabel')">
          <el-input-number v-model="form.xp_reward" :min="0" />
        </el-form-item>
        <el-form-item :label="t('common.growthCoin')">
          <el-input-number v-model="form.coin_reward" :min="0" />
        </el-form-item>
        <el-form-item v-if="editing" :label="t('common.enabled')">
          <el-switch v-model="form.is_active" />
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
  max-width: 900px;
}
.toolbar {
  margin-bottom: 12px;
}
</style>
