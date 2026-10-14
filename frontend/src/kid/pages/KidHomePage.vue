<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidHome, Planet } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'
import { planetTitle } from '@/shared/planetTitle'
import PlanetTile from '../components/PlanetTile.vue'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

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
  collection: 'kid-collection',
}
const home = ref<KidHome | null>(null)
const error = ref('')
const toast = ref('')
let toastTimer: ReturnType<typeof setTimeout> | undefined

onMounted(async () => {
  try {
    home.value = await api.get<KidHome>('/kid/home')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('kid.planetSignalError')
  }
})

function open(planet: Planet): void {
  if (session.me?.child?.preferences.sounds) playTap()
  if (!planet.available) {
    toast.value = t('kid.building', { title: planetTitle(planet.key, planet.title) })
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => (toast.value = ''), 2200)
    return
  }
  const routeName = planetRoutes[planet.key]
  if (routeName) void router.push({ name: routeName })
}
</script>

<template>
  <main class="home">
    <p v-if="error" class="error">{{ error }}</p>
    <template v-if="home">
      <section class="greet">
        <h1>{{ t('kid.hello', { name: home.profile.nickname }) }}</h1>
        <p>{{ t('kid.ageGrade', { age: home.profile.age, grade: home.profile.grade }) }}</p>
      </section>
      <section class="grid">
        <PlanetTile v-for="p in home.planets" :key="p.key" :planet="p" :animate="session.me?.child?.preferences.animations ?? true" @open="open" />
      </section>
    </template>
    <div v-if="toast" class="toast" role="status">{{ toast }}</div>
  </main>
</template>

<style scoped>
.home {
  padding: 0 16px 32px;
  max-width: 960px;
  margin: 0 auto;
}
.greet h1 {
  margin: 8px 0 4px;
  font-size: 28px;
}
.greet p {
  margin: 0 0 20px;
  opacity: 0.8;
}
.grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
}
@media (min-width: 600px) {
  .grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
@media (min-width: 900px) {
  .grid {
    grid-template-columns: repeat(4, 1fr);
  }
}
@media (orientation: landscape) and (max-width: 899px) {
  .grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
@media (orientation: landscape) and (min-width: 600px) {
  .grid {
    grid-template-columns: repeat(4, 1fr);
  }
}
.error {
  text-align: center;
  padding: 40px 0;
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
