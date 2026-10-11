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
  min-height: 32px;
  max-width: 160px;
  border: 1px solid #d0d3e0;
  border-radius: 8px;
  padding: 4px 8px;
  background: #fff;
  font-size: 12px;
  color: inherit;
}
.sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
