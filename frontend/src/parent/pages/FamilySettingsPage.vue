<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { AiSettings, Family, ParentAccount, TaskCategory } from '@/shared/api/types'
import { setAppLocale, type AppLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const session = useSessionStore()
const tab = ref<'family' | 'accounts' | 'ai' | 'categories'>('family')
const loading = ref(true)
const savingFamily = ref(false)
const savingPassword = ref(false)
const savingProfile = ref(false)
const savingAi = ref(false)
const creatingParent = ref(false)
const savingCategory = ref(false)
const categories = ref<TaskCategory[]>([])
const showCategoryForm = ref(false)
const editingCategoryId = ref<number | null>(null)
const categoryName = ref('')

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

const ai = ref<AiSettings | null>(null)
const aiForm = reactive({
  provider: 'auto',
  gemini_model: 'gemini-2.5-flash',
  qwen_model: 'qwen-turbo',
  gemini_api_key: '',
  qwen_api_key: '',
  clear_gemini_api_key: false,
  clear_qwen_api_key: false,
})

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
  aiForm.gemini_api_key = ''
  aiForm.qwen_api_key = ''
  aiForm.clear_gemini_api_key = false
  aiForm.clear_qwen_api_key = false
}

async function loadCategories(): Promise<void> {
  categories.value = await api.get<TaskCategory[]>('/parent/task-categories')
}

function syncTabFromRoute(): void {
  if (route.query.tab === 'categories') tab.value = 'categories'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    syncTabFromRoute()
    await Promise.all([loadFamily(), loadParents(), loadAi(), loadCategories()])
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
    }
    if (aiForm.clear_gemini_api_key) payload.clear_gemini_api_key = true
    else if (aiForm.gemini_api_key.trim()) payload.gemini_api_key = aiForm.gemini_api_key.trim()
    if (aiForm.clear_qwen_api_key) payload.clear_qwen_api_key = true
    else if (aiForm.qwen_api_key.trim()) payload.qwen_api_key = aiForm.qwen_api_key.trim()

    ai.value = await api.put<AiSettings>('/parent/ai-settings', payload)
    aiForm.gemini_api_key = ''
    aiForm.qwen_api_key = ''
    aiForm.clear_gemini_api_key = false
    aiForm.clear_qwen_api_key = false
    aiForm.provider = ai.value.provider
    aiForm.gemini_model = ai.value.gemini.model
    aiForm.qwen_model = ai.value.qwen.model
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

watch(() => route.query.tab, syncTabFromRoute)

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="settings">
    <el-tabs v-model="tab">
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
          <el-table :data="parents" stripe>
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
        <el-table :data="categories" stripe :empty-text="t('parent.settings.noCategories')">
          <el-table-column prop="name" :label="t('parent.settings.categoryName')" min-width="160" />
          <el-table-column :label="t('common.actions')" width="140">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEditCategory(row as TaskCategory)">{{ t('common.edit') }}</el-button>
              <el-button link type="danger" @click="deleteCategory(row as TaskCategory)">{{ t('common.delete') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane :label="t('parent.settings.tabAi')" name="ai">
        <p class="hint top">{{ t('parent.settings.aiHint') }}</p>
        <el-form label-position="top" @submit.prevent="saveAi">
          <el-form-item :label="t('parent.settings.aiProvider')">
            <el-select v-model="aiForm.provider" style="width: 100%">
              <el-option :label="t('parent.settings.aiProviderAuto')" value="auto" />
              <el-option label="Gemini" value="gemini" />
              <el-option :label="t('common.homeworkParserQwen')" value="qwen" />
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
            <el-input
              v-model="aiForm.gemini_api_key"
              type="password"
              show-password
              :placeholder="t('parent.settings.apiKeyPlaceholder')"
              :disabled="aiForm.clear_gemini_api_key"
            />
          </el-form-item>
          <el-form-item>
            <el-checkbox v-model="aiForm.clear_gemini_api_key">{{ t('parent.settings.clearFamilyKey') }}</el-checkbox>
          </el-form-item>
          <el-form-item :label="t('parent.settings.model')">
            <el-input v-model="aiForm.gemini_model" maxlength="64" />
          </el-form-item>

          <el-divider content-position="left">{{ t('common.homeworkParserQwen') }}</el-divider>
          <div v-if="ai" class="ai-status">
            {{ sourceLabel(ai.qwen.source) }}
            <span v-if="ai.qwen.hint"> · {{ ai.qwen.hint }}</span>
          </div>
          <el-form-item :label="t('parent.settings.apiKey')">
            <el-input
              v-model="aiForm.qwen_api_key"
              type="password"
              show-password
              :placeholder="t('parent.settings.apiKeyPlaceholder')"
              :disabled="aiForm.clear_qwen_api_key"
            />
          </el-form-item>
          <el-form-item>
            <el-checkbox v-model="aiForm.clear_qwen_api_key">{{ t('parent.settings.clearFamilyKey') }}</el-checkbox>
          </el-form-item>
          <el-form-item :label="t('parent.settings.model')">
            <el-input v-model="aiForm.qwen_model" maxlength="64" />
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
</style>
