<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { AiModelChoice, AiModelList, AiSettings, Family, ParentAccount, RewardPolicy, TaskCategory } from '@/shared/api/types'
import { setAppLocale, type AppLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'
import { planetTitle } from '@/shared/planetTitle'
import type { Planet } from '@/shared/api/types'
import { useIsPhone } from '@/shared/useMediaQuery'

const { t } = useI18n()
const isPhone = useIsPhone()
const route = useRoute()
const session = useSessionStore()
const tab = ref<'family' | 'accounts' | 'ai' | 'categories' | 'bag-rewards' | 'planets'>('family')
const planets = ref<(Planet & { enabled: boolean })[]>([])
const savingPlanet = ref<string | null>(null)
const { locale } = useI18n()
function label(zh: string, en: string): string {
  return locale.value === 'en' ? en : locale.value === 'zh-CN' ? zh : `${zh} / ${en}`
}
async function loadPlanets(): Promise<void> {
  planets.value = await api.get('/parent/planet-settings')
}
async function togglePlanet(key: string, enabled: boolean): Promise<void> {
  savingPlanet.value = key
  try {
    planets.value = await api.patch('/parent/planet-settings', { key, enabled })
    ElMessage.success(t('common.saved'))
  } catch (e) { reportError(e) }
  finally { savingPlanet.value = null }
}
const loading = ref(true)
const savingFamily = ref(false)
const savingPassword = ref(false)
const savingProfile = ref(false)
const savingAi = ref(false)
const creatingParent = ref(false)
const savingCategory = ref(false)
const savingBagReward = ref<string | null>(null)
const categories = ref<TaskCategory[]>([])
const bagRewardPolicies = ref<RewardPolicy[]>([])
const showCategoryForm = ref(false)
const editingCategoryId = ref<number | null>(null)
const categoryName = ref('')

const BAG_REWARD_CODES = ['item.checklist.before_school', 'item.checklist.after_school'] as const

const timezones = Intl.supportedValuesOf('timeZone')
const localeOptions = computed(() => [
  { value: 'zh-en' as AppLocale, label: t('lang.bilingual') },
  { value: 'zh-CN' as AppLocale, label: t('lang.zh') },
  { value: 'en' as AppLocale, label: t('lang.en') },
])

const familyForm = reactive({ name: '', timezone: '', locale: 'zh-en' as AppLocale })
const parents = ref<ParentAccount[]>([])
const profileForm = reactive({ display_name: '', email: '' })
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const showCreateParent = ref(false)
const createForm = reactive({ display_name: '', username: '', password: '', password_confirmation: '' })

type AiProviderName = 'gemini' | 'qwen' | 'openai'
type KeyProbe = 'idle' | 'checking' | 'ok' | 'invalid' | 'failed' | 'empty' | 'none'

const ai = ref<AiSettings | null>(null)
const aiForm = reactive({
  provider: 'auto',
  gemini_model: 'gemini-3.1-flash-lite',
  qwen_model: 'qwen-turbo',
  openai_model: 'gpt-4o-mini',
  gemini_api_key: '',
  qwen_api_key: '',
  openai_api_key: '',
  clear_gemini_api_key: false,
  clear_qwen_api_key: false,
  clear_openai_api_key: false,
})
const modelOptions = reactive<Record<AiProviderName, AiModelChoice[]>>({
  gemini: [],
  qwen: [],
  openai: [],
})
const modelLoading = reactive<Record<AiProviderName, boolean>>({
  gemini: false,
  qwen: false,
  openai: false,
})
const modelNote = reactive<Record<AiProviderName, string>>({
  gemini: '',
  qwen: '',
  openai: '',
})
const modelTouched = reactive<Record<AiProviderName, boolean>>({
  gemini: false,
  qwen: false,
  openai: false,
})
const keyProbe = reactive<Record<AiProviderName, Exclude<KeyProbe, 'checking'>>>({
  gemini: 'idle',
  qwen: 'idle',
  openai: 'idle',
})
const modelRequest = { gemini: 0, qwen: 0, openai: 0 }
const modelTimers: Record<AiProviderName, number> = { gemini: 0, qwen: 0, openai: 0 }
const suppressModelTouch: Record<AiProviderName, boolean> = { gemini: false, qwen: false, openai: false }

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
  else ElMessage.error(t('common.saveFailed'))
}

async function loadFamily(): Promise<void> {
  const family = await api.get<Family>('/parent/family')
  familyForm.name = family.name
  familyForm.timezone = family.timezone
  familyForm.locale = (family.locale as AppLocale) || 'zh-en'
}

async function loadParents(): Promise<void> {
  parents.value = await api.get<ParentAccount[]>('/parent/parents')
  const self = parents.value.find((p) => p.is_self)
  if (self) {
    profileForm.display_name = self.display_name
    profileForm.email = self.email ?? ''
  }
}

