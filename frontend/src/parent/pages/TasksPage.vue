<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ApprovalRequest, ParentChild, ParentTask, TaskInstance } from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'

const { t } = useI18n()
const tab = ref<'tasks' | 'instances'>('tasks')
const tasks = ref<ParentTask[]>([])
const instances = ref<TaskInstance[]>([])
const children = ref<ParentChild[]>([])
const pendingApprovals = ref<ApprovalRequest[]>([])
const loading = ref(true)
const creating = ref(false)
const instanceDate = ref('')
const showCreate = ref(false)

const childMap = computed(() => new Map(children.value.map((c) => [c.id, c])))
const approvalByInstance = computed(() => {
  const m = new Map<number, ApprovalRequest>()
  for (const a of pendingApprovals.value) {
    if (a.subject_type === 'task_instance') m.set(a.subject_id, a)
  }
  return m
})

function taskStatusLabel(status: string): string {
  const key = `parent.tasks.status.${status}`
  return t(key)
}

const form = ref({
  title: '',
  child_id: undefined as number | undefined,
  xp_reward: 10,
  coin_reward: 5,
  requires_approval: true,
})

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

async function loadTasks(): Promise<void> {
  tasks.value = await api.get<ParentTask[]>('/parent/tasks')
}

async function loadInstances(): Promise<void> {
  if (!instanceDate.value) instanceDate.value = todayIso()
  const [inst, approvals] = await Promise.all([
    api.get<TaskInstance[]>('/parent/task-instances', { date: instanceDate.value }),
    api.get<ApprovalRequest[]>('/parent/approvals', { status: 'PENDING' }),
  ])
  instances.value = inst
  pendingApprovals.value = approvals
}

