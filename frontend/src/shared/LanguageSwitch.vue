<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { setAppLocale, type AppLocale } from '@/i18n'

const { t, locale } = useI18n()

const options = computed(() => [
  { value: 'zh-en' as AppLocale, label: t('lang.bilingual') },
  { value: 'zh-CN' as AppLocale, label: t('lang.zh') },
  { value: 'en' as AppLocale, label: t('lang.en') },
])

function onChange(value: AppLocale): void {
  setAppLocale(value)
}
</script>

<template>
  <label class="lang">
    <span class="sr">{{ t('common.language') }}</span>
    <select :value="locale" class="select" @change="onChange(($event.target as HTMLSelectElement).value as AppLocale)">
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
  </label>
</template>

<style scoped>
.lang {
  display: inline-flex;
  align-items: center;
}
.select {
  appearance: none;
  min-height: 32px;
  max-width: 196px;
  border: 1px solid #d0d3e0;
  border-radius: 999px;
  padding: 4px 28px 4px 12px;
  white-space: pre-line;
  background:
    #fff
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23555' d='M1 1l5 5 5-5'/%3E%3C/svg%3E")
    no-repeat right 10px center;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.3;
  color: #1b1f4b;
}
.select:focus {
  outline: 2px solid #8ea0ff;
  outline-offset: 2px;
}
.sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
