<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { api } from '@/shared/api/client'

type Settings = {
  allow_ai: boolean
  image_provider: 'mock' | 'wanx'
  image_model: string
  review_intervals_days: number[]
}
type Child = { id: number; nickname: string }
type Poem = { id: number; title: string; author: string | null; dynasty?: string | null; preview?: string; source?: string }
type Assignment = { id: number; child_id: number; due_date: string | null; poem: { id: number; title: string; author: string | null } }
type RecordRow = {
  id: number
  child_id: number
  kind: string
  passed: boolean
  accuracy_percent: number | null
  feedback: string | null
  points_awarded: number
  created_at: string | null
  poem: { id: number; title: string }
}
type Plan = {
  id: number
  child_id: number
  status: string
  step_index: number
  next_review_date: string | null
  poem: { id: number; title: string }
}

const settings = reactive<Settings>({
  allow_ai: false,
  image_provider: 'mock',
  image_model: 'wanx-v1',
  review_intervals_days: [1, 2, 4, 7, 15],
})
const children = ref<Child[]>([])
const grade1 = ref<Poem[]>([])
const assignments = ref<Assignment[]>([])
const recent = ref<RecordRow[]>([])
const plans = ref<Plan[]>([])
const searchQ = ref('')
const searchHits = ref<Poem[]>([])
const assignChildId = ref<number | null>(null)
const assignPoemId = ref<number | null>(null)
const assignDue = ref('')
const error = ref('')
const saving = ref(false)

async function load() {
  try {
    const data = await api.get<{
      settings: Settings
      children: Child[]
      assignments: Assignment[]
      recent_records: RecordRow[]
      review_plans: Plan[]
      grade1: Poem[]
    }>('/parent/poetry')
    Object.assign(settings, data.settings)
    children.value = data.children
    assignments.value = data.assignments
    recent.value = data.recent_records
    plans.value = data.review_plans
    grade1.value = data.grade1
    if (assignChildId.value == null && data.children[0]) assignChildId.value = data.children[0].id
  } catch (e) {
    error.value = String(e)
  }
}

async function saveSettings() {
  saving.value = true
  error.value = ''
  try {
    Object.assign(settings, await api.put('/parent/poetry/settings', {
      allow_ai: settings.allow_ai,
      image_provider: settings.image_provider,
      image_model: settings.image_model,
    }))
    ElMessage.success('已保存 / Saved')
  } catch (e) {
    error.value = String(e)
  } finally {
    saving.value = false
  }
}

async function doSearch() {
  try {
    searchHits.value = await api.get<Poem[]>('/parent/poetry/search', { q: searchQ.value })
  } catch (e) {
    error.value = String(e)
  }
}

async function assign(poemId?: number) {
  const childId = assignChildId.value
  const pid = poemId ?? assignPoemId.value
  if (!childId || !pid) {
    ElMessage.warning('请选择孩子和诗词')
    return
  }
  try {
    await api.post('/parent/poetry/assignments', {
      child_id: childId,
      poem_id: pid,
      due_date: assignDue.value || null,
    })
    ElMessage.success('已布置背诵任务')
    await load()
  } catch (e) {
    error.value = String(e)
  }
}

async function cancel(id: number) {
  try {
    await api.delete(`/parent/poetry/assignments/${id}`)
    await load()
  } catch (e) {
    error.value = String(e)
  }
}

function childName(id: number): string {
  return children.value.find((c) => c.id === id)?.nickname ?? `#${id}`
}

onMounted(load)
</script>

