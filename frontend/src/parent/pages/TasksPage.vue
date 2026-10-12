<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type {
  AiSettings,
  HomeworkConfirmResult,
  HomeworkItemPreview,
  HomeworkParseResult,
  HomeworkParserKind,
  HomeworkSubject,
  ParentChild,
  ParentTask,
  TaskInstance,
  TaskSchedule,
} from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'

const { t } = useI18n()
const tab = ref<'tasks' | 'instances'>('tasks')
const tasks = ref<ParentTask[]>([])
const instances = ref<TaskInstance[]>([])
const children = ref<ParentChild[]>([])
const loading = ref(true)
const saving = ref(false)
const instanceDate = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)
const showHomework = ref(false)

const childMap = computed(() => new Map(children.value.map((c) => [c.id, c])))

const subjects: HomeworkSubject[] = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他']

type TaskForm = {
  title: string
  child_id: number | undefined
  start_date: string
  due_time: string | null
  recurring: boolean
  end_date: string
  xp_reward: number
  coin_reward: number
  with_parent: boolean
  steps: { title: string }[]
  kind: ParentTask['kind']
}

const activeChildren = computed(() => children.value.filter((c) => c.status === 'active'))

function soleActiveChildId(): number | undefined {
  return activeChildren.value.length === 1 ? activeChildren.value[0]?.id : undefined
}

function blankForm(): TaskForm {
  return {
    title: '',
    child_id: soleActiveChildId(),
    start_date: todayIso(),
    due_time: '13:20',
    recurring: false,
    end_date: '',
    xp_reward: 10,
    coin_reward: 5,
    with_parent: false,
    steps: [],
    kind: 'standard',
  }
}

const form = ref<TaskForm>(blankForm())

