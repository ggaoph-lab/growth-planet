<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { Family } from '@/shared/api/types'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()
const form = reactive({ name: '', timezone: '' })
const loading = ref(true)
const saving = ref(false)
const timezones = Intl.supportedValuesOf('timeZone')

onMounted(async () => {
  try {
    const family = await api.get<Family>('/parent/family')
    form.name = family.name
    form.timezone = family.timezone
  } finally {
    loading.value = false
  }
})

async function save(): Promise<void> {
  saving.value = true
  try {
    const family = await api.patch<Family>('/parent/family', { name: form.name, timezone: form.timezone })
    form.name = family.name
    form.timezone = family.timezone
    await session.load(true)
    ElMessage.success(t('common.saved'))
  } catch (e) {
    if (e instanceof ApiError && e.code !== 'SUDO_REQUIRED') ElMessage.error(e.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-loading="loading" class="settings">
    <el-form label-position="top" @submit.prevent="save">
      <el-form-item :label="t('parent.family.name')">
        <el-input v-model="form.name" maxlength="64" />
      </el-form-item>
      <el-form-item :label="t('parent.family.timezone')">
        <el-select v-model="form.timezone" filterable style="width: 100%">
          <el-option v-for="tz in timezones" :key="tz" :label="tz" :value="tz" />
        </el-select>
        <div class="hint">{{ t('parent.family.settingsHint') }}</div>
      </el-form-item>
      <el-button type="primary" :loading="saving" native-type="submit">{{ t('common.save') }}</el-button>
    </el-form>
  </div>
</template>

<style scoped>
.settings {
  max-width: 560px;
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
</style>
