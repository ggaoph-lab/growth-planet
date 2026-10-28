<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type { CollectionStamp } from '@/shared/api/types'

const { t } = useI18n()
const router = useRouter()
const cachedCollection = peekWarmGet<{ stamps: CollectionStamp[] }>('/kid/collection')
const stamps = ref<CollectionStamp[]>(cachedCollection?.stamps ?? [])
const error = ref('')
const loading = ref(cachedCollection == null)

onMounted(async () => {
  try {
    const data = await warmGet<{ stamps: CollectionStamp[] }>('/kid/collection')
    stamps.value = data.stamps
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
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
    <p v-if="loading" class="error">{{ t('common.loading') }}</p>
    <div v-else class="grid">
      <article v-for="stamp in stamps" :key="stamp.key" class="stamp" :class="{ earned: stamp.earned }">
        <div class="icon">{{ stamp.earned ? '🏅' : '🔒' }}</div>
        <p>{{ stamp.title }}</p>
      </article>
    </div>
  </main>
</template>

<style scoped>
.page { width: 100%; padding: 0 var(--kid-gutter) var(--kid-page-bottom); max-width: var(--kid-content-max); margin: 0 auto; }
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.back { background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
@media (min-width: 600px) {
  .grid { grid-template-columns: repeat(3, 1fr); }
}
@media (min-width: 900px) {
  .grid { grid-template-columns: repeat(4, 1fr); }
}
@media (orientation: landscape) and (max-width: 899px) {
  .grid { grid-template-columns: repeat(3, 1fr); }
}
@media (orientation: landscape) and (min-width: 600px) {
  .grid { grid-template-columns: repeat(4, 1fr); }
}
@media (min-width: 1200px) {
  .grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
}
.stamp { min-width: 0; overflow-wrap: anywhere; }
.stamp { text-align: center; padding: 16px; border-radius: 16px; background: rgba(255,255,255,0.06); opacity: 0.7; }
.stamp.earned { opacity: 1; background: rgba(91, 141, 239, 0.2); }
.icon { font-size: 32px; margin-bottom: 8px; }
.error { color: #ffb4b4; }
</style>
