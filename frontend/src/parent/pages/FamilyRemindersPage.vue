<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { api } from '@/shared/api/client'

type Reminder = { id: number; title: string; weekdays: number[]; times: string[]; enabled: boolean; updated_at: string }
const rows = ref<Reminder[]>([])
const loading = ref(false)
const saving = ref(false)
const open = ref(false)
const editingId = ref<number | null>(null)
const title = ref('')
const weekdays = ref<number[]>([1, 2, 3, 4, 5, 6, 7])
const times = ref<string[]>(['08:00'])
const enabled = ref(true)
const days = [{ n: 1, label: '周一' }, { n: 2, label: '周二' }, { n: 3, label: '周三' },
  { n: 4, label: '周四' }, { n: 5, label: '周五' }, { n: 6, label: '周六' }, { n: 7, label: '周日' }]

async function load() {
  loading.value = true
  try { rows.value = await api.get<Reminder[]>('/parent/family-reminders') }
  catch { ElMessage.error('提醒加载失败') }
  finally { loading.value = false }
}
function edit(row?: Reminder) {
  editingId.value = row?.id ?? null
  title.value = row?.title ?? ''
  weekdays.value = [...(row?.weekdays ?? [1, 2, 3, 4, 5, 6, 7])]
  times.value = [...(row?.times ?? ['08:00'])]
  enabled.value = row?.enabled ?? true
  open.value = true
}
async function save() {
  const cleanTitle = title.value.trim()
  const cleanTimes = [...new Set(times.value.filter((time) => /^([01]\d|2[0-3]):[0-5]\d$/.test(time)))].sort()
  if (!cleanTitle || !weekdays.value.length || !cleanTimes.length || cleanTimes.length !== times.value.length) {
    ElMessage.warning('请填写内容、选择星期，并设置不重复的有效时间')
    return
  }
  saving.value = true
  const body = { title: cleanTitle, weekdays: [...weekdays.value].sort(), times: cleanTimes, enabled: enabled.value }
  try {
    if (editingId.value) await api.put(`/parent/family-reminders/${editingId.value}`, body)
    else await api.post('/parent/family-reminders', body)
    open.value = false
    ElMessage.success('已保存，设备联网后会同步')
    await load()
  } catch { ElMessage.error('保存失败，请检查网络和内容') }
  finally { saving.value = false }
}
async function remove(row: Reminder) {
  try { await ElMessageBox.confirm(`删除“${row.title}”？`, '删除提醒', { type: 'warning' }) }
  catch { return }
  try { await api.delete(`/parent/family-reminders/${row.id}`); await load() }
  catch { ElMessage.error('删除失败') }
}
onMounted(load)
</script>

<template>
  <main class="reminders-page" v-loading="loading">
    <header class="page-head">
      <div><p class="eyebrow">家庭信息中心</p><h1>语音提醒</h1><p>在这里安排，ThinkSmart View 同步后离线播报。</p></div>
      <el-button type="primary" size="large" @click="edit()">添加提醒</el-button>
    </header>
    <p class="device-link">设备尚未同步时，请先在 <RouterLink to="/parent/devices">设备管理</RouterLink> 生成配对码。</p>
    <el-empty v-if="!loading && !rows.length" description="还没有提醒" />
    <section v-else class="reminder-grid">
      <article v-for="row in rows" :key="row.id" class="reminder-card">
        <div><h2>{{ row.title }}</h2><p>{{ row.weekdays.map((d) => days.find((x) => x.n === d)?.label).join(' · ') }}</p>
          <strong>{{ row.times.join(' / ') }}</strong><p v-if="!row.enabled">已暂停</p></div>
        <div class="actions"><el-button @click="edit(row)">编辑</el-button><el-button type="danger" text @click="remove(row)">删除</el-button></div>
      </article>
    </section>
    <el-dialog v-model="open" :title="editingId ? '编辑提醒' : '添加提醒'" width="min(560px, 95vw)">
      <el-form label-position="top">
        <el-form-item label="播报内容"><el-input v-model="title" maxlength="100" show-word-limit placeholder="例如：现在该整理书包了" /></el-form-item>
        <el-form-item label="每周重复"><el-checkbox-group v-model="weekdays"><el-checkbox v-for="day in days" :key="day.n" :value="day.n">{{ day.label }}</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="每天播报时间">
          <div class="times"><div v-for="(_, index) in times" :key="index" class="time-row"><input v-model="times[index]" type="time" aria-label="播报时间" />
            <el-button v-if="times.length > 1" text type="danger" @click="times.splice(index, 1)">移除</el-button></div>
            <el-button v-if="times.length < 8" @click="times.push('12:00')">＋ 再加一个时间</el-button></div>
        </el-form-item>
        <el-form-item label="状态"><el-switch v-model="enabled" active-text="启用" inactive-text="暂停" /></el-form-item>
      </el-form>
      <template #footer><el-button @click="open = false">取消</el-button><el-button type="primary" :loading="saving" @click="save">保存</el-button></template>
    </el-dialog>
  </main>
</template>

<style scoped>
.reminders-page{max-width:1050px;margin:auto;padding:28px 20px;color:#202127}.page-head{display:flex;justify-content:space-between;gap:20px;align-items:center}.eyebrow{font-size:13px;color:#777;margin:0}h1{font-size:32px;margin:4px 0}h2{font-size:20px;margin:0 0 12px}.page-head p:last-child,.reminder-card p,.device-link{color:#737680}.device-link{margin:20px 0}.reminder-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}.reminder-card{background:#fff;border:1px solid #e9e9ed;border-radius:20px;padding:22px;display:flex;justify-content:space-between;gap:15px}.reminder-card strong{font-size:22px}.actions{display:flex;align-items:flex-start}.times{display:flex;flex-direction:column;gap:10px}.time-row{display:flex;gap:10px;align-items:center}.time-row input{font:inherit;padding:8px;border:1px solid #cfd1d8;border-radius:8px}@media(max-width:600px){.page-head{align-items:flex-start}.reminder-card{flex-direction:column}}
</style>