async function loadAi(): Promise<void> {
  const data = await api.get<AiSettings>('/parent/ai-settings')
  ai.value = data
  aiForm.provider = data.provider
  aiForm.gemini_model = data.gemini.model
  aiForm.qwen_model = data.qwen.model
  aiForm.openai_model = data.openai.model
  aiForm.gemini_api_key = ''
  aiForm.qwen_api_key = ''
  aiForm.openai_api_key = ''
  aiForm.clear_gemini_api_key = false
  aiForm.clear_qwen_api_key = false
  aiForm.clear_openai_api_key = false
  modelTouched.gemini = false
  modelTouched.qwen = false
  modelTouched.openai = false
  if (tab.value === 'ai') await refreshVisibleModels()
}

function modelField(provider: AiProviderName): 'gemini_model' | 'qwen_model' | 'openai_model' {
  if (provider === 'gemini') return 'gemini_model'
  if (provider === 'qwen') return 'qwen_model'
  return 'openai_model'
}

function draftKey(provider: AiProviderName): string {
  if (provider === 'gemini') return aiForm.gemini_api_key.trim()
  if (provider === 'qwen') return aiForm.qwen_api_key.trim()
  return aiForm.openai_api_key.trim()
}

function clearingKey(provider: AiProviderName): boolean {
  if (provider === 'gemini') return aiForm.clear_gemini_api_key
  if (provider === 'qwen') return aiForm.clear_qwen_api_key
  return aiForm.clear_openai_api_key
}

function applyModelList(provider: AiProviderName, data: AiModelList): void {
  modelOptions[provider] = data.models
  const field = modelField(provider)
  const current = aiForm[field]
  const known = data.models.some((model) => model.id === current)
  const customized = ai.value?.[provider].model_customized === true
  if (data.latest && !modelTouched[provider] && !customized && aiForm[field] !== data.latest) {
    suppressModelTouch[provider] = true
    aiForm[field] = data.latest
    void nextTick(() => {
      suppressModelTouch[provider] = false
    })
  }

  if (data.error === 'invalid_key') {
    keyProbe[provider] = 'invalid'
    modelNote[provider] = t('parent.settings.modelInvalidKey')
  } else if (data.error === 'upstream_failed') {
    keyProbe[provider] = 'failed'
    modelNote[provider] = t('parent.settings.modelLoadFailed')
  } else if (data.error === 'no_models') {
    keyProbe[provider] = 'none'
    modelNote[provider] = t('parent.settings.modelNone')
  } else if (data.error === 'not_configured') {
    keyProbe[provider] = 'empty'
    modelNote[provider] = ''
  } else if (data.models.length > 0) {
    keyProbe[provider] = 'ok'
    if (data.latest && aiForm[field] === data.latest && !customized) modelNote[provider] = t('parent.settings.modelLatest')
    else if (!known) modelNote[provider] = t('parent.settings.modelMissing')
    else if (data.probed) modelNote[provider] = t('parent.settings.modelProbed', { count: data.models.length })
    else modelNote[provider] = ''
  } else {
    keyProbe[provider] = 'failed'
    modelNote[provider] = t('parent.settings.modelLoadFailed')
  }
}

async function loadModels(provider: AiProviderName, key = ''): Promise<void> {
  if (clearingKey(provider)) {
    modelOptions[provider] = []
    modelNote[provider] = ''
    keyProbe[provider] = 'empty'
    modelLoading[provider] = false
    return
  }
  const seq = ++modelRequest[provider]
  modelLoading[provider] = true
  try {
    const body: { provider: AiProviderName; api_key?: string; preferred_model?: string } = { provider }
    if (key) body.api_key = key
    const preferred = aiForm[modelField(provider)].trim()
    if (preferred) body.preferred_model = preferred
    const data = await api.post<AiModelList>('/parent/ai-models', body)
    if (seq !== modelRequest[provider]) return
    applyModelList(provider, data)
  } catch {
    if (seq !== modelRequest[provider]) return
    modelOptions[provider] = []
    keyProbe[provider] = 'failed'
    modelNote[provider] = t('parent.settings.modelLoadFailed')
  } finally {
    if (seq === modelRequest[provider]) modelLoading[provider] = false
  }
}

function keyProbeState(provider: AiProviderName): KeyProbe {
  if (clearingKey(provider)) return 'empty'
  if (modelLoading[provider]) return 'checking'
  return keyProbe[provider]
}

function keyProbeLabel(provider: AiProviderName): string {
  switch (keyProbeState(provider)) {
    case 'checking':
      return t('parent.settings.keyChecking')
    case 'ok':
      return t('parent.settings.keyAvailable')
    case 'invalid':
      return t('parent.settings.keyUnavailable')
    case 'failed':
      return t('parent.settings.keyCheckFailed')
    case 'none':
      return t('parent.settings.keyNoModels')
    case 'empty':
      return t('parent.settings.aiSourceNone')
    default:
      return ''
  }
}

