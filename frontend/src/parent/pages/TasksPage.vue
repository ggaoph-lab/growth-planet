<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
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
  TaskCategory,
  TaskInstance,
  TaskSchedule,
} from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'
import { useIsPhone } from '@/shared/useMediaQuery'

const { t, locale } = useI18n()
const isPhone = useIsPhone()
const taskFormLabelWidth = computed(() =>
  String(locale.value).toLowerCase().startsWith('en') ? '188px' : '148px',
)
const tab = ref<'tasks' | 'instances'>('tasks')
const tasks = ref<ParentTask[]>([])
const categories = ref<TaskCategory[]>([])
const instances = ref<TaskInstance[]>([])
const children = ref<ParentChild[]>([])
const categoryFilterId = ref<number | null>(null)
const detailTask = ref<ParentTask | null>(null)
const openInstanceGroups = ref<Record<string, boolean>>({})
const loading = ref(true)
const saving = ref(false)
const instanceDate = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)
const showHomework = ref(false)
const showEditScope = ref(false)
const editScopeDate = ref('')
const pendingSavePayload = ref<Record<string, unknown> | null>(null)

const childMap = computed(() => new Map(children.value.map((c) => [c.id, c])))

const subjects: HomeworkSubject[] = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他']

type SubtaskForm = {
  id?: number
  title: string
  description: string
  start_date: string
  due_time: string | null
  recurring: boolean
  /** ISO weekdays 1=Mon … 7=Sun when recurring */
  weekdays: number[]
  end_date: string
  xp_reward: number
  coin_reward: number
  point_reward: number
  with_parent: boolean
}

type EditScope = 'this_day' | 'weekday_forward'

const WEEKDAY_OPTIONS = [1, 2, 3, 4, 5, 6, 7] as const

type TaskForm = {
  title: string
  child_id: number | undefined
  category_id: number | null
  steps: { title: string }[]
  subtasks: SubtaskForm[]
  kind: ParentTask['kind']
}

const activeChildren = computed(() => children.value.filter((c) => c.status === 'active'))

function soleActiveChildId(): number | undefined {
  return activeChildren.value.length === 1 ? activeChildren.value[0]?.id : undefined
}

function blankSubtask(): SubtaskForm {
  const start = todayIso()
  return {
    title: '',
    description: '',
    start_date: start,
    due_time: '13:20',
    recurring: false,
    weekdays: [isoWeekday(start)],
    end_date: '',
    xp_reward: 10,
    coin_reward: 5,
    point_reward: 0,
    with_parent: false,
  }
}