async function load(): Promise<void> {
  loading.value = true
  try {
    children.value = await api.get<ParentChild[]>('/parent/children')
    await Promise.all([loadTasks(), loadInstances()])
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
}

async function createTask(): Promise<void> {
  if (!form.value.title.trim()) {
    ElMessage.warning(t('common.fillTaskTitle'))
    return
  }
  creating.value = true
  try {
    const created = await api.post<ParentTask>('/parent/tasks', {
      title: form.value.title.trim(),
      kind: 'standard',
      start_date: todayIso(),
      xp_reward: form.value.xp_reward,
      coin_reward: form.value.coin_reward,
      requires_approval: form.value.requires_approval,
      schedules: [{ frequency: 'daily' }],
      child_id: form.value.child_id ?? null,
    })
    tasks.value.unshift(created)
    showCreate.value = false
    form.value.title = ''
    ElMessage.success(t('common.taskCreated'))
    await loadInstances()
  } catch (e) {
    reportError(e)
  } finally {
    creating.value = false
  }
}

async function approveInstance(inst: TaskInstance): Promise<void> {
  const approval = approvalByInstance.value.get(inst.id)
  if (!approval) {
    ElMessage.warning(t('common.approvalNotFound'))
    return
  }
  try {
    await api.postIdempotent(`/parent/task-instances/${inst.id}/approve`, { lock_version: approval.lock_version })
    ElMessage.success(t('growth.approved'))
    await loadInstances()
  } catch (e) {
    reportError(e)
  }
}

async function returnInstance(inst: TaskInstance): Promise<void> {
  const approval = approvalByInstance.value.get(inst.id)
  if (!approval) {
    ElMessage.warning(t('common.approvalNotFound'))
    return
  }
  try {
    await api.postIdempotent(`/parent/task-instances/${inst.id}/return`, {
      lock_version: approval.lock_version,
      note: t('common.returnNoteDefault'),
    })
    ElMessage.success(t('growth.returned'))
    await loadInstances()
  } catch (e) {
    reportError(e)
  }
}

function instanceTitle(inst: TaskInstance): string {
  const title = inst.snapshot?.title
  return typeof title === 'string' && title ? title : t('common.taskNumber', { id: inst.task_id })
}

function onTabChange(name: string | number): void {
  if (name === 'instances') void loadInstances()
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <el-tabs v-model="tab" @tab-change="onTabChange">
      <el-tab-pane :label="t('common.taskTemplates')" name="tasks">
        <div class="toolbar">
          <el-button type="primary" @click="showCreate = true">{{ t('common.createTask') }}</el-button>
        </div>
        <el-table :data="tasks" stripe :empty-text="t('common.noTasksYet')">
          <el-table-column prop="title" :label="t('common.title')" min-width="140" />
          <el-table-column :label="t('parent.dashboard.children')" width="100">
            <template #default="{ row }">
              {{ row.child_id ? childMap.get(row.child_id)?.nickname ?? row.child_id : t('common.wholeFamily') }}
            </template>
          </el-table-column>
          <el-table-column :label="t('growth.coins')" width="120">
            <template #default="{ row }">{{ row.xp_reward }} XP · {{ row.coin_reward }} {{ t('common.coinShort') }}</template>
          </el-table-column>
          <el-table-column :label="t('common.status')" width="80">
            <template #default="{ row }">
              <el-tag :type="row.requires_approval ? 'warning' : 'success'" size="small">
                {{ row.requires_approval ? t('common.needsApproval') : t('common.auto') }}
              </el-tag>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('common.todayInstances')" name="instances">
        <div class="toolbar">
          <el-date-picker v-model="instanceDate" type="date" value-format="YYYY-MM-DD" @change="loadInstances" />
          <el-button @click="loadInstances">{{ t('common.refresh') }}</el-button>
        </div>
        <div v-if="instances.length === 0" class="empty">{{ t('common.noInstancesOnDate') }}</div>
        <el-card v-for="inst in instances" :key="inst.id" shadow="never" class="inst">
          <div class="inst-head">
            <span v-if="childMap.get(inst.child_id)" class="av">{{ avatarEmoji(childMap.get(inst.child_id)!.avatar) }}</span>
            <div>
              <div class="inst-title">{{ instanceTitle(inst) }}</div>
              <div class="inst-meta">{{ childMap.get(inst.child_id)?.nickname ?? inst.child_id }} · {{ inst.task_date }}</div>
            </div>
            <el-tag size="small">{{ taskStatusLabel(inst.status) }}</el-tag>
          </div>
          <div v-if="inst.status === 'submitted'" class="inst-actions">
            <el-button type="success" size="small" @click="approveInstance(inst)">{{ t('common.approve') }}</el-button>
            <el-button type="warning" size="small" plain @click="returnInstance(inst)">{{ t('common.return') }}</el-button>
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <el-dialog v-model="showCreate" :title="t('common.createTask')" width="420px" destroy-on-close>
      <el-form label-width="88px">
        <el-form-item :label="t('common.title')" required>
          <el-input v-model="form.title" maxlength="128" />
        </el-form-item>
        <el-form-item :label="t('common.assignChild')">
          <el-select v-model="form.child_id" clearable :placeholder="t('common.allChildrenPlaceholder')">
            <el-option v-for="c in children.filter((x) => x.status === 'active')" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.xpLabel')">
          <el-input-number v-model="form.xp_reward" :min="0" />
        </el-form-item>
        <el-form-item :label="t('common.growthCoin')">
          <el-input-number v-model="form.coin_reward" :min="0" />
        </el-form-item>
        <el-form-item :label="t('common.needsApproval')">
          <el-switch v-model="form.requires_approval" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreate = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="creating" @click="createTask">{{ t('common.create') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page {
  max-width: 960px;
}
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.inst {
  margin-bottom: 10px;
  border-radius: 12px;
}
.inst-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.av {
  font-size: 28px;
}
.inst-title {
  font-weight: 600;
}
.inst-meta {
  font-size: 13px;
  color: #9a9db0;
}
.inst-actions {
  margin-top: 12px;
  display: flex;
  gap: 8px;
}
.empty {
  color: #9a9db0;
  padding: 24px 0;
  text-align: center;
}
</style>