function scheduleModels(provider: AiProviderName, key: string): void {
  window.clearTimeout(modelTimers[provider])
  modelLoading[provider] = true
  modelTimers[provider] = window.setTimeout(() => {
    void loadModels(provider, key.trim())
  }, 400)
}

async function refreshVisibleModels(): Promise<void> {
  await Promise.all([
    loadModels('gemini', draftKey('gemini')),
    loadModels('qwen', draftKey('qwen')),
    loadModels('openai', draftKey('openai')),
  ])
}

async function loadCategories(): Promise<void> {
  categories.value = await api.get<TaskCategory[]>('/parent/task-categories')
}

async function loadBagRewards(): Promise<void> {
  const policies = await api.get<RewardPolicy[]>('/parent/reward-policies')
  bagRewardPolicies.value = policies.filter((p) => (BAG_REWARD_CODES as readonly string[]).includes(p.code))
}

function bagRewardLabel(code: string): string {
  if (code === 'item.checklist.before_school') return t('parent.settings.bagBeforeSchool')
  if (code === 'item.checklist.after_school') return t('parent.settings.bagAfterSchool')
  return code
}

async function saveBagReward(policy: RewardPolicy): Promise<void> {
  savingBagReward.value = policy.code
  try {
    const updated = await api.put<RewardPolicy>('/parent/reward-policies', {
      code: policy.code,
      xp_amount: Number(policy.xp_amount) || 0,
      coin_amount: Number(policy.coin_amount) || 0,
      is_active: policy.is_active,
    })
    bagRewardPolicies.value = bagRewardPolicies.value.map((row) => (row.code === updated.code ? updated : row))
    ElMessage.success(t('parent.settings.bagRewardSaved'))
  } catch (e) {
    reportError(e)
  } finally {
    savingBagReward.value = null
  }
}

function syncTabFromRoute(): void {
  if (route.query.tab === 'categories') tab.value = 'categories'
  if (route.query.tab === 'bag-rewards') tab.value = 'bag-rewards'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    syncTabFromRoute()
    if (route.query.tab === 'planets') tab.value = 'planets'
    await Promise.all([loadPlanets(), loadFamily(), loadParents(), loadAi(), loadCategories(), loadBagRewards()])
  } catch (e) {
    reportError(e)
  } finally {
    loading.value = false
  }
}

function openCreateCategory(): void {
  editingCategoryId.value = null
  categoryName.value = ''
  showCategoryForm.value = true
}

function openEditCategory(row: TaskCategory): void {
  editingCategoryId.value = row.id
  categoryName.value = row.name
  showCategoryForm.value = true
}

async function saveCategory(): Promise<void> {
  const name = categoryName.value.trim()
  if (!name) {
    ElMessage.warning(t('parent.settings.categoryNameRequired'))
    return
  }
  savingCategory.value = true
  try {
    if (editingCategoryId.value) {
      await api.put<TaskCategory>(`/parent/task-categories/${editingCategoryId.value}`, { name })
      ElMessage.success(t('parent.settings.categoryUpdated'))
    } else {
      await api.post<TaskCategory>('/parent/task-categories', { name })
      ElMessage.success(t('parent.settings.categoryCreated'))
    }
    showCategoryForm.value = false
    await loadCategories()
  } catch (e) {
    reportError(e)
  } finally {
    savingCategory.value = false
  }
}

