<script setup lang="ts">
import EnglishLearning from '@/kid/components/EnglishLearning.vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()
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
</style>