const homeworkRaw = ref('')
const homeworkParsing = ref(false)
const homeworkConfirming = ref(false)
const homeworkPreview = ref<HomeworkParseResult | null>(null)
const homeworkChildId = ref<number | undefined>(undefined)
const homeworkWithParent = ref(false)
const homeworkProvider = ref<'auto' | 'gemini' | 'qwen' | 'rules'>('auto')
const homeworkDraft = ref({
  subject: '其他' as HomeworkSubject | string,
  task_date: '',
  title: '',
  items: [] as HomeworkItemPreview[],
  xp_reward: 0,
  coin_reward: 0,
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
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function dateOnly(value: string | null | undefined): string {
  if (!value) return ''
  return value.slice(0, 10)
}

function timeOnly(value: string | null | undefined): string {
  if (!value) return ''
  return value.slice(0, 5)
}

/** ISO weekday: 1=Mon … 7=Sun */
function isoWeekday(dateStr: string): number {
  const parts = dateStr.split('-').map((x) => Number(x))
  const y = parts[0] ?? 0
  const m = parts[1] ?? 1
  const d = parts[2] ?? 1
  const day = new Date(y, m - 1, d).getDay()
  return day === 0 ? 7 : day
}

function buildSchedules(f: TaskForm): TaskSchedule[] {
  const due = f.due_time || null
  if (f.recurring) {
    return [
      {
        frequency: 'weekly',
        weekdays: [isoWeekday(f.start_date)],
        due_time: due,
      },
    ]
  }
  return [
    {
      frequency: 'once',
      due_time: due,
    },
  ]
}

function scheduleSummary(task: ParentTask): string {
  const schedule = task.schedules?.[0]
  const time = timeOnly(schedule?.due_time ?? undefined)
  const timePart = time ? ` ${time}` : ''
  if (schedule?.frequency === 'weekly') {
    return `${t('common.scheduleWeekly')}${timePart}`
  }
  if (schedule?.frequency === 'daily') {
    return `${t('common.daily')}${timePart}`
  }
  return `${t('common.scheduleOnce')}${timePart}`
}

function taskDateLabel(task: ParentTask): string {
  const start = dateOnly(task.start_date)
  const end = dateOnly(task.end_date)
  if (end && end !== start) return `${start} → ${end}`
  return start || '—'
}

async function loadTasks(): Promise<void> {
  tasks.value = await api.get<ParentTask[]>('/parent/tasks')
}

async function loadInstances(): Promise<void> {
  if (!instanceDate.value) instanceDate.value = todayIso()
  instances.value = await api.get<TaskInstance[]>('/parent/task-instances', { date: instanceDate.value })
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

function openCreate(): void {
  editingId.value = null
  form.value = blankForm()
  showForm.value = true
}

function openEdit(task: ParentTask): void {
  const schedule = task.schedules?.[0]
  editingId.value = task.id
  form.value = {
    title: task.title,
    child_id: task.child_id ?? undefined,
    start_date: dateOnly(task.start_date) || todayIso(),
    due_time: timeOnly(schedule?.due_time ?? undefined) || '13:20',
    recurring: schedule?.frequency === 'weekly',
    end_date: dateOnly(task.end_date),
    xp_reward: task.xp_reward,
    coin_reward: task.coin_reward,
    with_parent: !!task.with_parent,
    steps: (task.steps ?? []).map((s) => ({ title: s.title })),
    kind: task.kind,
  }
  showForm.value = true
}

function validateForm(): boolean {
  if (!form.value.title.trim()) {
    ElMessage.warning(t('common.fillTaskTitle'))
    return false
  }
  if (!form.value.start_date) {
    ElMessage.warning(t('common.fillTaskDate'))
    return false
  }
  if (form.value.recurring && !form.value.end_date) {
    ElMessage.warning(t('common.fillTaskEndDate'))
    return false
  }
  return true
}

async function saveTask(): Promise<void> {
  if (!validateForm()) return
  saving.value = true
  try {
    const endDate = form.value.recurring ? form.value.end_date : form.value.start_date
    const payload = {
      title: form.value.title.trim(),
      kind: form.value.kind,
      start_date: form.value.start_date,
      end_date: endDate,
      xp_reward: form.value.xp_reward,
      coin_reward: form.value.coin_reward,
      requires_approval: false,
      with_parent: form.value.with_parent,
      schedules: buildSchedules(form.value),
      child_id: form.value.child_id ?? null,
      ...(form.value.kind === 'stepped'
        ? { steps: form.value.steps.filter((s) => s.title.trim()).map((s) => ({ title: s.title.trim() })) }
        : {}),
    }

    if (editingId.value) {
      const updated = await api.put<ParentTask>(`/parent/tasks/${editingId.value}`, payload)
      const idx = tasks.value.findIndex((x) => x.id === updated.id)
      if (idx >= 0) tasks.value[idx] = updated
      else tasks.value.unshift(updated)
      ElMessage.success(t('common.taskUpdated'))
    } else {
      const created = await api.post<ParentTask>('/parent/tasks', payload)
      tasks.value.unshift(created)
      ElMessage.success(t('common.taskCreated'))
    }
    showForm.value = false
    await loadInstances()
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

async function deleteTask(task: ParentTask): Promise<void> {
  try {
    await ElMessageBox.confirm(t('common.confirmDeleteTask', { title: task.title }), t('common.delete'), {
      type: 'warning',
      confirmButtonText: t('common.delete'),
      cancelButtonText: t('common.cancel'),
    })
  } catch {
    return
  }
  try {
    await api.delete(`/parent/tasks/${task.id}`)
    tasks.value = tasks.value.filter((x) => x.id !== task.id)
    ElMessage.success(t('common.taskDeleted'))
    await loadInstances()
  } catch (e) {
    reportError(e)
  }
}

function addStep(): void {
  form.value.steps.push({ title: '' })
}

function removeStep(index: number): void {
  form.value.steps.splice(index, 1)
}

async function openHomework(): Promise<void> {
  homeworkRaw.value = ''
  homeworkPreview.value = null
  homeworkWithParent.value = false
  homeworkProvider.value = 'auto'
  try {
    const settings = await api.get<AiSettings>('/parent/ai-settings')
    if (settings.provider === 'gemini' || settings.provider === 'qwen' || settings.provider === 'rules' || settings.provider === 'auto') {
      homeworkProvider.value = settings.provider
    }
  } catch {
    homeworkProvider.value = 'auto'
  }
  homeworkChildId.value = soleActiveChildId()
  homeworkDraft.value = {
    subject: '其他',
    task_date: instanceDate.value || todayIso(),
    title: '',
    items: [],
    xp_reward: 0,
    coin_reward: 0,
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
      requires_approval: false,
      with_parent: homeworkWithParent.value,
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

function instanceTitle(inst: TaskInstance): string {
  const title = inst.snapshot?.title
  return typeof title === 'string' && title ? title : t('common.taskNumber', { id: inst.task_id })
}

function instanceDueTime(inst: TaskInstance): string {
  const due = inst.snapshot?.due_time
  return typeof due === 'string' && due ? timeOnly(due) : ''
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
          <el-button type="primary" @click="openCreate">{{ t('common.createTask') }}</el-button>
          <el-button @click="openHomework">{{ t('common.importHomework') }}</el-button>
        </div>
        <el-table :data="tasks" stripe :empty-text="t('common.noTasksYet')">
          <el-table-column prop="title" :label="t('common.title')" min-width="140" />
          <el-table-column :label="t('common.homeworkSubject')" width="88">
            <template #default="{ row }">{{ row.category || '—' }}</template>
          </el-table-column>
          <el-table-column :label="t('common.taskDate')" min-width="160">
            <template #default="{ row }">
              <div>{{ taskDateLabel(row as ParentTask) }}</div>
              <div class="muted">{{ scheduleSummary(row as ParentTask) }}</div>
            </template>
          </el-table-column>
          <el-table-column :label="t('parent.dashboard.children')" width="120">
            <template #default="{ row }">
              <div>
                {{
                  (row as ParentTask).child_id
                    ? childMap.get((row as ParentTask).child_id!)?.nickname ?? (row as ParentTask).child_id
                    : t('common.wholeFamily')
                }}
              </div>
              <el-tag v-if="(row as ParentTask).with_parent" size="small" type="warning" class="tag-inline">
                {{ t('common.withParentTag') }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('growth.coins')" width="120">
            <template #default="{ row }">
              {{ (row as ParentTask).xp_reward }} XP · {{ (row as ParentTask).coin_reward }} {{ t('common.coinShort') }}
            </template>
          </el-table-column>
          <el-table-column :label="t('common.actions')" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEdit(row as ParentTask)">{{ t('common.edit') }}</el-button>
              <el-button link type="danger" @click="deleteTask(row as ParentTask)">{{ t('common.delete') }}</el-button>
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
              <div class="inst-meta">
                {{ childMap.get(inst.child_id)?.nickname ?? inst.child_id }} · {{ inst.task_date }}
                <template v-if="instanceDueTime(inst)"> · {{ instanceDueTime(inst) }}</template>
              </div>
            </div>
            <div class="inst-tags">
              <el-tag v-if="inst.snapshot?.with_parent" size="small" type="warning">{{ t('common.withParentTag') }}</el-tag>
              <el-tag size="small">{{ taskStatusLabel(inst.status) }}</el-tag>
            </div>
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <el-dialog
      v-model="showForm"
      :title="editingId ? t('common.editTask') : t('common.createTask')"
      width="480px"
      destroy-on-close
    >
      <el-form label-width="100px">
        <el-form-item :label="t('common.title')" required>
          <el-input v-model="form.title" maxlength="128" />
        </el-form-item>
        <el-form-item :label="t('common.assignChild')">
          <el-select v-model="form.child_id" clearable :placeholder="t('common.allChildrenPlaceholder')">
            <el-option v-for="c in activeChildren" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.withParent')">
          <el-switch v-model="form.with_parent" />
          <div class="hint-inline">{{ t('common.withParentHint') }}</div>
        </el-form-item>
        <el-form-item :label="t('common.taskStartDate')" required>
          <el-date-picker v-model="form.start_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('common.taskTime')">
          <el-time-picker
            v-model="form.due_time"
            format="HH:mm"
            value-format="HH:mm"
            clearable
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('common.recurringTask')">
          <el-switch v-model="form.recurring" />
          <div class="hint-inline">{{ t('common.recurringTaskHint') }}</div>
        </el-form-item>
        <el-form-item v-if="form.recurring" :label="t('common.taskEndDate')" required>
          <el-date-picker
            v-model="form.end_date"
            type="date"
            value-format="YYYY-MM-DD"
            :disabled-date="(d: Date) => form.start_date ? d < new Date(form.start_date + 'T00:00:00') : false"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('common.xpLabel')">
          <el-input-number v-model="form.xp_reward" :min="0" />
        </el-form-item>
        <el-form-item :label="t('common.growthCoin')">
          <el-input-number v-model="form.coin_reward" :min="0" />
        </el-form-item>

        <template v-if="form.kind === 'stepped'">
          <div class="hw-items-head">
            <strong>{{ t('common.homeworkItems') }}</strong>
            <el-button size="small" @click="addStep">{{ t('common.homeworkAddItem') }}</el-button>
          </div>
          <div v-for="(step, index) in form.steps" :key="index" class="hw-item">
            <el-input v-model="step.title" :placeholder="t('common.homeworkItemTitle')" maxlength="128" />
            <el-button link type="danger" @click="removeStep(index)">{{ t('common.homeworkRemoveItem') }}</el-button>
          </div>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="showForm = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="saveTask">
          {{ editingId ? t('common.save') : t('common.create') }}
        </el-button>
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
          <div class="hint-inline">{{ t('common.homeworkProviderHint') }}</div>
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
                v-for="c in activeChildren"
                :key="c.id"
                :label="c.nickname"
                :value="c.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item :label="t('common.withParent')">
            <el-switch v-model="homeworkWithParent" />
            <div class="hint-inline">{{ t('common.withParentHint') }}</div>
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
  max-width: 1080px;
}
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.muted {
  font-size: 12px;
  color: #9a9db0;
  margin-top: 2px;
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
.inst-tags {
  margin-left: auto;
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}
.tag-inline {
  margin-top: 4px;
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
.hint-inline {
  margin-top: 6px;
  color: #6b6f85;
  font-size: 12px;
  line-height: 1.4;
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
