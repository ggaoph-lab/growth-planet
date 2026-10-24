<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import en from 'element-plus/es/locale/lang/en'
import { ElMessage } from 'element-plus'
import LanguageSwitch from '@/shared/LanguageSwitch.vue'
import { ApiError, api, ensureCsrfCookie } from '@/shared/api/client'

const { t, locale } = useI18n()
const epLocale = computed(() => (locale.value === 'en' ? en : zhCn))
const router = useRouter()

const timezones = Intl.supportedValuesOf('timeZone')
const form = reactive({
  invite_code: '',
  family_name: '',
  timezone: 'Asia/Shanghai',
  display_name: '',
  username: '',
  password: '',
  password_confirmation: '',
})
const error = ref('')
const submitting = ref(false)

onMounted(() => {
  void ensureCsrfCookie()
})

async function submit(): Promise<void> {
  if (form.password !== form.password_confirmation) {
    error.value = t('auth.passwordMismatch')
    return
  }
  submitting.value = true
  error.value = ''
  try {
    await ensureCsrfCookie()
    await api.post('/auth/register', {
      invite_code: form.invite_code.trim(),
      family_name: form.family_name.trim(),
      timezone: form.timezone,
      display_name: form.display_name.trim(),
      username: form.username.trim(),
      password: form.password,
    })
    ElMessage.success(t('auth.registerSuccess'))
    await router.replace({ name: 'login' })
  } catch (e) {
    error.value = e instanceof ApiError
      ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
      : t('auth.registerFailed')
  } finally {
    submitting.value = false
    form.password = ''
    form.password_confirmation = ''
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
        <p class="sub">{{ t('auth.registerTitle') }}</p>
        <el-form label-position="top" @submit.prevent="submit">
          <el-form-item :label="t('auth.inviteCode')" required>
            <el-input v-model="form.invite_code" size="large" maxlength="64" autocapitalize="characters" autocomplete="off" />
          </el-form-item>
          <el-form-item :label="t('auth.familyName')" required>
            <el-input v-model="form.family_name" size="large" maxlength="64" />
          </el-form-item>
          <el-form-item :label="t('parent.family.timezone')" required>
            <el-select v-model="form.timezone" filterable size="large" style="width: 100%">
              <el-option v-for="tz in timezones" :key="tz" :label="tz" :value="tz" />
            </el-select>
          </el-form-item>
          <el-form-item :label="t('auth.displayName')" required>
            <el-input v-model="form.display_name" size="large" maxlength="32" :placeholder="t('auth.displayNameHint')" />
          </el-form-item>
          <el-form-item :label="t('auth.username')" required>
            <el-input v-model="form.username" size="large" maxlength="32" autocomplete="username" autocapitalize="off" />
          </el-form-item>
          <el-form-item :label="t('auth.password')" required>
            <el-input v-model="form.password" size="large" type="password" show-password autocomplete="new-password" />
            <div class="hint">{{ t('parent.settings.passwordHint') }}</div>
          </el-form-item>
          <el-form-item :label="t('auth.confirmPassword')" :error="error" required>
            <el-input v-model="form.password_confirmation" size="large" type="password" show-password autocomplete="new-password" />
          </el-form-item>
          <el-button type="primary" size="large" native-type="submit" class="submit" :loading="submitting">
            {{ t('auth.register') }}
          </el-button>
        </el-form>
        <RouterLink :to="{ name: 'login' }" class="kid-link">{{ t('auth.backToLogin') }}</RouterLink>
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
  width: min(420px, 100%);
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
.hint {
  color: #6b6f80;
  font-size: 12px;
  margin-top: 6px;
}
.submit {
  width: 100%;
  margin-top: 8px;
}
.lang-row {
  width: min(420px, 100%);
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
