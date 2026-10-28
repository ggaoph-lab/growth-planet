<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import type { Planet } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { unlockSpeak } from '@/shared/speak'
import { useKidHomeStore } from '@/stores/kidHome'
import { useSessionStore } from '@/stores/session'
import { planetTitle } from '@/shared/planetTitle'
import { prefetchKidRoute, prefetchKidRouteChunks } from '@/kid/prefetch'
import PlanetTile from '../components/PlanetTile.vue'
import { storeToRefs } from 'pinia'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const kidHome = useKidHomeStore()
const { home } = storeToRefs(kidHome)

const planetRoutes: Record<string, string> = {
  task_planet: 'kid-tasks',
  wallet: 'kid-wallet',
  shop: 'kid-shop',
  bag_planet: 'kid-bag',
  schedule_planet: 'kid-schedule',
  explore_planet: 'kid-explore',
  food_diary: 'kid-food',
  movie_planet: 'kid-movies',
  english_planet: 'kid-english',
  english_beta: 'kid-english-beta',
  ielts_planet: 'kid-ielts',
  encyclopedia_planet: 'kid-encyclopedia',
  poetry_planet: 'kid-poetry',
  game_planet: 'kid-games',
  collection: 'kid-collection',
}
const error = ref('')
const toast = ref('')
let toastTimer: ReturnType<typeof setTimeout> | undefined

onMounted(async () => {
  try {
    const data = await kidHome.load()
    const names = (data.planets ?? [])
      .filter((planet) => planet.available)
      .map((planet) => planetRoutes[planet.key])
      .filter((name): name is string => Boolean(name))
    prefetchKidRouteChunks(names)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  }
})

function warmPlanet(planet: Planet): void {
  const routeName = planetRoutes[planet.key]
  if (planet.available && routeName) prefetchKidRoute(routeName)
}

function open(planet: Planet): void {
  if (session.me?.child?.preferences.sounds) playTap()
  // Unlock TTS during this tap so fairy-tale read-aloud works on phone/iPad browsers.
  if (planet.key === 'english_planet' && planet.available) void unlockSpeak()
  if (!planet.available) {
    toast.value = t('kid.building', { title: planetTitle(planet.key, planet.title) })
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => (toast.value = ''), 2200)
    return
  }
  const routeName = planetRoutes[planet.key]
  if (!routeName) return
  // Prefer data already warmed on the home screen; still kick chunk+API for a cold tap.
  prefetchKidRoute(routeName)
  void router.push({ name: routeName })
}
</script>

<template>
  <main class="home">
    <p v-if="error" class="error">{{ error }}</p>
    <p v-else-if="!home" class="loading" aria-live="polite">{{ t('common.loading') }}</p>
    <template v-else>
      <section class="greet">
        <h1>{{ t('kid.hello', { name: home.profile.nickname }) }}</h1>
        <p>{{ t('kid.ageGrade', { age: home.profile.age, grade: home.profile.grade }) }}</p>
      </section>
      <section class="grid">
        <PlanetTile
          v-for="p in home.planets"
          :key="p.key"
          :planet="p"
          :animate="session.me?.child?.preferences.animations ?? true"
          @prefetch="warmPlanet"
          @open="open"
        />
      </section>
    </template>
    <div v-if="toast" class="toast" role="status">{{ toast }}</div>
  </main>
</template>

<style scoped>
.home {
  width: 100%;
  padding: 8px var(--kid-gutter) var(--kid-page-bottom);
  max-width: 1600px;
  margin: 0 auto;
}
.greet {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px 24px;
  margin-bottom: 24px;
  padding-bottom: 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.14);
}
.greet h1 {
  margin: 0;
  font-size: clamp(24px, 2.5vw, 36px);
  overflow-wrap: anywhere;
}
.greet p {
  margin: 0;
  opacity: 0.8;
}
.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: clamp(12px, 1.5vw, 24px);
}
@media (min-width: 640px) {
  .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (min-width: 960px) {
  .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (min-width: 1200px) {
  .grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
}
@media (min-width: 1600px) {
  .grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
}
@media (max-width: 639px) {
  .greet { gap: 4px; margin-bottom: 16px; padding-bottom: 16px; }
  .greet p { width: 100%; font-size: 14px; }
}
@media (orientation: landscape) and (max-height: 600px) and (min-width: 600px) {
  .home { padding-top: 4px; }
  .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .greet { margin-bottom: 12px; padding-bottom: 8px; }
  .greet h1 { font-size: 22px; }
  .greet p { font-size: 14px; }
}
.error,
.loading {
  text-align: center;
  padding: 40px 0;
  opacity: 0.85;
}
.toast {
  position: fixed;
  left: 50%;
  bottom: calc(32px + env(safe-area-inset-bottom));
  transform: translateX(-50%);
  max-width: 90vw;
  padding: 14px 20px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.95);
  color: var(--gp-space-dark);
  font-weight: 600;
  text-align: center;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
</style>
