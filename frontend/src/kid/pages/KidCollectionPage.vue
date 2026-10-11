<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { CollectionStamp } from '@/shared/api/types'

const { t } = useI18n()
const router = useRouter()
const stamps = ref<CollectionStamp[]>([])
const error = ref('')

onMounted(async () => {
  try {
    const data = await api.get<{ stamps: CollectionStamp[] }>('/kid/collection')
    stamps.value = data.stamps
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  }
})
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.collection') }}</h1>
    </header>
    <p v-if="error" class="error">{{ error }}</p>
    <div class="grid">
      <article v-for="stamp in stamps" :key="stamp.key" class="stamp" :class="{ earned: stamp.earned }">
        <div class="icon">{{ stamp.earned ? '🏅' : '🔒' }}</div>
        <p>{{ stamp.title }}</p>
      </article>
    </div>
  </main>
</template>

<style scoped>
.page { padding: 0 16px 32px; max-width: 720px; margin: 0 auto; }
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.back { background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; }
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.stamp { text-align: center; padding: 16px; border-radius: 16px; background: rgba(255,255,255,0.06); opacity: 0.7; }
.stamp.earned { opacity: 1; background: rgba(91, 141, 239, 0.2); }
.icon { font-size: 32px; margin-bottom: 8px; }
.error { color: #ffb4b4; }
</style>