async function deleteCategory(row: TaskCategory): Promise<void> {
  try {
    await ElMessageBox.confirm(
      t('parent.settings.deleteCategoryConfirm', { name: row.name }),
      t('common.delete'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await api.delete(`/parent/task-categories/${row.id}`)
    await loadCategories()
    ElMessage.success(t('parent.settings.categoryDeleted'))
  } catch (e) {
    reportError(e)
  }
}

async function saveFamily(): Promise<void> {
  savingFamily.value = true
  try {
    const family = await api.patch<Family>('/parent/family', {
      name: familyForm.name,
      timezone: familyForm.timezone,
      locale: familyForm.locale,
    })
    familyForm.name = family.name
    familyForm.timezone = family.timezone
    familyForm.locale = family.locale as AppLocale
    setAppLocale(familyForm.locale)
    await session.load(true)
    ElMessage.success(t('common.saved'))
  } catch (e) {
    reportError(e)
  } finally {
    savingFamily.value = false
  }
}

async function saveProfile(): Promise<void> {
  savingProfile.value = true
  try {
    await api.patch<ParentAccount>('/parent/parents/me', {
      display_name: profileForm.display_name,
      email: profileForm.email || null,
    })
    await loadParents()
    await session.load(true)
    ElMessage.success(t('common.saved'))
  } catch (e) {
    reportError(e)
  } finally {
    savingProfile.value = false
  }
}

async function savePassword(): Promise<void> {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    ElMessage.warning(t('parent.settings.passwordMismatch'))
    return
  }
  savingPassword.value = true
  try {
    await api.put('/parent/parents/me/password', {
      current_password: passwordForm.current_password,
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    })
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    ElMessage.success(t('parent.settings.passwordChanged'))
  } catch (e) {
    reportError(e)
  } finally {
    savingPassword.value = false
  }
}

async function createParent(): Promise<void> {
  if (createForm.password !== createForm.password_confirmation) {
    ElMessage.warning(t('parent.settings.passwordMismatch'))
    return
  }
  creatingParent.value = true
  try {
    await api.post('/parent/parents', {
      display_name: createForm.display_name,
      username: createForm.username,
      password: createForm.password,
    })
    showCreateParent.value = false
    createForm.display_name = ''
    createForm.username = ''
    createForm.password = ''
    createForm.password_confirmation = ''
    await loadParents()
    ElMessage.success(t('parent.settings.parentCreated'))
  } catch (e) {
    reportError(e)
  } finally {
    creatingParent.value = false
  }
}

async function deleteParent(row: ParentAccount): Promise<void> {
  try {
    await ElMessageBox.confirm(
      t('parent.settings.deleteParentConfirm', { name: row.display_name }),
      t('parent.settings.deleteParent'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await api.delete(`/parent/parents/${row.id}`)
    await loadParents()
    ElMessage.success(t('parent.settings.parentDeleted'))
  } catch (e) {
    reportError(e)
  }
}

async function saveAi(): Promise<void> {
  savingAi.value = true
  try {
    const payload: Record<string, unknown> = {
      provider: aiForm.provider,
      gemini_model: aiForm.gemini_model,
      qwen_model: aiForm.qwen_model,
      openai_model: aiForm.openai_model,
    }
    if (aiForm.clear_gemini_api_key) payload.clear_gemini_api_key = true
    else if (aiForm.gemini_api_key.trim()) payload.gemini_api_key = aiForm.gemini_api_key.trim()
    if (aiForm.clear_qwen_api_key) payload.clear_qwen_api_key = true
    else if (aiForm.qwen_api_key.trim()) payload.qwen_api_key = aiForm.qwen_api_key.trim()
    if (aiForm.clear_openai_api_key) payload.clear_openai_api_key = true
    else if (aiForm.openai_api_key.trim()) payload.openai_api_key = aiForm.openai_api_key.trim()

    ai.value = await api.put<AiSettings>('/parent/ai-settings', payload)
    aiForm.gemini_api_key = ''
    aiForm.qwen_api_key = ''
    aiForm.openai_api_key = ''
    aiForm.clear_gemini_api_key = false
    aiForm.clear_qwen_api_key = false
    aiForm.clear_openai_api_key = false
    aiForm.provider = ai.value.provider
    aiForm.gemini_model = ai.value.gemini.model
    aiForm.qwen_model = ai.value.qwen.model
    aiForm.openai_model = ai.value.openai.model
    modelTouched.gemini = true
    modelTouched.qwen = true
    modelTouched.openai = true
    await refreshVisibleModels()
    ElMessage.success(t('common.saved'))
  } catch (e) {
    reportError(e)
  } finally {
    savingAi.value = false
  }
}

function sourceLabel(source: string): string {
  if (source === 'family') return t('parent.settings.aiSourceFamily')
  if (source === 'env') return t('parent.settings.aiSourceEnv')
  return t('parent.settings.aiSourceNone')
}

function markModelTouched(provider: AiProviderName): void {
  if (suppressModelTouch[provider]) return
  modelTouched[provider] = true
  const latest = modelOptions[provider][0]?.id
  const customized = ai.value?.[provider].model_customized === true
  if (!customized && latest && aiForm[modelField(provider)] === latest) {
    modelNote[provider] = t('parent.settings.modelLatest')
    return
  }
  modelNote[provider] = ''
}

watch(tab, (name) => {
  if (name === 'ai' && ai.value) void refreshVisibleModels()
})

watch(() => aiForm.gemini_api_key, (value) => {
  if (tab.value === 'ai') scheduleModels('gemini', value)
})
watch(() => aiForm.qwen_api_key, (value) => {
  if (tab.value === 'ai') scheduleModels('qwen', value)
})
watch(() => aiForm.openai_api_key, (value) => {
  if (tab.value === 'ai') scheduleModels('openai', value)
})
watch(() => aiForm.clear_gemini_api_key, (clearing) => {
  if (tab.value !== 'ai') return
  if (clearing) {
    modelOptions.gemini = []
    modelNote.gemini = ''
    keyProbe.gemini = 'empty'
    return
  }
  void loadModels('gemini', draftKey('gemini'))
})
watch(() => aiForm.clear_qwen_api_key, (clearing) => {
  if (tab.value !== 'ai') return
  if (clearing) {
    modelOptions.qwen = []
    modelNote.qwen = ''
    keyProbe.qwen = 'empty'
    return
  }
  void loadModels('qwen', draftKey('qwen'))
})
watch(() => aiForm.clear_openai_api_key, (clearing) => {
  if (tab.value !== 'ai') return
  if (clearing) {
    modelOptions.openai = []
    modelNote.openai = ''
    keyProbe.openai = 'empty'
    return
  }
  void loadModels('openai', draftKey('openai'))
})

watch(() => route.query.tab, syncTabFromRoute)

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="settings">
    <el-tabs v-model="tab">
      <el-tab-pane :label="label('星球开关', 'Planet switches')" name="planets">
        <p class="hint top">{{ label('控制本家庭所有孩子可以使用的星球。关闭后隐藏入口并暂停访问，已有记录保留。', 'Choose the planets available to all children in this family. Turning one off hides it and pauses access while keeping existing records.') }}</p>
        <div class="phone-list">
          <div v-for="planet in planets" :key="planet.key" class="phone-card">
            <div class="phone-info"><span class="phone-title">{{ planetTitle(planet.key, planet.title) }}</span></div>
            <el-switch :model-value="planet.enabled" :aria-label="planetTitle(planet.key, planet.title)" :loading="savingPlanet === planet.key" :disabled="savingPlanet !== null" @change="togglePlanet(planet.key, Boolean($event))" />
          </div>
        </div>
      </el-tab-pane>
      <el-tab-pane :label="t('parent.settings.tabFamily')" name="family">
        <el-form label-position="top" @submit.prevent="saveFamily">
          <el-form-item :label="t('parent.family.name')">
            <el-input v-model="familyForm.name" maxlength="64" />
          </el-form-item>
          <el-form-item :label="t('parent.settings.defaultLocale')">
            <el-select v-model="familyForm.locale" style="width: 100%">
              <el-option v-for="o in localeOptions" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <div class="hint">{{ t('parent.settings.defaultLocaleHint') }}</div>
          </el-form-item>
          <el-form-item :label="t('parent.family.timezone')">
            <el-select v-model="familyForm.timezone" filterable style="width: 100%">
              <el-option v-for="tz in timezones" :key="tz" :label="tz" :value="tz" />
            </el-select>
            <div class="hint">{{ t('parent.family.settingsHint') }}</div>
          </el-form-item>
          <el-button type="primary" :loading="savingFamily" native-type="submit">{{ t('common.save') }}</el-button>
        </el-form>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabAccounts')" name="accounts">
        <section class="block">
          <div class="block-head">
            <h3>{{ t('parent.settings.parentsList') }}</h3>
            <el-button type="primary" size="small" @click="showCreateParent = true">{{ t('parent.settings.addParent') }}</el-button>
          </div>
          <el-table v-if="!isPhone" :data="parents" stripe>
            <el-table-column prop="display_name" :label="t('parent.settings.displayName')" min-width="120" />
            <el-table-column prop="username" :label="t('auth.username')" width="120" />
            <el-table-column :label="t('common.status')" width="100">
              <template #default="{ row }">
                <el-tag v-if="row.is_self" size="small" type="success">{{ t('parent.settings.you') }}</el-tag>
                <span v-else>{{ row.status }}</span>
              </template>
            </el-table-column>
            <el-table-column :label="t('common.actions')" width="100">
              <template #default="{ row }">
                <el-button
                  v-if="!row.is_self"
                  link
                  type="danger"
                  @click="deleteParent(row as ParentAccount)"
                >
                  {{ t('common.delete') }}
                </el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-else class="phone-list">
            <article v-for="row in parents" :key="row.id" class="phone-card">
              <div class="phone-info">
                <div class="phone-title">{{ row.display_name }}</div>
                <div class="phone-meta">@{{ row.username }}</div>
                <el-tag v-if="row.is_self" size="small" type="success">{{ t('parent.settings.you') }}</el-tag>
                <span v-else class="phone-meta">{{ row.status }}</span>
              </div>
              <div v-if="!row.is_self" class="phone-ops">
                <el-button size="small" type="danger" @click="deleteParent(row)">{{ t('common.delete') }}</el-button>
              </div>
            </article>
          </div>
        </section>

        <section class="block">
          <h3>{{ t('parent.settings.myProfile') }}</h3>
          <el-form label-position="top" @submit.prevent="saveProfile">
            <el-form-item :label="t('parent.settings.displayName')">
              <el-input v-model="profileForm.display_name" maxlength="32" />
            </el-form-item>
            <el-form-item :label="t('auth.email')">
              <el-input v-model="profileForm.email" maxlength="191" />
            </el-form-item>
            <el-button type="primary" :loading="savingProfile" native-type="submit">{{ t('common.save') }}</el-button>
          </el-form>
        </section>

        <section class="block">
          <h3>{{ t('parent.settings.changePassword') }}</h3>
          <el-form label-position="top" @submit.prevent="savePassword">
            <el-form-item :label="t('parent.settings.currentPassword')">
              <el-input v-model="passwordForm.current_password" type="password" show-password autocomplete="current-password" />
            </el-form-item>
            <el-form-item :label="t('parent.settings.newPassword')">
              <el-input v-model="passwordForm.password" type="password" show-password autocomplete="new-password" />
              <div class="hint">{{ t('parent.settings.passwordHint') }}</div>
            </el-form-item>
            <el-form-item :label="t('parent.settings.confirmPassword')">
              <el-input v-model="passwordForm.password_confirmation" type="password" show-password autocomplete="new-password" />
            </el-form-item>
            <el-button type="primary" :loading="savingPassword" native-type="submit">{{ t('parent.settings.changePassword') }}</el-button>
          </el-form>
        </section>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabCategories')" name="categories">
        <p class="hint top">{{ t('parent.settings.categoriesHint') }}</p>
        <div class="block-head">
          <h3>{{ t('parent.settings.tabCategories') }}</h3>
          <el-button type="primary" size="small" @click="openCreateCategory">{{ t('parent.settings.addCategory') }}</el-button>
        </div>
        <el-table v-if="!isPhone" :data="categories" stripe :empty-text="t('parent.settings.noCategories')">
          <el-table-column prop="name" :label="t('parent.settings.categoryName')" min-width="160" />
          <el-table-column :label="t('common.actions')" width="140">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEditCategory(row as TaskCategory)">{{ t('common.edit') }}</el-button>
              <el-button link type="danger" @click="deleteCategory(row as TaskCategory)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="categories.length === 0" :description="t('parent.settings.noCategories')" />
          <article v-for="row in categories" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.name }}</div>
            </div>
            <div class="phone-ops">
              <el-button size="small" @click="openEditCategory(row)">{{ t('common.edit') }}</el-button>
              <el-button size="small" type="danger" @click="deleteCategory(row)">{{ t('common.delete') }}</el-button>
            </div>
          </article>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabBagRewards')" name="bag-rewards">
        <p class="hint top">{{ t('parent.settings.bagRewardsHint') }}</p>
        <el-table v-if="!isPhone" :data="bagRewardPolicies" stripe>
          <el-table-column :label="t('parent.settings.bagRewardDirectory')" min-width="160">
            <template #default="{ row }">
              {{ bagRewardLabel((row as RewardPolicy).code) }}
            </template>
          </el-table-column>
          <el-table-column :label="t('parent.settings.bagRewardXp')" width="120">
            <template #default="{ row }">
              <el-input-number v-model="(row as RewardPolicy).xp_amount" :min="0" :max="100" controls-position="right" />
            </template>
          </el-table-column>
          <el-table-column :label="t('parent.settings.bagRewardCoins')" width="120">
            <template #default="{ row }">
              <el-input-number v-model="(row as RewardPolicy).coin_amount" :min="0" :max="100" controls-position="right" />
            </template>
          </el-table-column>
          <el-table-column :label="t('parent.settings.bagRewardActive')" width="100">
            <template #default="{ row }">
              <el-switch v-model="(row as RewardPolicy).is_active" />
            </template>
          </el-table-column>
          <el-table-column :label="t('common.actions')" width="100">
            <template #default="{ row }">
              <el-button
                link
                type="primary"
                :loading="savingBagReward === (row as RewardPolicy).code"
                @click="saveBagReward(row as RewardPolicy)"
              >
                {{ t('common.save') }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <article v-for="row in bagRewardPolicies" :key="row.code" class="phone-card bag-reward-card">
            <div class="phone-title">{{ bagRewardLabel(row.code) }}</div>
            <el-form label-position="top" class="bag-reward-form" @submit.prevent="saveBagReward(row)">
              <el-form-item :label="t('parent.settings.bagRewardXp')">
                <el-input-number v-model="row.xp_amount" :min="0" :max="100" controls-position="right" />
              </el-form-item>
              <el-form-item :label="t('parent.settings.bagRewardCoins')">
                <el-input-number v-model="row.coin_amount" :min="0" :max="100" controls-position="right" />
              </el-form-item>
              <el-form-item :label="t('parent.settings.bagRewardActive')">
                <el-switch v-model="row.is_active" />
              </el-form-item>
              <el-button type="primary" :loading="savingBagReward === row.code" native-type="submit">{{ t('common.save') }}</el-button>
            </el-form>
          </article>
        </div>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabAi')" name="ai">
        <p class="hint top">{{ t('parent.settings.aiHint') }}</p>
        <p class="hint top">{{ t('parent.settings.keyProbeHint') }}</p>
        <el-form label-position="top" @submit.prevent="saveAi">
          <el-form-item :label="t('parent.settings.aiProvider')">
            <el-select v-model="aiForm.provider" style="width: 100%">
              <el-option :label="t('parent.settings.aiProviderAuto')" value="auto" />
              <el-option label="Gemini" value="gemini" />
              <el-option :label="t('common.homeworkParserQwen')" value="qwen" />
              <el-option label="ChatGPT" value="openai" />
              <el-option :label="t('common.homeworkParserRules')" value="rules" />
            </el-select>
            <div class="hint">{{ t('parent.settings.aiProviderHint') }}</div>
          </el-form-item>

          <el-divider content-position="left">Gemini</el-divider>
          <div v-if="ai" class="ai-status">
            {{ sourceLabel(ai.gemini.source) }}
            <span v-if="ai.gemini.hint"> · {{ ai.gemini.hint }}</span>
          </div>
          <el-form-item :label="t('parent.settings.apiKey')">
            <div class="api-key-row">
              <el-input
                v-model="aiForm.gemini_api_key"
                type="password"
                show-password
                :placeholder="t('parent.settings.apiKeyPlaceholder')"
                :disabled="aiForm.clear_gemini_api_key"
              />
              <span
                v-if="keyProbeLabel('gemini')"
                class="key-probe"
                :class="keyProbeState('gemini')"
                :title="keyProbeLabel('gemini')"
              >{{ keyProbeLabel('gemini') }}</span>
            </div>
          </el-form-item>
          <el-form-item>
            <el-checkbox v-model="aiForm.clear_gemini_api_key">{{ t('parent.settings.clearFamilyKey') }}</el-checkbox>
          </el-form-item>
          <el-form-item :label="t('parent.settings.model')">
            <el-select
              v-if="modelOptions.gemini.length > 0 || modelLoading.gemini"
              v-model="aiForm.gemini_model"
              filterable
              style="width: 100%"
              :loading="modelLoading.gemini"
              :placeholder="t('parent.settings.modelsLoading')"
              @change="markModelTouched('gemini')"
            >
              <el-option v-for="model in modelOptions.gemini" :key="model.id" :label="model.label === model.id ? model.id : `${model.label} · ${model.id}`" :value="model.id" />
            </el-select>
            <el-input v-else v-model="aiForm.gemini_model" maxlength="128" @input="markModelTouched('gemini')" />
            <div v-if="modelNote.gemini" class="hint">{{ modelNote.gemini }}</div>
          </el-form-item>

          <el-divider content-position="left">{{ t('common.homeworkParserQwen') }}</el-divider>
          <div v-if="ai" class="ai-status">
            {{ sourceLabel(ai.qwen.source) }}
            <span v-if="ai.qwen.hint"> · {{ ai.qwen.hint }}</span>
          </div>
          <el-form-item :label="t('parent.settings.apiKey')">
            <div class="api-key-row">
              <el-input
                v-model="aiForm.qwen_api_key"
                type="password"
                show-password
                :placeholder="t('parent.settings.apiKeyPlaceholder')"
                :disabled="aiForm.clear_qwen_api_key"
              />
              <span
                v-if="keyProbeLabel('qwen')"
                class="key-probe"
                :class="keyProbeState('qwen')"
                :title="keyProbeLabel('qwen')"
              >{{ keyProbeLabel('qwen') }}</span>
            </div>
          </el-form-item>
          <el-form-item>
            <el-checkbox v-model="aiForm.clear_qwen_api_key">{{ t('parent.settings.clearFamilyKey') }}</el-checkbox>
          </el-form-item>
          <el-form-item :label="t('parent.settings.model')">
            <el-select
              v-if="modelOptions.qwen.length > 0 || modelLoading.qwen"
              v-model="aiForm.qwen_model"
              filterable
              style="width: 100%"
              :loading="modelLoading.qwen"
              :placeholder="t('parent.settings.modelsLoading')"
              @change="markModelTouched('qwen')"
            >
              <el-option v-for="model in modelOptions.qwen" :key="model.id" :label="model.label === model.id ? model.id : `${model.label} · ${model.id}`" :value="model.id" />
            </el-select>
            <el-input v-else v-model="aiForm.qwen_model" maxlength="128" @input="markModelTouched('qwen')" />
            <div v-if="modelNote.qwen" class="hint">{{ modelNote.qwen }}</div>
          </el-form-item>

          <el-divider content-position="left">ChatGPT</el-divider>
          <div v-if="ai" class="ai-status">
            {{ sourceLabel(ai.openai.source) }}
            <span v-if="ai.openai.hint"> · {{ ai.openai.hint }}</span>
          </div>
          <el-form-item :label="t('parent.settings.apiKey')">
            <div class="api-key-row">
              <el-input
                v-model="aiForm.openai_api_key"
                type="password"
                show-password
                :placeholder="t('parent.settings.apiKeyPlaceholder')"
                :disabled="aiForm.clear_openai_api_key"
              />
              <span
                v-if="keyProbeLabel('openai')"
                class="key-probe"
                :class="keyProbeState('openai')"
                :title="keyProbeLabel('openai')"
              >{{ keyProbeLabel('openai') }}</span>
            </div>
          </el-form-item>
          <el-form-item>
            <el-checkbox v-model="aiForm.clear_openai_api_key">{{ t('parent.settings.clearFamilyKey') }}</el-checkbox>
          </el-form-item>
          <el-form-item :label="t('parent.settings.model')">
            <el-select
              v-if="modelOptions.openai.length > 0 || modelLoading.openai"
              v-model="aiForm.openai_model"
              filterable
              style="width: 100%"
              :loading="modelLoading.openai"
              :placeholder="t('parent.settings.modelsLoading')"
              @change="markModelTouched('openai')"
            >
              <el-option v-for="model in modelOptions.openai" :key="model.id" :label="model.label === model.id ? model.id : `${model.label} · ${model.id}`" :value="model.id" />
            </el-select>
            <el-input v-else v-model="aiForm.openai_model" maxlength="128" @input="markModelTouched('openai')" />
            <div v-if="modelNote.openai" class="hint">{{ modelNote.openai }}</div>
          </el-form-item>

          <el-button type="primary" :loading="savingAi" native-type="submit">{{ t('common.save') }}</el-button>
        </el-form>
      </el-tab-pane>
    </el-tabs>

    <el-dialog
      v-model="showCategoryForm"
      :title="editingCategoryId ? t('parent.settings.editCategory') : t('parent.settings.addCategory')"
      width="420px"
      destroy-on-close
    >
      <el-form label-position="top" @submit.prevent="saveCategory">
        <el-form-item :label="t('parent.settings.categoryName')" required>
          <el-input v-model="categoryName" maxlength="32" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCategoryForm = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="savingCategory" @click="saveCategory">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showCreateParent" :title="t('parent.settings.addParent')" width="420px" destroy-on-close>
      <el-form label-position="top">
        <el-form-item :label="t('parent.settings.displayName')" required>
          <el-input v-model="createForm.display_name" maxlength="32" />
        </el-form-item>
        <el-form-item :label="t('auth.username')" required>
          <el-input v-model="createForm.username" maxlength="32" />
        </el-form-item>
        <el-form-item :label="t('auth.password')" required>
          <el-input v-model="createForm.password" type="password" show-password />
          <div class="hint">{{ t('parent.settings.passwordHint') }}</div>
        </el-form-item>
        <el-form-item :label="t('parent.settings.confirmPassword')" required>
          <el-input v-model="createForm.password_confirmation" type="password" show-password />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreateParent = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="creatingParent" @click="createParent">{{ t('common.create') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.settings {
  max-width: 720px;
  background: #fff;
  border-radius: 14px;
  padding: 20px;
}
.hint {
  color: #6b6f80;
  font-size: 12px;
  line-height: 1.6;
  margin-top: 6px;
}
.hint.top {
  margin: 0 0 16px;
}
.block {
  margin-bottom: 28px;
}
.block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
.block h3 {
  margin: 0 0 12px;
  font-size: 15px;
}
.ai-status {
  font-size: 13px;
  color: #6b6f80;
  margin: -4px 0 12px;
}
.api-key-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}
.api-key-row :deep(.el-input) {
  flex: 1;
  min-width: 0;
}
.key-probe {
  flex-shrink: 0;
  font-size: 12px;
  line-height: 1.2;
  font-weight: 600;
  white-space: nowrap;
}
.key-probe.checking,
.key-probe.idle,
.key-probe.empty {
  color: #8b93a1;
}
.key-probe.ok {
  color: #1f9d63;
}
.key-probe.invalid,
.key-probe.none {
  color: #d94848;
}
.key-probe.failed {
  color: #c2811a;
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
  gap: 10px;
  padding: 12px 14px;
  background: #f7f8fc;
  border-radius: 12px;
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
}
.bag-reward-card {
  flex-direction: column;
  align-items: stretch;
}
.bag-reward-form {
  width: 100%;
}
@media (max-width: 767px) {
  .settings {
    padding: 14px 12px;
  }
}
</style>