<template>
  <main class="poetry">
    <h1>诗词星球</h1>
    <p>布置「要求诗词」· 孩子端另有每周推荐与背诵挑战</p>
    <p v-if="error" role="alert">{{ error }}</p>

    <div class="board">
      <section>
        <h2>设置 / Settings</h2>
        <label><input v-model="settings.allow_ai" type="checkbox"> 允许 Qwen（故事 / 生图提示 / 背诵纠错）</label>
        <label>
          生图服务商
          <select v-model="settings.image_provider">
            <option value="mock">mock（离线占位）</option>
            <option value="wanx">通义万相 Wanx</option>
          </select>
        </label>
        <label>生图模型 <input v-model="settings.image_model" type="text"></label>
        <p class="hint">复习间隔（天）：{{ settings.review_intervals_days.join(' → ') }}</p>
        <button type="button" :disabled="saving" @click="saveSettings">保存设置</button>
      </section>

      <section>
        <h2>要求诗词（学校布置）/ Assign</h2>
        <p class="hint">搜索诗泉或一年级精选，选择孩子与截止日期后布置；孩子在「要求诗词」入口可见。</p>
        <label>
          孩子
          <select v-model.number="assignChildId">
            <option v-for="c in children" :key="c.id" :value="c.id">{{ c.nickname }}</option>
          </select>
        </label>
        <label>截止日期 <input v-model="assignDue" type="date"></label>
        <div class="row">
          <input v-model="searchQ" type="search" placeholder="搜索诗泉 / 本地（如 静夜思）" @keyup.enter="doSearch">
          <button type="button" @click="doSearch">搜索</button>
        </div>
        <ul class="hits">
          <li v-for="p in searchHits" :key="p.id">
            <span>{{ p.title }} · {{ p.author }} <small>{{ p.preview }}</small></span>
            <button type="button" @click="assign(p.id)">布置</button>
          </li>
        </ul>
        <h3>一年级精选</h3>
        <ul class="hits">
          <li v-for="p in grade1" :key="p.id">
            <span>{{ p.title }} · {{ p.author }}</span>
            <button type="button" @click="assign(p.id)">布置</button>
          </li>
        </ul>
      </section>

      <section class="side-stack">
        <h2>进行中的任务</h2>
        <ul v-if="assignments.length" class="list">
          <li v-for="a in assignments" :key="a.id">
            {{ childName(a.child_id) }} · 《{{ a.poem.title }}》
            <template v-if="a.due_date"> · 截止 {{ a.due_date }}</template>
            <button type="button" class="link" @click="cancel(a.id)">取消</button>
          </li>
        </ul>
        <p v-else class="hint">暂无布置</p>

        <h2>复习计划</h2>
        <ul v-if="plans.length" class="list">
          <li v-for="p in plans" :key="p.id">
            {{ childName(p.child_id) }} · 《{{ p.poem.title }}》 · {{ p.status }} · 下次 {{ p.next_review_date }} · 第 {{ p.step_index + 1 }} 档
          </li>
        </ul>
        <p v-else class="hint">孩子成功背诵后会自动生成复习日</p>

        <h2>最近练习</h2>
        <ul v-if="recent.length" class="list">
          <li v-for="r in recent" :key="r.id">
            {{ childName(r.child_id) }} · {{ r.kind }} · 《{{ r.poem.title }}》
            · {{ r.passed ? '通过' : '再练' }}
            <template v-if="r.accuracy_percent != null"> · {{ r.accuracy_percent }}%</template>
            · +{{ r.points_awarded }} 积分
          </li>
        </ul>
        <p v-else class="hint">还没有练习记录</p>
      </section>
    </div>
  </main>
</template>

<style scoped>
.poetry {
  max-width: 1080px;
  margin: 0 auto;
  padding: 16px 20px 48px;
  width: 100%;
  box-sizing: border-box;
}
h1 { margin: 0 0 4px; }
.board {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0;
}
section {
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}
label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
  flex-wrap: wrap;
}
.row { display: flex; gap: 8px; margin: 8px 0; flex-wrap: wrap; }
.row input { flex: 1; min-width: 160px; }
.hits, .list { list-style: none; padding: 0; margin: 0; }
.hits li, .list li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
  flex-wrap: wrap;
}
.hint { opacity: 0.7; }
button.link {
  border: none;
  background: none;
  color: #c45c26;
  cursor: pointer;
}
.side-stack h2:first-child { margin-top: 0; }
@media (min-width: 900px) {
  .board {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
    gap: 0 28px;
    align-items: start;
  }
  .board > section:first-child {
    grid-column: 1 / -1;
  }
  .board > section {
    margin-top: 20px;
  }
  .side-stack {
    position: sticky;
    top: 12px;
  }
}
@media (orientation: landscape) and (max-height: 520px) {
  .poetry { padding: 12px 16px 32px; }
  section { margin-top: 16px; padding-top: 12px; }
}
</style>
