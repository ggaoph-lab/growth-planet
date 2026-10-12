<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type {
  ApprovalRequest,
  HomeworkConfirmResult,
  HomeworkItemPreview,
  HomeworkParseResult,
  HomeworkParserKind,
  HomeworkSubject,
  ParentChild,
  ParentTask,
  TaskInstance,
} from '@/shared/api/types'
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
const showHomework = ref(false)

const childMap = computed(() => new Map(children.value.map((c) => [c.id, c])))
const approvalByInstance = computed(() => {
  const m = new Map<number, ApprovalRequest>()
  for (const a of pendingApprovals.value) {
    if (a.subject_type === 'task_instance') m.set(a.subject_id, a)
  }
  return m
})

const subjects: HomeworkSubject[] = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他']

const form = ref({
  title: '',
  child_id: undefined as number | undefined,
  xp_reward: 10,
  coin_reward: 5,
  requires_approval: true,
})

const homeworkRaw = ref('')
const homeworkParsing = ref(false)
const homeworkConfirming = ref(false)
const homeworkPreview = ref<HomeworkParseResult | null>(null)
const homeworkChildId = ref<number | undefined>(undefined)
const homeworkProvider = ref<'auto' | 'gemini' | 'qwen' | 'rules'>('auto')
const homeworkDraft = ref({
  subject: '其他' as HomeworkSubject | string,
  task_date: '',
  title: '',
  items: [] as HomeworkItemPreview[],
  xp_reward: 0,
  coin_reward: 0,
  requires_approval: true,
  notes: null as string | null,
  parser: 'rules' as HomeworkParserKind | string,
  ai_configured: false,
  source_text: '',
})

function homeworkParserLabel(parser: string): string {
  if (parser === 'gemini') return t('common.homeworkParserGemini')
  if (parser === 'qwen') return t('common.homeworkParserQwen')
  return t('common.homeworkParserRules')
}

function homeworkAiHint(parser: string, aiConfigured: boolean): string {
  if (parser === 'gemini') return t('common.homeworkAiGeminiOn')
  if (parser === 'qwen') return t('common.homeworkAiQwenOn')
  if (aiConfigured) return t('common.homeworkAiOn')
  return t('common.homeworkAiOff')
}

const homeworkUsedAi = computed(
  () => homeworkDraft.value.parser === 'gemini' || homeworkDraft.value.parser === 'qwen',
)

watch(
  () => homeworkDraft.value.items,
  (items) => {
    homeworkDraft.value.xp_reward = items.reduce((sum, item) => sum + (item.suggested_xp || 0), 0)
    homeworkDraft.value.coin_reward = items.reduce((sum, item) => sum + (item.suggested_coins || 0), 0)
  },
  { deep: true },
)

function taskStatusLabel(status: string): string {
  const key = `parent.tasks.status.${status}`
  return t(key)
}

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

function openHomework(): void {
  homeworkRaw.value = ''
  homeworkPreview.value = null
  homeworkProvider.value = 'auto'
  homeworkChildId.value = children.value.find((c) => c.status === 'active')?.id
  homeworkDraft.value = {
    subject: '其他',
    task_date: instanceDate.value || todayIso(),
    title: '',
    items: [],
    xp_reward: 0,
    coin_reward: 0,
    requires_approval: true,
    notes: null,
    parser: 'rules',
    ai_configured: false,
    source_text: '',
  }
  showHomework.value = true
}

function applyPreview(preview: HomeworkParseResult): void {
  homeworkPreview.value = preview
  homeworkDraft.value = {
    subject: preview.subject,
    task_date: preview.task_date || todayIso(),
    title: preview.title,
    items: preview.items.map((item) => ({
      ...item,
      detail: item.detail ?? '',
    })),
    xp_reward: preview.xp_reward,
    coin_reward: preview.coin_reward,
    requires_approval: true,
    notes: preview.notes,
    parser: preview.parser,
    ai_configured: preview.ai_configured,
    source_text: preview.source_text,
  }
}