function blankForm(): TaskForm {
  return {
    title: '',
    child_id: soleActiveChildId(),
    steps: [],
    subtasks: [blankSubtask()],
    category_id: null,
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
const homeworkProvider = ref<'auto' | 'gemini' | 'qwen' | 'openai' | 'rules'>('auto')
const homeworkDraft = ref({
  subject: '其他' as HomeworkSubject | string,
  task_date: '',
  title: '',
  items: [] as HomeworkItemPreview[],
  xp_reward: 0,
  coin_reward: 0,
  point_reward: 0,
  notes: null as string | null,
  parser: 'rules' as HomeworkParserKind | string,
  ai_configured: false,
  source_text: '',
})

function homeworkParserLabel(parser: string): string {
  if (parser === 'gemini') return t('common.homeworkParserGemini')
  if (parser === 'qwen') return t('common.homeworkParserQwen')
  if (parser === 'openai') return 'ChatGPT'
  return t('common.homeworkParserRules')
}

function homeworkAiHint(parser: string, aiConfigured: boolean): string {
  if (parser === 'gemini') return t('common.homeworkAiGeminiOn')
  if (parser === 'qwen') return t('common.homeworkAiQwenOn')
  if (parser === 'openai') return 'ChatGPT'
  if (aiConfigured) return t('common.homeworkAiOn')
  return t('common.homeworkAiOff')
}

const homeworkUsedAi = computed(
  () => homeworkDraft.value.parser === 'gemini' || homeworkDraft.value.parser === 'qwen' || homeworkDraft.value.parser === 'openai',
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

function weekdayLabel(day: number): string {
  return t(`common.weekdayShort.${day}`)
}

function normalizeWeekdays(raw: number[] | null | undefined, fallbackDate: string): number[] {
  const fromApi = (raw ?? []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7)
  const unique = [...new Set(fromApi)].sort((a, b) => a - b)
  if (unique.length > 0) return unique
  return [isoWeekday(fallbackDate || todayIso())]
}

function onSubtaskRecurringChange(sub: SubtaskForm, enabled: boolean): void {
  sub.recurring = enabled
  if (enabled && sub.weekdays.length === 0) {
    sub.weekdays = [isoWeekday(sub.start_date || todayIso())]
  }
}

function onSubtaskStartDateChange(sub: SubtaskForm, value: string | null): void {
  const next = value || todayIso()
  const prev = sub.start_date
  sub.start_date = next
  if (!sub.recurring) return
  // If the parent only had the previous start weekday selected, follow the new start date.
  if (sub.weekdays.length === 1 && sub.weekdays[0] === isoWeekday(prev)) {
    sub.weekdays = [isoWeekday(next)]
  }
}

function buildSubtaskSchedules(sub: SubtaskForm): TaskSchedule[] {
  const due = sub.due_time || null
  if (sub.recurring) {
    return [
      {
        frequency: 'weekly',
        weekdays: normalizeWeekdays(sub.weekdays, sub.start_date),
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

function subtaskFromTask(task: ParentTask): SubtaskForm {
  const schedule = task.schedules?.[0]
  const start = dateOnly(task.start_date) || todayIso()
  const recurring = schedule?.frequency === 'weekly'
  return {
    id: task.id,
    title: task.title,
    description: task.description ?? '',
    start_date: start,
    due_time: timeOnly(schedule?.due_time ?? undefined) || null,
    recurring,
    weekdays: normalizeWeekdays(schedule?.weekdays ?? null, start),
    end_date: dateOnly(task.end_date),
    xp_reward: task.xp_reward,
    coin_reward: task.coin_reward,
    point_reward: task.point_reward ?? 0,
    with_parent: !!task.with_parent,
  }
}

function rewardXp(task: ParentTask): number {
  const kids = task.children ?? []
  if (kids.length > 0) return kids.reduce((sum, child) => sum + child.xp_reward, 0)
  return task.xp_reward
}

function rewardPoints(task: ParentTask): number {
  const kids = task.children ?? []
  if (kids.length > 0) return kids.reduce((sum, child) => sum + (child.point_reward ?? 0), 0)
  return task.point_reward ?? 0
}

function rewardCoins(task: ParentTask): number {
  const kids = task.children ?? []
  if (kids.length > 0) return kids.reduce((sum, child) => sum + child.coin_reward, 0)
  return task.coin_reward
}

function rewardSummary(task: ParentTask): string {
  return `${rewardXp(task)} ${t('common.xpLabel')} · ${rewardPoints(task)} ${t('common.pointsLabel')} · ${rewardCoins(task)} ${t('common.coinShort')}`
}

function subtaskTimes(task: ParentTask): string[] {
  const times = (task.children ?? [])
    .map((child) => timeOnly(child.schedules?.[0]?.due_time ?? undefined))
    .filter((time) => time.length > 0)
  return [...new Set(times)].sort()
}

function scheduleSummary(task: ParentTask): string {
  const children = task.children ?? []
  if (children.length > 0) {
    const times = subtaskTimes(task)
    const timePart = times.length ? ` ${times.join('、')}` : ''
    return `${t('common.subtaskCount', { n: children.length })}${timePart}`
  }
  const schedule = task.schedules?.[0]
  const time = timeOnly(schedule?.due_time ?? undefined)
  const timePart = time ? ` ${time}` : ''
  if (schedule?.frequency === 'weekly') {
    const days = normalizeWeekdays(schedule.weekdays ?? null, dateOnly(task.start_date) || todayIso())
      .map((d) => weekdayLabel(d))
      .join(locale.value.toString().toLowerCase().startsWith('zh') ? '、' : ', ')
    return `${t('common.scheduleWeekly')} ${days}${timePart}`
  }
  if (schedule?.frequency === 'daily') {
    return `${t('common.daily')}${timePart}`
  }
  return `${t('common.scheduleOnce')}${timePart}`
}

function detailSubtasks(task: ParentTask): ParentTask[] {
  return [...(task.children ?? [])].sort((a, b) => {
    const left = timeOnly(a.schedules?.[0]?.due_time ?? undefined) || '99:99'
    const right = timeOnly(b.schedules?.[0]?.due_time ?? undefined) || '99:99'
    return left.localeCompare(right) || a.id - b.id
  })
}

function taskDateLabel(task: ParentTask): string {
  const start = dateOnly(task.start_date)
  const end = dateOnly(task.end_date)
  if (end && end !== start) return `${start} → ${end}`
  return start || '—'
}

const visibleTasks = computed(() => {
  if (categoryFilterId.value == null) return tasks.value
  return tasks.value.filter((task) => task.category_id === categoryFilterId.value)
})

const activeCategoryName = computed(() => {
  if (categoryFilterId.value == null) return ''
  return (
    categories.value.find((category) => category.id === categoryFilterId.value)?.name ??
    tasks.value.find((task) => task.category_id === categoryFilterId.value)?.task_category?.name ??
    ''
  )
})

interface InstanceGroup {
  key: string
  title: string
  folded: boolean
  items: TaskInstance[]
}

const instanceGroups = computed<InstanceGroup[]>(() => {
  const groups: InstanceGroup[] = []
  const index = new Map<string, InstanceGroup>()
  for (const inst of instances.value) {
    const groupId = groupIdOf(inst)
    if (groupId !== null) {
      const key = `g-${groupId}`
      let group = index.get(key)
      if (!group) {
        const title = inst.snapshot?.group_title
        group = {
          key,
          title: typeof title === 'string' && title ? title : t('common.taskDefault'),
          folded: true,
          items: [],
        }
        index.set(key, group)
        groups.push(group)
      }
      group.items.push(inst)
    } else {
      groups.push({ key: `i-${inst.id}`, title: '', folded: false, items: [inst] })
    }
  }
  for (const group of groups) {
    if (!group.folded) continue
    group.items.sort((a, b) => instanceDueTime(a).localeCompare(instanceDueTime(b)))
  }
  return groups
})

function toggleCategory(task: ParentTask): void {
  const id = task.category_id
  if (id == null) return
  categoryFilterId.value = categoryFilterId.value === id ? null : id
}

function openDetail(task: ParentTask): void {
  detailTask.value = task
}

function editFromDetail(): void {
  const task = detailTask.value
  if (!task) return
  detailTask.value = null
  openEdit(task)
}

function isInstanceGroupOpen(key: string): boolean {
  return openInstanceGroups.value[key] ?? false
}

function toggleInstanceGroup(key: string): void {
  openInstanceGroups.value = { ...openInstanceGroups.value, [key]: !isInstanceGroupOpen(key) }
}

async function loadTasks(): Promise<void> {
  tasks.value = await api.get<ParentTask[]>('/parent/tasks')
}

async function loadCategories(): Promise<void> {
  categories.value = await api.get<TaskCategory[]>('/parent/task-categories')
}

async function loadInstances(): Promise<void> {
  if (!instanceDate.value) instanceDate.value = todayIso()
  instances.value = await api.get<TaskInstance[]>('/parent/task-instances', { date: instanceDate.value })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    children.value = await api.get<ParentChild[]>('/parent/children')
    await Promise.all([loadTasks(), loadCategories(), loadInstances()])
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
  editingId.value = task.id
  const childSubs = task.children ?? []
  form.value = {
    title: task.title,
    child_id: task.child_id ?? undefined,
    steps: (task.steps ?? []).map((s) => ({ title: s.title })),
    subtasks: childSubs.length > 0 ? childSubs.map(subtaskFromTask) : [subtaskFromTask(task)],
    category_id: task.category_id ?? null,
    kind: task.kind,
  }
  // Leaf tasks become a group title + one subtask on save; drop the leaf id from the only subtask.
  if (childSubs.length === 0 && form.value.subtasks[0]) {
    delete form.value.subtasks[0].id
  }
  showForm.value = true
}

function validateForm(): boolean {
  if (!form.value.title.trim()) {
    ElMessage.warning(t('common.fillTaskTitle'))
    return false
  }
  if (form.value.subtasks.length === 0) {
    ElMessage.warning(t('common.fillSubtaskTitle'))
    return false
  }
  if (form.value.subtasks.some((sub) => !sub.title.trim())) {
    ElMessage.warning(t('common.fillSubtaskTitle'))
    return false
  }
  if (form.value.subtasks.some((sub) => !sub.start_date)) {
    ElMessage.warning(t('common.fillTaskDate'))
    return false
  }
  if (form.value.subtasks.some((sub) => sub.recurring && !sub.end_date)) {
    ElMessage.warning(t('common.fillTaskEndDate'))
    return false
  }
  if (form.value.subtasks.some((sub) => sub.recurring && sub.weekdays.length === 0)) {
    ElMessage.warning(t('common.fillTaskWeekdays'))
    return false
  }
  return true
}

function buildSavePayload(scopeFields: { edit_scope?: EditScope; scope_date?: string } = {}): Record<string, unknown> {
  const subs = form.value.subtasks
  const startDates = subs.map((sub) => sub.start_date).filter(Boolean).sort()
  const endDates = subs
    .map((sub) => (sub.recurring ? sub.end_date : sub.start_date))
    .filter(Boolean)
    .sort()
  return {
    title: form.value.title.trim(),
    kind: form.value.kind,
    start_date: startDates[0] ?? todayIso(),
    end_date: endDates[endDates.length - 1] ?? startDates[0] ?? todayIso(),
    xp_reward: 0,
    coin_reward: 0,
    point_reward: 0,
    with_parent: false,
    schedules: [{ frequency: 'once' as const }],
    child_id: form.value.child_id ?? null,
    category_id: form.value.category_id ?? null,
    subtasks: subs.map((sub) => ({
      ...(sub.id ? { id: sub.id } : {}),
      title: sub.title.trim(),
      description: sub.description.trim() || null,
      start_date: sub.start_date,
      end_date: sub.recurring ? sub.end_date : sub.start_date,
      xp_reward: sub.xp_reward,
      coin_reward: sub.coin_reward,
      point_reward: sub.point_reward,
      with_parent: sub.with_parent,
      schedules: buildSubtaskSchedules(sub),
    })),
    ...(form.value.kind === 'stepped'
      ? { steps: form.value.steps.filter((s) => s.title.trim()).map((s) => ({ title: s.title.trim() })) }
      : {}),
    ...scopeFields,
  }
}

async function commitSave(payload: Record<string, unknown>): Promise<void> {
  saving.value = true
  try {
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
    showEditScope.value = false
    pendingSavePayload.value = null
    await loadInstances()
  } catch (e) {
    reportError(e)
  } finally {
    saving.value = false
  }
}

async function saveTask(): Promise<void> {
  if (!validateForm()) return

  const hasRecurring = form.value.subtasks.some((sub) => sub.recurring)
  if (editingId.value && hasRecurring) {
    pendingSavePayload.value = buildSavePayload()
    editScopeDate.value = todayIso()
    showEditScope.value = true
    return
  }

  await commitSave(buildSavePayload())
}

async function confirmEditScope(scope: EditScope): Promise<void> {
  const base = pendingSavePayload.value
  if (!base) return
  await commitSave({
    ...base,
    edit_scope: scope,
    scope_date: editScopeDate.value || todayIso(),
  })
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

function addSubtask(): void {
  form.value.subtasks.push(blankSubtask())
}

function removeSubtask(index: number): void {
  form.value.subtasks.splice(index, 1)
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
    if (settings.provider === 'gemini' || settings.provider === 'qwen' || settings.provider === 'openai' || settings.provider === 'rules' || settings.provider === 'auto') {
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
    point_reward: 0,
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
    point_reward: preview.point_reward ?? 0,
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
      point_reward: homeworkDraft.value.point_reward,
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

function groupIdOf(inst: TaskInstance): number | null {
  const raw = inst.snapshot?.group_task_id
  const id = typeof raw === 'number' ? raw : typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : NaN
  return Number.isInteger(id) && id > 0 ? id : null
}

function linesOf(inst: TaskInstance): { key: string; title: string }[] {
  const related = inst.instance_steps
  if (Array.isArray(related) && related.length > 0) {
    return [...related]
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .filter((step) => step.title)
      .map((step) => ({ key: `r-${step.id}`, title: step.title }))
  }
  const steps = inst.snapshot?.steps
  if (!Array.isArray(steps)) return []
  return steps
    .filter((step) => typeof step?.title === 'string' && step.title.length > 0)
    .map((step, index) => ({ key: `s-${step.id ?? index}`, title: step.title }))
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
          <RouterLink to="/parent/family-reminders"><el-button>语音提醒</el-button></RouterLink>
          <el-button type="primary" @click="openCreate">{{ t('common.createTask') }}</el-button>
          <el-button @click="openHomework">{{ t('common.importHomework') }}</el-button>
          <el-tag v-if="activeCategoryName" closable @close="categoryFilterId = null">
            {{ t('common.filterByCategory', { name: activeCategoryName }) }}
          </el-tag>
        </div>
        <el-table v-if="!isPhone" :data="visibleTasks" stripe :empty-text="t('common.noTasksYet')">
          <el-table-column :label="t('common.title')" min-width="160">
            <template #default="{ row }">
              <el-button link type="primary" @click="openDetail(row as ParentTask)">
                {{ (row as ParentTask).title }}
              </el-button>
              <div v-if="(row as ParentTask).children?.length" class="muted">
                {{ t('common.subtaskCount', { n: (row as ParentTask).children!.length }) }}
              </div>
            </template>
          </el-table-column>
          <el-table-column :label="t('common.taskCategory')" width="110">
            <template #default="{ row }">
              <el-button
                v-if="(row as ParentTask).task_category"
                link
                :type="categoryFilterId === (row as ParentTask).category_id ? 'primary' : 'info'"
                @click="toggleCategory(row as ParentTask)"
              >
                {{ (row as ParentTask).task_category!.name }}
              </el-button>
              <span v-else>—</span>
            </template>
          </el-table-column>
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
              <el-tag
                v-if="
                  (row as ParentTask).with_parent ||
                  (row as ParentTask).children?.some((child) => child.with_parent)
                "
                size="small"
                type="warning"
                class="tag-inline"
              >
                {{ t('common.withParentTag') }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('common.xpLabel')" width="72" align="center">
            <template #default="{ row }">{{ rewardXp(row as ParentTask) }}</template>
          </el-table-column>
          <el-table-column :label="t('common.pointsLabel')" width="72" align="center">
            <template #default="{ row }">{{ rewardPoints(row as ParentTask) }}</template>
          </el-table-column>
          <el-table-column :label="t('common.growthCoin')" width="88" align="center">
            <template #default="{ row }">{{ rewardCoins(row as ParentTask) }}</template>
          </el-table-column>
          <el-table-column :label="t('common.actions')" width="140" align="center">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEdit(row as ParentTask)">{{ t('common.edit') }}</el-button>
              <el-button link type="danger" @click="deleteTask(row as ParentTask)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="visibleTasks.length === 0" :description="t('common.noTasksYet')" />
          <article v-for="row in visibleTasks" :key="row.id" class="phone-card">
            <div class="phone-info">
              <button type="button" class="phone-title-btn" @click="openDetail(row)">{{ row.title }}</button>
              <div v-if="row.children?.length" class="muted">{{ t('common.subtaskCount', { n: row.children.length }) }}</div>
              <div class="phone-meta">
                {{
                  row.child_id
                    ? childMap.get(row.child_id)?.nickname ?? row.child_id
                    : t('common.wholeFamily')
                }}
                · {{ taskDateLabel(row) }}
              </div>
              <div class="muted">{{ scheduleSummary(row) }} · {{ rewardSummary(row) }}</div>
              <el-tag
                v-if="row.with_parent || row.children?.some((child) => child.with_parent)"
                size="small"
                type="warning"
                class="tag-inline"
              >
                {{ t('common.withParentTag') }}
              </el-tag>
            </div>
            <div class="phone-ops">
              <el-button size="small" @click="openEdit(row)">{{ t('common.edit') }}</el-button>
              <el-button size="small" type="danger" @click="deleteTask(row)">{{ t('common.delete') }}</el-button>
            </div>
          </article>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('common.todayInstances')" name="instances">
        <div class="toolbar">
          <el-date-picker v-model="instanceDate" type="date" value-format="YYYY-MM-DD" @change="loadInstances" />
          <el-button @click="loadInstances">{{ t('common.refresh') }}</el-button>
          <el-button @click="openHomework">{{ t('common.importHomework') }}</el-button>
        </div>
        <div v-if="instances.length === 0" class="empty">{{ t('common.noInstancesOnDate') }}</div>
        <el-card v-for="group in instanceGroups" :key="group.key" shadow="never" class="inst">
          <button
            v-if="group.folded"
            type="button"
            class="group-toggle"
            @click="toggleInstanceGroup(group.key)"
          >
            <span class="inst-title">{{ group.title }}</span>
            <span class="inst-meta">{{ t('common.subtaskCount', { n: group.items.length }) }}</span>
            <span class="group-action">
              {{ isInstanceGroupOpen(group.key) ? t('common.hideDetail') : t('common.viewDetail') }}
            </span>
          </button>
          <div v-if="!group.folded || isInstanceGroupOpen(group.key)" :class="{ 'group-items': group.folded }">
            <div v-for="inst in group.items" :key="inst.id" class="inst-head" :class="{ nested: group.folded }">
              <span v-if="childMap.get(inst.child_id)" class="av">{{ avatarEmoji(childMap.get(inst.child_id)!.avatar) }}</span>
              <div>
                <div class="inst-title">{{ instanceTitle(inst) }}</div>
                <ul v-if="linesOf(inst).length" class="step-list">
                  <li v-for="line in linesOf(inst)" :key="line.key">{{ line.title }}</li>
                </ul>
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
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <el-dialog
      v-model="showForm"
      :title="editingId ? t('common.editTask') : t('common.createTask')"
      width="640px"
      destroy-on-close
      class="task-form-dialog"
    >
      <el-input
        v-model="form.title"
        class="title-box"
        size="large"
        maxlength="128"
        :placeholder="t('common.title')"
      />
      <el-form :label-width="taskFormLabelWidth" class="task-meta-form">
        <el-form-item :label="t('common.taskCategory')">
          <el-select
            v-model="form.category_id"
            clearable
            :placeholder="t('common.taskCategoryPlaceholder')"
            style="width: 100%"
          >
            <el-option v-for="category in categories" :key="category.id" :label="category.name" :value="category.id" />
          </el-select>
          <div class="hint-inline">
            <RouterLink class="settings-link" :to="{ name: 'parent-family', query: { tab: 'categories' } }">
              {{ t('common.manageCategories') }}
            </RouterLink>
          </div>
        </el-form-item>
        <el-form-item :label="t('common.assignChild')">
          <el-select v-model="form.child_id" clearable :placeholder="t('common.allChildrenPlaceholder')" style="width: 100%">
            <el-option v-for="c in activeChildren" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
        </el-form-item>
      </el-form>

      <div class="hw-items-head">
        <strong>{{ t('common.subtasks') }}</strong>
        <el-button size="small" @click="addSubtask">{{ t('common.addSubtask') }}</el-button>
      </div>
      <p class="hint">{{ t('common.subtaskHint') }}</p>
      <div v-for="(sub, index) in form.subtasks" :key="sub.id ?? `new-${index}`" class="subtask-card">
        <div class="subtask-card-head">
          <el-input v-model="sub.title" :placeholder="t('common.subtaskTitle')" maxlength="128" />
          <el-button
            link
            type="danger"
            :disabled="form.subtasks.length <= 1"
            @click="removeSubtask(index)"
          >
            {{ t('common.delete') }}
          </el-button>
        </div>
        <el-input
          v-model="sub.description"
          class="subtask-desc"
          type="textarea"
          :rows="2"
          maxlength="2000"
          :placeholder="t('common.subtaskDescription')"
        />
        <el-form :label-width="taskFormLabelWidth" class="subtask-fields">
          <el-form-item :label="t('common.withParent')">
            <div class="field-inline">
              <el-switch v-model="sub.with_parent" />
              <span class="hint-inline">{{ t('common.withParentHint') }}</span>
            </div>
          </el-form-item>
          <el-form-item :label="t('common.taskStartDate')" required>
            <el-date-picker
              :model-value="sub.start_date"
              type="date"
              value-format="YYYY-MM-DD"
              style="width: 100%"
              @update:model-value="(v: string | null) => onSubtaskStartDateChange(sub, v)"
            />
          </el-form-item>
          <el-form-item :label="t('common.taskTime')">
            <el-time-picker
              v-model="sub.due_time"
              format="HH:mm"
              value-format="HH:mm"
              clearable
              style="width: 100%"
            />
          </el-form-item>
          <el-form-item :label="t('common.recurringTask')">
            <div class="field-inline">
              <el-switch
                :model-value="sub.recurring"
                @update:model-value="(v: string | number | boolean) => onSubtaskRecurringChange(sub, Boolean(v))"
              />
              <span class="hint-inline">{{ t('common.recurringTaskHint') }}</span>
            </div>
          </el-form-item>
          <el-form-item v-if="sub.recurring" :label="t('common.taskWeekdays')" required>
            <el-checkbox-group v-model="sub.weekdays" class="weekday-group">
              <el-checkbox v-for="day in WEEKDAY_OPTIONS" :key="day" :value="day">
                {{ weekdayLabel(day) }}
              </el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item v-if="sub.recurring" :label="t('common.taskEndDate')" required>
            <el-date-picker
              v-model="sub.end_date"
              type="date"
              value-format="YYYY-MM-DD"
              :disabled-date="(d: Date) => sub.start_date ? d < new Date(sub.start_date + 'T00:00:00') : false"
              style="width: 100%"
            />
          </el-form-item>
          <el-form-item :label="t('common.xpLabel')">
            <el-input-number v-model="sub.xp_reward" :min="0" />
          </el-form-item>
          <el-form-item :label="t('common.pointsLabel')">
            <el-input-number v-model="sub.point_reward" :min="0" />
          </el-form-item>
          <el-form-item :label="t('common.growthCoin')">
            <el-input-number v-model="sub.coin_reward" :min="0" />
          </el-form-item>
        </el-form>
      </div>

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
      <template #footer>
        <el-button @click="showForm = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="saveTask">
          {{ editingId ? t('common.save') : t('common.create') }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="showEditScope"
      :title="t('common.recurringEditScopeTitle')"
      width="440px"
      @closed="pendingSavePayload = null"
    >
      <p class="edit-scope-hint">{{ t('common.recurringEditScopeHint') }}</p>
      <el-form label-width="120px">
        <el-form-item :label="t('common.recurringScopeDate')" required>
          <el-date-picker
            v-model="editScopeDate"
            type="date"
            value-format="YYYY-MM-DD"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showEditScope = false">{{ t('common.cancel') }}</el-button>
        <el-button :loading="saving" @click="confirmEditScope('this_day')">
          {{ t('common.recurringEditThisDay') }}
        </el-button>
        <el-button type="primary" :loading="saving" @click="confirmEditScope('weekday_forward')">
          {{ t('common.recurringEditWeekdayForward') }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      :model-value="detailTask !== null"
      :title="detailTask?.title || t('common.viewDetail')"
      width="480px"
      @update:model-value="(open: boolean) => { if (!open) detailTask = null }"
    >
      <template v-if="detailTask">
        <dl class="detail-list">
          <div>
            <dt>{{ t('common.taskCategory') }}</dt>
            <dd>{{ detailTask.task_category?.name || '—' }}</dd>
          </div>
          <div>
            <dt>{{ t('common.taskDate') }}</dt>
            <dd>{{ taskDateLabel(detailTask) }}</dd>
          </div>
          <div>
            <dt>{{ t('common.taskTime') }}</dt>
            <dd>{{ scheduleSummary(detailTask) }}</dd>
          </div>
          <div>
            <dt>{{ t('parent.dashboard.children') }}</dt>
            <dd>
              {{
                detailTask.child_id
                  ? childMap.get(detailTask.child_id)?.nickname ?? detailTask.child_id
                  : t('common.wholeFamily')
              }}
            </dd>
          </div>
          <div>
            <dt>{{ t('growth.coins') }}</dt>
            <dd>{{ rewardSummary(detailTask) }}</dd>
          </div>
        </dl>
        <div v-if="detailSubtasks(detailTask).length" class="detail-subs">
          <strong>{{ t('common.subtasks') }}</strong>
          <div v-for="sub in detailSubtasks(detailTask)" :key="sub.id" class="detail-sub">
            <span>{{ timeOnly(sub.schedules?.[0]?.due_time ?? undefined) || '—' }}</span>
            <div>
              <div>{{ sub.title }}</div>
              <div class="muted">
                {{ sub.xp_reward }} {{ t('common.xpLabel') }}
                · {{ sub.point_reward }} {{ t('common.pointsLabel') }}
                · {{ sub.coin_reward }} {{ t('common.coinShort') }}
                <template v-if="sub.with_parent"> · {{ t('common.withParentTag') }}</template>
              </div>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <el-button @click="detailTask = null">{{ t('common.close') }}</el-button>
        <el-button type="primary" @click="editFromDetail">{{ t('common.edit') }}</el-button>
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
            <el-option label="ChatGPT" value="openai" />
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
          <el-form-item :label="t('common.pointsLabel')">
            <el-input-number v-model="homeworkDraft.point_reward" :min="0" :max="100" />
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
  width: 100%;
}
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.phone-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.phone-card {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 10px;
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
.phone-title-btn {
  padding: 0;
  border: none;
  background: none;
  color: var(--el-color-primary);
  font: inherit;
  font-weight: 600;
  font-size: 15px;
  text-align: left;
  cursor: pointer;
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
.step-list {
  list-style: none;
  margin: 6px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.step-list li {
  margin: 0;
  padding: 6px 10px;
  border-radius: 8px;
  background: #f4f6fb;
  font-size: 13px;
  font-weight: 600;
  color: #2c3142;
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
.settings-link {
  color: #5b8def;
  text-decoration: none;
}
.title-box {
  margin-bottom: 16px;
}
.task-meta-form {
  margin-bottom: 4px;
}
.task-meta-form :deep(.el-form-item),
.subtask-fields :deep(.el-form-item) {
  align-items: flex-start;
}
.task-meta-form :deep(.el-form-item__label),
.subtask-fields :deep(.el-form-item__label) {
  white-space: nowrap;
  height: 32px;
  line-height: 32px;
  align-items: center;
}
.task-meta-form :deep(.el-form-item__content),
.subtask-fields :deep(.el-form-item__content) {
  min-width: 0;
}
.task-meta-form :deep(.el-form-item__content) {
  flex-direction: column;
  align-items: stretch;
}
.field-inline {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  min-height: 32px;
  width: 100%;
}
.field-inline .hint-inline {
  margin-top: 0;
  flex: 1;
  min-width: 0;
}
.weekday-group {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}
.edit-scope-hint {
  margin: 0 0 16px;
  color: #6b6f85;
  font-size: 13px;
  line-height: 1.5;
}
.subtask-card {
  margin-bottom: 12px;
  padding: 12px;
  border: 1px solid #eef0f5;
  border-radius: 12px;
  background: #fafbfc;
}
.subtask-card-head {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}
.subtask-desc {
  margin-bottom: 8px;
}
.subtask-fields {
  margin: 4px -12px 0;
}
.subtask-fields :deep(.el-form-item) {
  margin-bottom: 12px;
}
.group-toggle {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 0;
  border: none;
  background: transparent;
  text-align: left;
  cursor: pointer;
}
.group-action {
  margin-left: auto;
  color: #5b8def;
  font-size: 13px;
}
.group-items {
  margin-top: 12px;
  display: grid;
  gap: 10px;
}
.inst-head.nested {
  padding-top: 8px;
  border-top: 1px solid #eef0f5;
}
.detail-list {
  display: grid;
  gap: 10px;
  margin: 0;
}
.detail-list div {
  display: grid;
  grid-template-columns: 88px 1fr;
  gap: 8px;
}
.detail-list dt {
  margin: 0;
  color: #9a9db0;
}
.detail-list dd {
  margin: 0;
}
.detail-subs {
  margin-top: 16px;
  display: grid;
  gap: 8px;
}
.detail-sub {
  display: grid;
  grid-template-columns: 64px 1fr;
  gap: 8px;
  align-items: start;
  padding: 8px 10px;
  border-radius: 10px;
  background: #f7f8fb;
}
</style>
