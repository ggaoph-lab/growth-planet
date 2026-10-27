<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type {
  AiModelChoice,
  AiModelList,
  AiSettings,
  AiTtsVoiceOption,
  Family,
  FamilyInvite,
  IeltsRewardSettings,
  ParentAccount,
  RewardPolicy,
  TaskCategory,
  TtsPreviewResult,
} from '@/shared/api/types'
import { setAppLocale, type AppLocale } from '@/i18n'
import { biLabel } from '@/locales/bilingual'
import { useSessionStore } from '@/stores/session'
import { planetTitle } from '@/shared/planetTitle'
import type { Planet } from '@/shared/api/types'
import { useIsPhone } from '@/shared/useMediaQuery'

const { t } = useI18n()
const isPhone = useIsPhone()
const route = useRoute()
const session = useSessionStore()
const tab = ref<'family' | 'accounts' | 'invites' | 'ai' | 'categories' | 'bag-rewards' | 'ielts-rewards' | 'planets'>('family')
const isSiteAdmin = computed(() => session.me?.user.is_site_admin === true)
const invites = ref<FamilyInvite[]>([])
const loadingInvites = ref(false)
const creatingInvite = ref(false)
const showCreateInvite = ref(false)
const createdInviteCode = ref('')
const inviteForm = reactive({ note: '', expires_in_days: 14 })
const planets = ref<(Planet & { enabled: boolean })[]>([])
const savingPlanet = ref<string | null>(null)
const { locale } = useI18n()
function label(zh: string, en: string): string {
  return biLabel(zh, en, locale.value)
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
const clearingAiSecret = ref<string | null>(null)
const creatingParent = ref(false)
const savingCategory = ref(false)
const savingBagReward = ref<string | null>(null)
const categories = ref<TaskCategory[]>([])
const bagRewardPolicies = ref<RewardPolicy[]>([])
const showCategoryForm = ref(false)
const editingCategoryId = ref<number | null>(null)
const categoryName = ref('')

const BAG_REWARD_CODES = ['item.checklist.before_school', 'item.checklist.after_school'] as const

// 雅思星球 (Word Atlas) reward caps; see backend IeltsRewardService.
const ieltsForm = reactive<IeltsRewardSettings>({
  rewards_enabled: true,
  points_daily_cap: 20,
  coins_daily_cap: 10,
  mastered_points: 2,
  mastered_daily_cap: 20,
})
const savingIelts = ref(false)
async function loadIelts(): Promise<void> {
  Object.assign(ieltsForm, await api.get<IeltsRewardSettings>('/parent/ielts/settings'))
}
async function saveIelts(): Promise<void> {
  savingIelts.value = true
  try {
    Object.assign(ieltsForm, await api.put<IeltsRewardSettings>('/parent/ielts/settings', {
      rewards_enabled: ieltsForm.rewards_enabled,
      points_daily_cap: Number(ieltsForm.points_daily_cap) || 0,
      coins_daily_cap: Number(ieltsForm.coins_daily_cap) || 0,
      mastered_points: Number(ieltsForm.mastered_points) || 0,
      mastered_daily_cap: Number(ieltsForm.mastered_daily_cap) || 0,
    }))
    ElMessage.success(t('parent.settings.ieltsRewardSaved'))
  } catch (e) {
    reportError(e)
  } finally {
    savingIelts.value = false
  }
}

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
const aiSubTab = ref<'content' | 'tts'>('content')
const aiCollapse = ref<string[]>(['gemini'])
const aiForm = reactive({
  provider: 'auto',
  gemini_model: 'gemini-3.1-flash-lite',
  qwen_model: 'qwen-turbo',
  openai_model: 'gpt-4o-mini',
  openai_base_url: 'https://api.openai.com/v1',
  gemini_api_key: '',
  qwen_api_key: '',
  openai_api_key: '',
  tts_provider: 'auto',
  tts_qwen_api_key: '',
  tts_elevenlabs_api_key: '',
  tts_voice: 'Cherry',
  tts_voice_en: 'Jennifer',
})

type AiClearKind = 'gemini' | 'qwen' | 'openai' | 'openai_base_url' | 'tts' | 'tts_eleven'

const defaultTtsVoicesZh: AiTtsVoiceOption[] = [
  { id: 'Cherry', name_zh: '芊悦', name_en: 'Cherry', desc_zh: '阳光积极、亲切自然', desc_en: 'Bright and friendly', tags: ['female', 'kids'] },
  { id: 'Serena', name_zh: '苏瑶', name_en: 'Serena', desc_zh: '温柔小姐姐', desc_en: 'Gentle and soft', tags: ['female', 'kids'] },
  { id: 'Ethan', name_zh: '晨煦', name_en: 'Ethan', desc_zh: '阳光温暖、有朝气', desc_en: 'Warm and energetic', tags: ['male'] },
  { id: 'Bella', name_zh: '萌宝', name_en: 'Bella', desc_zh: '可爱小萝莉音', desc_en: 'Cute childlike tone', tags: ['female', 'kids', 'child'] },
  { id: 'Bunny', name_zh: '萌小姬', name_en: 'Bunny', desc_zh: '萌属性小萝莉', desc_en: 'Very cute childlike', tags: ['female', 'kids', 'child'] },
]
const defaultTtsVoicesEn: AiTtsVoiceOption[] = [
  { id: 'Jennifer', name_zh: '詹妮弗', name_en: 'Jennifer', desc_zh: '品牌级电影质感美语女声', desc_en: 'Cinematic US English female', tags: ['female', 'us'] },
  { id: 'Aiden', name_zh: '艾登', name_en: 'Aiden', desc_zh: '美语大男孩', desc_en: 'US English young man', tags: ['male', 'us'] },
  { id: 'Bella', name_zh: '萌宝', name_en: 'Bella', desc_zh: '可爱童趣女声', desc_en: 'Cute childlike', tags: ['female', 'kids', 'child'] },
  { id: 'Cherry', name_zh: '芊悦', name_en: 'Cherry', desc_zh: '阳光亲切双语女声', desc_en: 'Bright bilingual female', tags: ['female', 'kids'] },
]
const ttsVoiceZhOptions = computed(() => ai.value?.tts?.voices?.zh?.length ? ai.value.tts.voices.zh : defaultTtsVoicesZh)
const ttsVoiceEnOptions = computed(() => ai.value?.tts?.voices?.en?.length ? ai.value.tts.voices.en : defaultTtsVoicesEn)
const previewingVoice = ref<'zh' | 'en' | null>(null)
let previewAudio: HTMLAudioElement | null = null

function voiceOptionLabel(voice: AiTtsVoiceOption): string {
  const name = label(voice.name_zh, voice.name_en)
  const tags: string[] = []
  if (voice.tags.includes('us')) tags.push(locale.value.startsWith('zh') ? '美音' : 'US')
  if (voice.tags.includes('child') || voice.tags.includes('kids')) tags.push(locale.value.startsWith('zh') ? '儿童向' : 'kids')
  const tagText = tags.length ? ` · ${tags.join('/')}` : ''
  return `${name}${tagText}`
}

function defaultAiCollapse(provider: string, settings: AiSettings | null): string[] {
  if (provider === 'gemini' || provider === 'qwen' || provider === 'openai') return [provider]
  if (!settings) return ['gemini']
  if (settings.gemini.source !== 'none') return ['gemini']
  if (settings.qwen.source !== 'none') return ['qwen']
  if (settings.openai.source !== 'none') return ['openai']
  return ['gemini']
}

function stopPreviewAudio(): void {
  if (!previewAudio) return
  previewAudio.pause()
  previewAudio.src = ''
  previewAudio = null
}

async function previewTtsVoice(kind: 'zh' | 'en'): Promise<void> {
  if (previewingVoice.value) return
  const voice = kind === 'zh' ? aiForm.tts_voice.trim() : aiForm.tts_voice_en.trim()
  if (!voice) return
  previewingVoice.value = kind
  stopPreviewAudio()
  try {
    const data = await api.post<TtsPreviewResult>('/parent/ai-settings/tts-preview', {
      voice,
      lang: kind === 'zh' ? 'zh-CN' : 'en-US',
    })
    if (!data.available || !data.audio_base64) {
      ElMessage.warning(
        data.hint === 'configure_dashscope_key'
          ? t('parent.settings.aiTtsPreviewNeedKey')
          : t('parent.settings.aiTtsPreviewFailed'),
      )
      return
    }
    const mime = data.mime || 'audio/mpeg'
    const audio = new Audio(`data:${mime};base64,${data.audio_base64}`)
    previewAudio = audio
    await audio.play()
  } catch (e) {
    reportError(e)
  } finally {
    previewingVoice.value = null
  }
}
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
  aiForm.openai_base_url = data.openai.base_url || 'https://api.openai.com/v1'
  aiForm.gemini_api_key = ''
  aiForm.qwen_api_key = ''
  aiForm.openai_api_key = ''
  aiForm.tts_provider = data.tts?.provider || 'auto'
  aiForm.tts_qwen_api_key = ''
  aiForm.tts_elevenlabs_api_key = ''
  aiForm.tts_voice = data.tts?.voice || 'Cherry'
  aiForm.tts_voice_en = data.tts?.voice_en || 'Jennifer'
  aiCollapse.value = defaultAiCollapse(data.provider, data)
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
  const seq = ++modelRequest[provider]
  modelLoading[provider] = true
  try {
    const body: { provider: AiProviderName; api_key?: string; preferred_model?: string; base_url?: string } = { provider }
    if (key) body.api_key = key
    const preferred = aiForm[modelField(provider)].trim()
    if (preferred) body.preferred_model = preferred
    if (provider === 'openai' && aiForm.openai_base_url.trim()) {
      body.base_url = aiForm.openai_base_url.trim()
    }
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
  if (modelLoading[provider]) return 'checking'
  return keyProbe[provider]
}

function canClearFamilyKey(provider: AiProviderName): boolean {
  return ai.value?.[provider].source === 'family'
}

function canClearTtsKey(): boolean {
  return ai.value?.tts?.source === 'tts'
}

function canClearElevenKey(): boolean {
  return ai.value?.tts?.elevenlabs?.source === 'family'
}

function canClearOpenAiBaseUrl(): boolean {
  return ai.value?.openai.base_url_customized === true
}

async function clearAiSecret(kind: AiClearKind): Promise<void> {
  if (clearingAiSecret.value) return
  const confirmMsg = kind === 'openai_base_url'
    ? t('parent.settings.clearOpenAiBaseUrlConfirm')
    : kind === 'tts'
      ? t('parent.settings.aiTtsClearKeyConfirm')
      : kind === 'tts_eleven'
        ? t('parent.settings.aiTtsClearElevenKeyConfirm')
        : t('parent.settings.clearThisKeyConfirm')
  const confirmTitle = kind === 'tts_eleven'
    ? t('parent.settings.aiTtsClearElevenKey')
    : t('parent.settings.clearThisKey')
  try {
    await ElMessageBox.confirm(confirmMsg, confirmTitle, { type: 'warning' })
  } catch {
    return
  }
  clearingAiSecret.value = kind
  try {
    const payload: Record<string, unknown> = {}
    if (kind === 'gemini') payload.clear_gemini_api_key = true
    else if (kind === 'qwen') payload.clear_qwen_api_key = true
    else if (kind === 'openai') payload.clear_openai_api_key = true
    else if (kind === 'openai_base_url') payload.clear_openai_base_url = true
    else if (kind === 'tts_eleven') payload.clear_tts_elevenlabs_api_key = true
    else payload.clear_tts_qwen_api_key = true

    ai.value = await api.put<AiSettings>('/parent/ai-settings', payload)
    if (kind === 'gemini') aiForm.gemini_api_key = ''
    else if (kind === 'qwen') aiForm.qwen_api_key = ''
    else if (kind === 'openai') aiForm.openai_api_key = ''
    else if (kind === 'openai_base_url') {
      aiForm.openai_base_url = ai.value.openai.base_url || 'https://api.openai.com/v1'
    } else if (kind === 'tts_eleven') {
      aiForm.tts_elevenlabs_api_key = ''
    } else {
      aiForm.tts_qwen_api_key = ''
    }
    if (kind === 'gemini' || kind === 'qwen' || kind === 'openai') {
      await loadModels(kind, '')
    } else if (kind === 'openai_base_url') {
      await loadModels('openai', draftKey('openai'))
    }
    ElMessage.success(
      kind === 'openai_base_url'
        ? t('parent.settings.clearOpenAiBaseUrlDone')
        : t('parent.settings.clearThisKeyDone'),
    )
  } catch (e) {
    reportError(e)
  } finally {
    clearingAiSecret.value = null
  }
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
  if (route.query.tab === 'ielts-rewards') tab.value = 'ielts-rewards'
  if (route.query.tab === 'invites' && isSiteAdmin.value) tab.value = 'invites'
}

async function loadInvites(): Promise<void> {
  if (!isSiteAdmin.value) {
    invites.value = []
    return
  }
  loadingInvites.value = true
  try {
    invites.value = await api.get<FamilyInvite[]>('/parent/invites')
  } finally {
    loadingInvites.value = false
  }
}

function inviteStatusLabel(status: FamilyInvite['status']): string {
  const map: Record<FamilyInvite['status'], string> = {
    available: t('parent.settings.inviteStatusAvailable'),
    used: t('parent.settings.inviteStatusUsed'),
    revoked: t('parent.settings.inviteStatusRevoked'),
    expired: t('parent.settings.inviteStatusExpired'),
  }
  return map[status]
}

async function createInvite(): Promise<void> {
  creatingInvite.value = true
  try {
    const created = await api.post<FamilyInvite>('/parent/invites', {
      note: inviteForm.note.trim() || null,
      expires_in_days: inviteForm.expires_in_days,
    })
    createdInviteCode.value = created.code ?? ''
    inviteForm.note = ''
    inviteForm.expires_in_days = 14
    showCreateInvite.value = false
    await loadInvites()
    ElMessage.success(t('parent.settings.inviteCreated'))
    if (createdInviteCode.value) {
      try {
        await navigator.clipboard.writeText(createdInviteCode.value)
        ElMessage.success(t('parent.settings.inviteCopied'))
      } catch {
        ElMessage.warning(t('parent.settings.inviteCopyFailed'))
      }
    }
  } catch (e) {
    reportError(e)
  } finally {
    creatingInvite.value = false
  }
}

async function revokeInvite(row: FamilyInvite): Promise<void> {
  try {
    await ElMessageBox.confirm(
      t('parent.settings.inviteRevokeConfirm', { prefix: row.code_prefix }),
      t('parent.settings.inviteRevoke'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await api.delete(`/parent/invites/${row.id}`)
    await loadInvites()
    ElMessage.success(t('parent.settings.inviteRevoked'))
  } catch (e) {
    reportError(e)
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    syncTabFromRoute()
    if (route.query.tab === 'planets') tab.value = 'planets'
    await Promise.all([loadPlanets(), loadFamily(), loadParents(), loadAi(), loadCategories(), loadBagRewards(), loadIelts(), loadInvites()])
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
      tts_provider: aiForm.tts_provider,
      tts_voice: aiForm.tts_voice,
      tts_voice_en: aiForm.tts_voice_en,
    }
    if (aiForm.gemini_api_key.trim()) payload.gemini_api_key = aiForm.gemini_api_key.trim()
    if (aiForm.qwen_api_key.trim()) payload.qwen_api_key = aiForm.qwen_api_key.trim()
    if (aiForm.openai_api_key.trim()) payload.openai_api_key = aiForm.openai_api_key.trim()
    if (aiForm.openai_base_url.trim()) payload.openai_base_url = aiForm.openai_base_url.trim()
    if (aiForm.tts_qwen_api_key.trim()) payload.tts_qwen_api_key = aiForm.tts_qwen_api_key.trim()
    if (aiForm.tts_elevenlabs_api_key.trim()) payload.tts_elevenlabs_api_key = aiForm.tts_elevenlabs_api_key.trim()

    ai.value = await api.put<AiSettings>('/parent/ai-settings', payload)
    aiForm.gemini_api_key = ''
    aiForm.qwen_api_key = ''
    aiForm.openai_api_key = ''
    aiForm.tts_qwen_api_key = ''
    aiForm.tts_elevenlabs_api_key = ''
    aiForm.provider = ai.value.provider
    aiForm.gemini_model = ai.value.gemini.model
    aiForm.qwen_model = ai.value.qwen.model
    aiForm.openai_model = ai.value.openai.model
    aiForm.openai_base_url = ai.value.openai.base_url || 'https://api.openai.com/v1'
    aiForm.tts_provider = ai.value.tts?.provider || 'auto'
    aiForm.tts_voice = ai.value.tts?.voice || 'Cherry'
    aiForm.tts_voice_en = ai.value.tts?.voice_en || 'Jennifer'
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
  if (source === 'tts') return t('parent.settings.aiTtsSourceTts')
  if (source === 'ai') return t('parent.settings.aiTtsSourceAi')
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

watch(() => aiForm.provider, (provider) => {
  if (provider === 'gemini' || provider === 'qwen' || provider === 'openai') {
    if (!aiCollapse.value.includes(provider)) {
      aiCollapse.value = [...aiCollapse.value, provider]
    }
  }
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
watch(() => aiForm.openai_base_url, () => {
  if (tab.value === 'ai') scheduleModels('openai', draftKey('openai'))
})

watch(() => route.query.tab, syncTabFromRoute)

onMounted(load)
onUnmounted(stopPreviewAudio)
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

      <el-tab-pane v-if="isSiteAdmin" :label="t('parent.settings.tabInvites')" name="invites">
        <p class="hint top">{{ t('parent.settings.invitesHint') }}</p>
        <div class="block-head">
          <h3>{{ t('parent.settings.tabInvites') }}</h3>
          <el-button type="primary" size="small" @click="showCreateInvite = true">{{ t('parent.settings.createInvite') }}</el-button>
        </div>
        <el-alert
          v-if="createdInviteCode"
          type="success"
          :closable="true"
          show-icon
          class="invite-alert"
          @close="createdInviteCode = ''"
        >
          <template #title>{{ t('parent.settings.invitePlainCode') }}</template>
          <code class="invite-code">{{ createdInviteCode }}</code>
        </el-alert>
        <el-table v-if="!isPhone" v-loading="loadingInvites" :data="invites" stripe empty-text="">
          <el-table-column prop="code_prefix" :label="t('parent.settings.invitePrefix')" width="100" />
          <el-table-column prop="note" :label="t('parent.settings.inviteNote')" min-width="120" />
          <el-table-column :label="t('parent.settings.inviteStatus')" width="100">
            <template #default="{ row }">{{ inviteStatusLabel((row as FamilyInvite).status) }}</template>
          </el-table-column>
          <el-table-column prop="expires_at" :label="t('parent.settings.inviteExpiresAt')" min-width="160" />
          <el-table-column :label="t('common.actions')" width="100">
            <template #default="{ row }">
              <el-button
                v-if="(row as FamilyInvite).status === 'available'"
                link
                type="danger"
                @click="revokeInvite(row as FamilyInvite)"
              >
                {{ t('parent.settings.inviteRevoke') }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else v-loading="loadingInvites" class="phone-list">
          <article v-for="row in invites" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.code_prefix }}…</div>
              <div class="phone-meta">{{ row.note || '—' }} · {{ inviteStatusLabel(row.status) }}</div>
              <div class="phone-meta">{{ row.expires_at }}</div>
            </div>
            <div v-if="row.status === 'available'" class="phone-ops">
              <el-button size="small" type="danger" @click="revokeInvite(row)">{{ t('parent.settings.inviteRevoke') }}</el-button>
            </div>
          </article>
          <p v-if="!loadingInvites && invites.length === 0" class="hint">{{ t('parent.settings.noInvites') }}</p>
        </div>
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

      <el-tab-pane :label="t('parent.settings.tabIeltsRewards')" name="ielts-rewards">
        <p class="hint top">{{ t('parent.settings.ieltsRewardsHint') }}</p>
        <el-form label-position="top" class="ielts-reward-form" @submit.prevent="saveIelts">
          <el-form-item :label="t('parent.settings.ieltsRewardsEnabled')">
            <el-switch v-model="ieltsForm.rewards_enabled" />
          </el-form-item>
          <el-form-item :label="t('parent.settings.ieltsPointsDailyCap')">
            <el-input-number v-model="ieltsForm.points_daily_cap" :min="0" :max="200" controls-position="right" />
          </el-form-item>
          <el-form-item :label="t('parent.settings.ieltsCoinsDailyCap')">
            <el-input-number v-model="ieltsForm.coins_daily_cap" :min="0" :max="200" controls-position="right" />
          </el-form-item>
          <el-form-item :label="t('parent.settings.ieltsMasteredPoints')">
            <el-input-number v-model="ieltsForm.mastered_points" :min="0" :max="20" controls-position="right" />
          </el-form-item>
          <el-form-item :label="t('parent.settings.ieltsMasteredDailyCap')">
            <el-input-number v-model="ieltsForm.mastered_daily_cap" :min="0" :max="200" controls-position="right" />
          </el-form-item>
          <p class="hint">{{ t('parent.settings.ieltsRewardsRule') }}</p>
          <el-button type="primary" :loading="savingIelts" native-type="submit">{{ t('common.save') }}</el-button>
        </el-form>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabAi')" name="ai">
        <el-tabs v-model="aiSubTab" class="ai-subtabs">
          <el-tab-pane :label="t('parent.settings.aiSubTabContent')" name="content">
            <p class="hint top">{{ t('parent.settings.aiContentHint') }}</p>
            <el-form label-position="top" @submit.prevent="saveAi">
              <el-form-item :label="t('parent.settings.aiProvider')">
                <el-select v-model="aiForm.provider" style="width: 100%">
                  <el-option :label="t('parent.settings.aiProviderAuto')" value="auto" />
                  <el-option label="Gemini" value="gemini" />
                  <el-option :label="t('common.homeworkParserQwen')" value="qwen" />
                  <el-option :label="t('parent.settings.aiProviderOpenAi')" value="openai" />
                  <el-option :label="t('common.homeworkParserRules')" value="rules" />
                </el-select>
                <div class="hint">{{ t('parent.settings.aiProviderHint') }}</div>
              </el-form-item>

              <el-collapse v-model="aiCollapse" class="ai-collapse">
                <el-collapse-item name="gemini">
                  <template #title>
                    <div class="ai-collapse-title">
                      <span class="ai-collapse-name">Gemini</span>
                      <span v-if="ai" class="ai-collapse-meta">{{ sourceLabel(ai.gemini.source) }}</span>
                      <span
                        v-if="keyProbeLabel('gemini')"
                        class="key-probe"
                        :class="keyProbeState('gemini')"
                      >{{ keyProbeLabel('gemini') }}</span>
                    </div>
                  </template>
                  <div v-if="ai?.gemini.hint" class="ai-status">{{ ai.gemini.hint }}</div>
                  <el-form-item :label="t('parent.settings.apiKey')">
                    <div class="api-key-row">
                      <el-input
                        v-model="aiForm.gemini_api_key"
                        type="password"
                        show-password
                        :placeholder="t('parent.settings.apiKeyPlaceholder')"
                      />
                      <el-button
                        v-if="canClearFamilyKey('gemini')"
                        link
                        type="danger"
                        :loading="clearingAiSecret === 'gemini'"
                        :disabled="clearingAiSecret !== null && clearingAiSecret !== 'gemini'"
                        @click="clearAiSecret('gemini')"
                      >
                        {{ t('parent.settings.clearThisKey') }}
                      </el-button>
                    </div>
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
                </el-collapse-item>

                <el-collapse-item name="qwen">
                  <template #title>
                    <div class="ai-collapse-title">
                      <span class="ai-collapse-name">{{ t('common.homeworkParserQwen') }}</span>
                      <span v-if="ai" class="ai-collapse-meta">{{ sourceLabel(ai.qwen.source) }}</span>
                      <span
                        v-if="keyProbeLabel('qwen')"
                        class="key-probe"
                        :class="keyProbeState('qwen')"
                      >{{ keyProbeLabel('qwen') }}</span>
                    </div>
                  </template>
                  <div v-if="ai?.qwen.hint" class="ai-status">{{ ai.qwen.hint }}</div>
                  <el-form-item :label="t('parent.settings.apiKey')">
                    <div class="api-key-row">
                      <el-input
                        v-model="aiForm.qwen_api_key"
                        type="password"
                        show-password
                        :placeholder="t('parent.settings.apiKeyPlaceholder')"
                      />
                      <el-button
                        v-if="canClearFamilyKey('qwen')"
                        link
                        type="danger"
                        :loading="clearingAiSecret === 'qwen'"
                        :disabled="clearingAiSecret !== null && clearingAiSecret !== 'qwen'"
                        @click="clearAiSecret('qwen')"
                      >
                        {{ t('parent.settings.clearThisKey') }}
                      </el-button>
                    </div>
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
                </el-collapse-item>

                <el-collapse-item name="openai">
                  <template #title>
                    <div class="ai-collapse-title">
                      <span class="ai-collapse-name">{{ t('parent.settings.aiProviderOpenAi') }}</span>
                      <span v-if="ai" class="ai-collapse-meta">{{ sourceLabel(ai.openai.source) }}</span>
                      <span
                        v-if="keyProbeLabel('openai')"
                        class="key-probe"
                        :class="keyProbeState('openai')"
                      >{{ keyProbeLabel('openai') }}</span>
                    </div>
                  </template>
                  <p class="hint">{{ t('parent.settings.openaiRelayHint') }}</p>
                  <div v-if="ai" class="ai-status">
                    <span v-if="ai.openai.hint">{{ ai.openai.hint }}</span>
                    <span v-if="ai.openai.base_url_customized">{{ ai.openai.hint ? ' · ' : '' }}{{ t('parent.settings.openaiRelayActive') }}</span>
                  </div>
                  <el-form-item :label="t('parent.settings.openaiBaseUrl')">
                    <div class="api-key-row">
                      <el-input
                        v-model="aiForm.openai_base_url"
                        maxlength="512"
                        :placeholder="t('parent.settings.openaiBaseUrlPlaceholder')"
                      />
                      <el-button
                        v-if="canClearOpenAiBaseUrl()"
                        link
                        type="danger"
                        :loading="clearingAiSecret === 'openai_base_url'"
                        :disabled="clearingAiSecret !== null && clearingAiSecret !== 'openai_base_url'"
                        @click="clearAiSecret('openai_base_url')"
                      >
                        {{ t('parent.settings.clearOpenAiBaseUrl') }}
                      </el-button>
                    </div>
                    <div class="hint">{{ t('parent.settings.openaiBaseUrlHint') }}</div>
                  </el-form-item>
                  <el-form-item :label="t('parent.settings.apiKey')">
                    <div class="api-key-row">
                      <el-input
                        v-model="aiForm.openai_api_key"
                        type="password"
                        show-password
                        :placeholder="t('parent.settings.apiKeyPlaceholder')"
                      />
                      <el-button
                        v-if="canClearFamilyKey('openai')"
                        link
                        type="danger"
                        :loading="clearingAiSecret === 'openai'"
                        :disabled="clearingAiSecret !== null && clearingAiSecret !== 'openai'"
                        @click="clearAiSecret('openai')"
                      >
                        {{ t('parent.settings.clearThisKey') }}
                      </el-button>
                    </div>
                  </el-form-item>
                  <el-form-item :label="t('parent.settings.model')">
                    <el-select
                      v-if="modelOptions.openai.length > 0 || modelLoading.openai"
                      v-model="aiForm.openai_model"
                      filterable
                      allow-create
                      default-first-option
                      style="width: 100%"
                      :loading="modelLoading.openai"
                      :placeholder="t('parent.settings.modelsLoading')"
                      @change="markModelTouched('openai')"
                    >
                      <el-option v-for="model in modelOptions.openai" :key="model.id" :label="model.label === model.id ? model.id : `${model.label} · ${model.id}`" :value="model.id" />
                    </el-select>
                    <el-input v-else v-model="aiForm.openai_model" maxlength="128" @input="markModelTouched('openai')" />
                    <div v-if="modelNote.openai" class="hint">{{ modelNote.openai }}</div>
                    <div class="hint">{{ t('parent.settings.openaiModelHint') }}</div>
                  </el-form-item>
                </el-collapse-item>
              </el-collapse>

              <el-button type="primary" class="ai-save" :loading="savingAi" native-type="submit">{{ t('common.save') }}</el-button>
            </el-form>
          </el-tab-pane>

          <el-tab-pane :label="t('parent.settings.aiSubTabTts')" name="tts">
            <p class="hint top">{{ t('parent.settings.aiTtsHint') }}</p>
            <div v-if="ai?.tts" class="ai-status">
              {{ sourceLabel(ai.tts.source) }}
              <span v-if="ai.tts.hint"> · {{ ai.tts.hint }}</span>
            </div>
            <el-form label-position="top" @submit.prevent="saveAi">
              <el-form-item :label="t('parent.settings.aiTtsProvider')">
                <el-select v-model="aiForm.tts_provider" style="width: 100%">
                  <el-option :label="t('parent.settings.aiTtsProviderAuto')" value="auto" />
                  <el-option :label="t('parent.settings.aiTtsProviderQwen')" value="qwen" />
                  <el-option :label="t('parent.settings.aiTtsProviderEleven')" value="elevenlabs" />
                  <el-option :label="t('parent.settings.aiTtsProviderEdge')" value="edge" />
                </el-select>
              </el-form-item>
              <el-form-item :label="t('parent.settings.aiTtsKey')">
                <div class="api-key-row">
                  <el-input
                    v-model="aiForm.tts_qwen_api_key"
                    type="password"
                    show-password
                    :placeholder="t('parent.settings.apiKeyPlaceholder')"
                  />
                  <el-button
                    v-if="canClearTtsKey()"
                    link
                    type="danger"
                    :loading="clearingAiSecret === 'tts'"
                    :disabled="clearingAiSecret !== null && clearingAiSecret !== 'tts'"
                    @click="clearAiSecret('tts')"
                  >
                    {{ t('parent.settings.aiTtsClearKey') }}
                  </el-button>
                </div>
                <div class="hint">{{ t('parent.settings.aiTtsKeyHint') }}</div>
              </el-form-item>
              <el-form-item :label="t('parent.settings.aiTtsElevenKey')">
                <div v-if="ai?.tts?.elevenlabs" class="ai-status">
                  {{ sourceLabel(ai.tts.elevenlabs.source) }}
                  <span v-if="ai.tts.elevenlabs.hint"> · {{ ai.tts.elevenlabs.hint }}</span>
                </div>
                <div class="api-key-row">
                  <el-input
                    v-model="aiForm.tts_elevenlabs_api_key"
                    type="password"
                    show-password
                    :placeholder="t('parent.settings.apiKeyPlaceholder')"
                  />
                  <el-button
                    v-if="canClearElevenKey()"
                    link
                    type="danger"
                    :loading="clearingAiSecret === 'tts_eleven'"
                    :disabled="clearingAiSecret !== null && clearingAiSecret !== 'tts_eleven'"
                    @click="clearAiSecret('tts_eleven')"
                  >
                    {{ t('parent.settings.aiTtsClearElevenKey') }}
                  </el-button>
                </div>
                <div class="hint">{{ t('parent.settings.aiTtsElevenKeyHint') }}</div>
              </el-form-item>
              <el-form-item :label="t('parent.settings.aiTtsVoice')">
                <div class="tts-voice-row">
                  <el-select v-model="aiForm.tts_voice" filterable allow-create default-first-option class="tts-voice-select">
                    <el-option
                      v-for="voice in ttsVoiceZhOptions"
                      :key="voice.id"
                      :label="voiceOptionLabel(voice)"
                      :value="voice.id"
                    />
                  </el-select>
                  <el-button
                    :loading="previewingVoice === 'zh'"
                    :disabled="!aiForm.tts_voice.trim() || previewingVoice === 'en'"
                    @click="previewTtsVoice('zh')"
                  >
                    {{ previewingVoice === 'zh' ? t('parent.settings.aiTtsPreviewing') : t('parent.settings.aiTtsPreview') }}
                  </el-button>
                </div>
                <div class="hint">{{ t('parent.settings.aiTtsVoiceHint') }}</div>
              </el-form-item>
              <el-form-item :label="t('parent.settings.aiTtsVoiceEn')">
                <div class="tts-voice-row">
                  <el-select v-model="aiForm.tts_voice_en" filterable allow-create default-first-option class="tts-voice-select">
                    <el-option
                      v-for="voice in ttsVoiceEnOptions"
                      :key="voice.id"
                      :label="voiceOptionLabel(voice)"
                      :value="voice.id"
                    />
                  </el-select>
                  <el-button
                    :loading="previewingVoice === 'en'"
                    :disabled="!aiForm.tts_voice_en.trim() || previewingVoice === 'zh'"
                    @click="previewTtsVoice('en')"
                  >
                    {{ previewingVoice === 'en' ? t('parent.settings.aiTtsPreviewing') : t('parent.settings.aiTtsPreview') }}
                  </el-button>
                </div>
              </el-form-item>
              <el-button type="primary" class="ai-save" :loading="savingAi" native-type="submit">{{ t('common.save') }}</el-button>
            </el-form>
          </el-tab-pane>
        </el-tabs>
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

    <el-dialog v-model="showCreateInvite" :title="t('parent.settings.createInvite')" width="420px" destroy-on-close>
      <el-form label-position="top">
        <el-form-item :label="t('parent.settings.inviteNote')">
          <el-input v-model="inviteForm.note" maxlength="64" :placeholder="t('parent.settings.inviteNotePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('parent.settings.inviteExpiresDays')">
          <el-input-number v-model="inviteForm.expires_in_days" :min="1" :max="365" style="width: 100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreateInvite = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="creatingInvite" @click="createInvite">{{ t('common.create') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.settings {
  width: min(100%, 1100px);
  background: #fff;
  border-radius: 16px;
  padding: 28px 32px;
  border: 1px solid #e8ebf3;
  box-shadow: 0 1px 2px rgba(28, 32, 51, 0.04);
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
.invite-alert {
  margin-bottom: 16px;
}
.invite-code {
  display: block;
  margin-top: 6px;
  font-size: 18px;
  letter-spacing: 0.08em;
  word-break: break-all;
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
.ai-subtabs :deep(.el-tabs__header) {
  margin-bottom: 14px;
}
.ai-subtabs :deep(.el-tabs__nav-wrap::after) {
  height: 1px;
}
.ai-collapse {
  margin: 4px 0 16px;
  border: none;
}
.ai-collapse :deep(.el-collapse-item__header) {
  height: auto;
  min-height: 44px;
  line-height: 1.3;
  padding: 8px 0;
  font-weight: 600;
}
.ai-collapse :deep(.el-collapse-item__wrap) {
  border-bottom: none;
}
.ai-collapse :deep(.el-collapse-item__content) {
  padding: 4px 0 16px;
}
.ai-collapse :deep(.el-collapse-item) {
  border-bottom: 1px solid #eef0f5;
}
.ai-collapse-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  padding-right: 8px;
  width: 100%;
}
.ai-collapse-name {
  font-size: 14px;
  color: #1f2330;
}
.ai-collapse-meta {
  font-size: 12px;
  font-weight: 500;
  color: #6b6f80;
}
.ai-save {
  margin-top: 4px;
}
.tts-voice-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}
.tts-voice-select {
  flex: 1;
  min-width: 0;
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
.ielts-reward-form {
  max-width: 420px;
}
@media (max-width: 767px) {
  .settings {
    padding: 16px 14px;
  }
}
</style>