async function parseHomework(): Promise<void> {
  if (!homeworkRaw.value.trim()) {
    ElMessage.warning(t('common.homeworkPasteFirst'))
    return
  }
  homeworkParsing.value = true
  try {
    const preview = await api.post<HomeworkParseResult>('/parent/homework/parse', {
      text: homeworkRaw.value.trim(),
      task_date: homeworkDraft.value.task_date || todayIso(),
      provider: homeworkProvider.value,
    })
    applyPreview(preview)
  } catch (e) {
    reportError(e)
  } finally {
    homeworkParsing.value = false
  }
}

function addHomeworkItem(): void {
  homeworkDraft.value.items.push({
    title: '',
    detail: '',
    suggested_coins: 3,
    suggested_xp: 5,
  })
}

function removeHomeworkItem(index: number): void {
  homeworkDraft.value.items.splice(index, 1)
}

async function confirmHomework(): Promise<void> {
  if (!homeworkChildId.value) {
    ElMessage.warning(t('common.homeworkNeedChild'))
    return
  }
  const items = homeworkDraft.value.items
    .map((item) => ({
      title: item.title.trim(),
      detail: item.detail?.trim() ? item.detail.trim() : null,
      suggested_coins: item.suggested_coins,
      suggested_xp: item.suggested_xp,
    }))
    .filter((item) => item.title)
  if (items.length === 0) {
    ElMessage.warning(t('common.homeworkNeedItems'))
    return
  }
  if (!homeworkDraft.value.title.trim()) {
    ElMessage.warning(t('common.fillTaskTitle'))
    return
  }

  homeworkConfirming.value = true
  try {
    const result = await api.post<HomeworkConfirmResult>('/parent/homework/confirm', {
      child_id: homeworkChildId.value,
      subject: homeworkDraft.value.subject,
      task_date: homeworkDraft.value.task_date,
      title: homeworkDraft.value.title.trim(),
      items,
      xp_reward: homeworkDraft.value.xp_reward,
      coin_reward: homeworkDraft.value.coin_reward,
      requires_approval: homeworkDraft.value.requires_approval,
      source_text: homeworkDraft.value.source_text || homeworkRaw.value.trim(),
    })
    tasks.value.unshift(result.task)
    instanceDate.value = homeworkDraft.value.task_date
    tab.value = 'instances'
    showHomework.value = false
    ElMessage.success(t('common.homeworkImported'))
    await loadInstances()
  } catch (e) {
    reportError(e)
  } finally {
    homeworkConfirming.value = false
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
          <el-button @click="openHomework">{{ t('common.importHomework') }}</el-button>
        </div>
        <el-table :data="tasks" stripe :empty-text="t('common.noTasksYet')">
          <el-table-column prop="title" :label="t('common.title')" min-width="140" />
          <el-table-column :label="t('common.homeworkSubject')" width="88">
            <template #default="{ row }">{{ row.category || '—' }}</template>
          </el-table-column>
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
          <el-button @click="openHomework">{{ t('common.importHomework') }}</el-button>
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

    <el-dialog v-model="showHomework" :title="t('common.importHomework')" width="640px" destroy-on-close class="hw-dialog">
      <p class="hint">{{ t('common.homeworkPasteHint') }}</p>
      <el-form label-width="88px">
        <el-form-item :label="t('common.homeworkRawText')" required>
          <el-input
            v-model="homeworkRaw"
            type="textarea"
            :rows="6"
            maxlength="8000"
            show-word-limit
            :placeholder="t('common.homeworkRawPlaceholder')"
          />
        </el-form-item>
        <el-form-item :label="t('common.homeworkProvider')">
          <el-select v-model="homeworkProvider" style="width: 220px">
            <el-option :label="t('common.homeworkProviderAuto')" value="auto" />
            <el-option label="Gemini" value="gemini" />
            <el-option :label="t('common.homeworkParserQwen')" value="qwen" />
            <el-option :label="t('common.homeworkParserRules')" value="rules" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="homeworkParsing" @click="parseHomework">{{ t('common.homeworkParse') }}</el-button>
        </el-form-item>

        <template v-if="homeworkPreview">
          <el-alert
            class="hw-alert"
            :type="homeworkUsedAi ? 'success' : 'warning'"
            :closable="false"
            show-icon
          >
            <template #title>
              {{ homeworkParserLabel(homeworkDraft.parser) }}
              ·
              {{ homeworkAiHint(homeworkDraft.parser, homeworkDraft.ai_configured) }}
            </template>
            <div v-if="homeworkDraft.notes">{{ t('common.homeworkNotes') }}：{{ homeworkDraft.notes }}</div>
          </el-alert>

          <el-form-item :label="t('common.assignChild')" required>
            <el-select v-model="homeworkChildId" :placeholder="t('common.homeworkNeedChild')">
              <el-option
                v-for="c in children.filter((x) => x.status === 'active')"
                :key="c.id"
                :label="c.nickname"
                :value="c.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item :label="t('common.homeworkSubject')" required>
            <el-select v-model="homeworkDraft.subject">
              <el-option v-for="s in subjects" :key="s" :label="s" :value="s" />
            </el-select>
          </el-form-item>
          <el-form-item :label="t('common.title')" required>
            <el-input v-model="homeworkDraft.title" maxlength="128" />
          </el-form-item>
          <el-form-item :label="t('common.homeworkDate')">
            <el-date-picker v-model="homeworkDraft.task_date" type="date" value-format="YYYY-MM-DD" />
          </el-form-item>
          <el-form-item :label="t('common.xpLabel')">
            <el-input-number v-model="homeworkDraft.xp_reward" :min="0" :max="100" />
          </el-form-item>
          <el-form-item :label="t('common.growthCoin')">
            <el-input-number v-model="homeworkDraft.coin_reward" :min="0" :max="50" />
          </el-form-item>
          <el-form-item :label="t('common.needsApproval')">
            <el-switch v-model="homeworkDraft.requires_approval" />
          </el-form-item>

          <div class="hw-items-head">
            <strong>{{ t('common.homeworkItems') }}</strong>
            <el-button size="small" @click="addHomeworkItem">{{ t('common.homeworkAddItem') }}</el-button>
          </div>
          <div v-for="(item, index) in homeworkDraft.items" :key="index" class="hw-item">
            <el-input v-model="item.title" :placeholder="t('common.homeworkItemTitle')" maxlength="128" />
            <el-input v-model="item.detail" :placeholder="t('common.homeworkItemDetail')" maxlength="500" />
            <div class="hw-item-rewards">
              <span>XP</span>
              <el-input-number v-model="item.suggested_xp" :min="0" :max="100" size="small" />
              <span>{{ t('common.coinShort') }}</span>
              <el-input-number v-model="item.suggested_coins" :min="0" :max="50" size="small" />
              <el-button link type="danger" @click="removeHomeworkItem(index)">{{ t('common.homeworkRemoveItem') }}</el-button>
            </div>
          </div>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="showHomework = false">{{ t('common.cancel') }}</el-button>
        <el-button
          type="primary"
          :disabled="!homeworkPreview"
          :loading="homeworkConfirming"
          @click="confirmHomework"
        >
          {{ t('common.homeworkConfirm') }}
        </el-button>
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
.hint {
  margin: 0 0 12px;
  color: #6b6f85;
  font-size: 13px;
  line-height: 1.5;
}
.hw-alert {
  margin-bottom: 16px;
}
.hw-items-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 8px 0 10px;
}
.hw-item {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
  padding: 10px;
  border: 1px solid #eef0f5;
  border-radius: 10px;
}
.hw-item-rewards {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #6b6f85;
}
</style>
