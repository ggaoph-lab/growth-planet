<script setup lang="ts">
import { defineAsyncComponent, h } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()

// Route shell paints immediately; the heavy learning UI loads as a second chunk.
const EnglishLearning = defineAsyncComponent({
  loader: () => import('@/kid/components/EnglishLearning.vue'),
  delay: 0,
  loadingComponent: {
    setup: () => () => h('p', { class: 'hint' }, t('common.loading')),
  },
})
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.english') }}</h1>
    </header>
    <EnglishLearning />
  </main>
</template>

<style scoped>
.page { padding: 0 16px 32px; max-width: 1120px; margin: 0 auto; }
@media (min-width: 768px) {
  .page { padding: 0 24px 40px; }
}
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.back { background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; }
:deep(.hint) { opacity: 0.75; padding: 24px 0; }
</style>
