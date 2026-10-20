<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import MirrorPuzzle from '../components/MirrorPuzzle.vue'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const child = computed(() => session.me?.child ?? null)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-games' })">{{ t('kid.games.backGames') }}</button>
      <h1>{{ t('kid.mirrorTitle') }}</h1>
      <span class="spacer" />
    </header>
    <MirrorPuzzle
      :animate="child?.preferences.animations ?? true"
      :sounds="child?.preferences.sounds ?? false"
    />
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 32px;
  max-width: 560px;
  margin: 0 auto;
}
.head {
  display: flex;
  align-items: center;
  margin-bottom: 8px;
}
.head h1 {
  flex: 1;
  text-align: center;
  margin: 0;
  font-size: 22px;
}
.spacer {
  width: 72px;
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
  touch-action: manipulation;
}
.back:focus-visible {
  outline: 3px solid var(--gp-sun);
  outline-offset: 2px;
}
@media (min-width: 768px) and (min-height: 600px) {
  .page {
    max-width: 960px;
    padding: 0 24px 40px;
  }
  .head h1 {
    font-size: 28px;
  }
}
@media (orientation: landscape) and (max-height: 520px) {
  .page {
    max-width: none;
    padding: 0 12px 8px;
  }
  .head {
    margin-bottom: 2px;
  }
  .head h1 {
    font-size: 16px;
  }
  .back {
    padding: 4px 10px;
  }
  .spacer {
    width: 56px;
  }
}
</style>
