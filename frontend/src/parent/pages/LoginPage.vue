<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import en from 'element-plus/es/locale/lang/en'
import LanguageSwitch from '@/shared/LanguageSwitch.vue'
import { ApiError, ensureCsrfCookie } from '@/shared/api/client'
import { warmGet } from '@/shared/api/warmGet'
import type { ApprovalRequest, Dashboard } from '@/shared/api/types'
import { useSessionStore } from '@/stores/session'

const { t, locale } = useI18n()
const epLocale = computed(() => (locale.value === 'en' ? en : zhCn))
const router = useRouter()
const session = useSessionStore()

const form = reactive({ login: '', password: '' })
const error = ref('')
const submitting = ref(false)

function prefetchParentShell(): void {
  void import('@/parent/ParentLayout.vue')
  void import('@/parent/pages/DashboardPage.vue')
}

onMounted(() => {
  void ensureCsrfCookie()
  void session.checkDevice()
  prefetchParentShell()
})

async function submit(): Promise<void> {
  if (!form.login || !form.password) return
  submitting.value = true
  error.value = ''
  try {
    prefetchParentShell()
    await session.loginParent(form.login, form.password)
    // Overlap destination data with the route chunk resolve.
    void warmGet<Dashboard>('/parent/dashboard')
    void warmGet<ApprovalRequest[]>('/parent/approvals', { status: 'PENDING' })
    await router.replace({ name: 'parent-dashboard' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('auth.loginFailed')
  } finally {
    submitting.value = false
    form.password = ''
  }
}
</script>

<template>
  <el-config-provider :locale="epLocale">
    <div class="login">
      <div class="lang-row"><LanguageSwitch /></div>
      <div class="card">
        <div class="logo">🪐</div>
        <h1>{{ t('app.name') }}</h1>
        <p class="sub">{{ t('auth.parentLogin') }}</p>
        <el-form label-position="top" @submit.prevent="submit">
          <el-form-item :label="t('auth.loginPlaceholder')">
            <el-input v-model="form.login" size="large" autocomplete="username" autocapitalize="off" />
          </el-form-item>
          <el-form-item :label="t('auth.password')" :error="error">
            <el-input v-model="form.password" size="large" type="password" show-password autocomplete="current-password" />
          </el-form-item>
          <el-button type="primary" size="large" native-type="submit" class="submit" :loading="submitting">{{ t('auth.login') }}</el-button>
        </el-form>
        <RouterLink v-if="session.deviceAuthorized" :to="{ name: 'kid-select' }" class="kid-link">👧 {{ t('auth.toKidMode') }}</RouterLink>
      </div>
    </div>
  </el-config-provider>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  background: radial-gradient(circle at 20% 20%, #3b3f8f 0, var(--gp-space-dark) 60%);
}
.card {
  width: min(400px, 100%);
  background: #fff;
  border-radius: 20px;
  padding: 32px 28px;
  text-align: center;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}
.logo {
  font-size: 48px;
}
h1 {
  margin: 4px 0;
}
.sub {
  color: #6b6f80;
  margin: 0 0 20px;
}
.el-form {
  text-align: left;
}
.submit {
  width: 100%;
  margin-top: 8px;
}
.lang-row {
  width: min(400px, 100%);
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}
.kid-link {
  display: inline-block;
  margin-top: 20px;
  padding: 10px;
  color: #5b8def;
  text-decoration: none;
}
</style>
