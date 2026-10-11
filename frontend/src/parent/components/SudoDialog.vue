<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError, api, setSudoHandler } from '@/shared/api/client'

const { t } = useI18n()
const visible = ref(false)
const password = ref('')
const error = ref('')
const submitting = ref(false)
let resolver: ((ok: boolean) => void) | null = null

function ask(): Promise<boolean> {
  password.value = ''
  error.value = ''
  visible.value = true
  return new Promise((resolve) => {
    resolver = resolve
  })
}

function finish(ok: boolean): void {
  visible.value = false
  resolver?.(ok)
  resolver = null
}

async function submit(): Promise<void> {
  if (!password.value) return
  submitting.value = true
  error.value = ''
  try {
    await api.post('/auth/confirm-password', { password: password.value })
    finish(true)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('auth.sudoFailed')
  } finally {
    submitting.value = false
  }
}

onMounted(() => setSudoHandler(ask))
onBeforeUnmount(() => setSudoHandler(null))
</script>

<template>
  <el-dialog v-model="visible" :title="t('auth.sudoTitle')" width="min(420px, 92vw)" :close-on-click-modal="false" @closed="resolver && finish(false)">
    <p class="hint">{{ t('auth.sudoHint') }}</p>
    <el-form @submit.prevent="submit">
      <el-form-item :error="error">
        <el-input v-model="password" type="password" show-password autocomplete="current-password" :placeholder="t('auth.password')" size="large" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="finish(false)">{{ t('common.cancel') }}</el-button>
      <el-button type="primary" :loading="submitting" @click="submit">{{ t('common.confirm') }}</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.hint {
  margin: 0 0 12px;
  color: var(--el-text-color-secondary);
}
</style>
