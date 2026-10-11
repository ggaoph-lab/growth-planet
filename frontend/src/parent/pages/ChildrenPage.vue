<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ParentChild } from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'
import ChildFormDialog from '../components/ChildFormDialog.vue'

const { t } = useI18n()
const children = ref<ParentChild[]>([])
const loading = ref(true)
const formOpen = ref(false)
const editing = ref<ParentChild | null>(null)

function statusLabel(status: string): string {
  if (status === 'active') return t('parent.children.active')
  if (status === 'paused') return t('common.statusListedPaused')
  return t('common.statusListedArchived')
}

async function load(): Promise<void> {
  loading.value = true
  try {
    children.value = await api.get<ParentChild[]>('/parent/children')
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function openEdit(child: ParentChild): void {
  editing.value = child
  formOpen.value = true
}

async function replace(updated: ParentChild): Promise<void> {
  const i = children.value.findIndex((c) => c.id === updated.id)
  if (i >= 0) children.value[i] = updated
  else children.value.push(updated)
  await load()
}

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(e.fieldErrors.birth_date?.[0] ?? e.fieldErrors.pin?.[0] ?? e.message)
}

async function changeBirthDate(child: ParentChild): Promise<void> {
  try {
    const { value } = await ElMessageBox.prompt(t('parent.children.birthPrompt'), t('parent.children.birthTitle', { name: child.nickname }), {
      inputValue: child.birth_date,
      inputPattern: /^\d{4}-\d{2}-\d{2}$/,
      inputErrorMessage: t('common.dateFormatError'),
      confirmButtonText: t('common.save'),
      cancelButtonText: t('common.cancel'),
    })
    replace(await api.put<ParentChild>(`/parent/children/${child.id}/birth-date`, { birth_date: value }))
    ElMessage.success(t('parent.children.birthUpdated'))
  } catch (e) {
    reportError(e)
  }
}

async function changePin(child: ParentChild): Promise<void> {
  try {
    const { value } = await ElMessageBox.prompt(t('parent.children.pinPromptExtended'), t('parent.children.pinTitle', { name: child.nickname }), {
      inputType: 'password',
      inputPattern: /^(\d{4,6})?$/,
      inputErrorMessage: t('common.pinFormatError'),
      confirmButtonText: t('common.save'),
      cancelButtonText: t('common.cancel'),
    })
    replace(await api.put<ParentChild>(`/parent/children/${child.id}/pin`, { pin: value ? value : null }))
    ElMessage.success(value ? t('parent.children.pinUpdated') : t('parent.children.pinCleared'))
  } catch (e) {
    reportError(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="children">
    <div class="toolbar">
      <span class="hint">{{ t('parent.children.hint') }}</span>
      <el-button type="primary" @click="openCreate">{{ t('parent.children.create') }}</el-button>
    </div>

    <div v-loading="loading" class="list">
      <el-empty v-if="!loading && children.length === 0" :description="t('parent.dashboard.noChildren')" />
      <article v-for="c in children" :key="c.id" class="card" :class="{ inactive: c.status !== 'active' }">
        <span class="avatar" :style="{ background: c.theme_color }">{{ avatarEmoji(c.avatar) }}</span>
        <div class="info">
          <div class="name">
            {{ c.nickname }}
            <el-tag size="small" :type="c.status === 'active' ? 'success' : 'info'">{{ statusLabel(c.status) }}</el-tag>
          </div>
          <div class="meta">
            {{
              t('common.childMeta', {
                age: c.age,
                grade: c.grade,
                birth: c.birth_date,
                pin: c.pin_enabled ? t('parent.children.pinOn') : t('parent.children.pinOff'),
              })
            }}
          </div>
        </div>
        <div class="ops">
          <el-button size="small" @click="openEdit(c)">{{ t('common.edit') }}</el-button>
          <el-button size="small" @click="changeBirthDate(c)">{{ t('parent.children.changeBirth') }}</el-button>
          <el-button size="small" @click="changePin(c)">{{ t('parent.children.pin') }}</el-button>
        </div>
      </article>
    </div>

    <ChildFormDialog v-model="formOpen" :child="editing" @saved="replace" />
  </div>
</template>

<style scoped>
.children {
  max-width: 1000px;
}
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.hint {
  color: #6b6f80;
  font-size: 13px;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 120px;
}
.card {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border-radius: 14px;
  padding: 14px 16px;
}
.card.inactive {
  opacity: 0.65;
}
.avatar {
  width: 52px;
  height: 52px;
  flex-shrink: 0;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 28px;
}
.info {
  flex: 1;
  min-width: 0;
}
.name {
  font-weight: 600;
  display: flex;
  gap: 8px;
  align-items: center;
}
.meta {
  color: #6b6f80;
  font-size: 13px;
  margin-top: 4px;
}
.ops {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
@media (max-width: 767px) {
  .card {
    flex-wrap: wrap;
  }
  .ops {
    width: 100%;
    justify-content: flex-end;
  }
  .ops .el-button {
    min-height: 36px;
  }
}
</style>
