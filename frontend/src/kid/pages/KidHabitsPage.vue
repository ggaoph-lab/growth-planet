<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { ParentHabit } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const habits = ref<ParentHabit[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref<number | null>(null)
const toast = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    habits.value = await api.get<ParentHabit[]>('/kid/habits')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function checkIn(habit: ParentHabit): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = habit.id
  try {
    await api.postIdempotent(`/kid/habits/${habit.id}/records`)
    toast.value = t('common.checkInSuccess', { icon: habit.icon ?? '⭐' })
    setTimeout(() => (toast.value = ''), 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.checkInFailed')
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.habits') }}</h1>
      <RouterLink class="link" :to="{ name: 'kid-tasks' }">{{ t('kid.navTasks') }}</RouterLink>
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <el-empty v-if="!loading && habits.length === 0" :description="t('kid.habitsEmpty')" />

    <article v-for="h in habits" :key="h.id" class="card">
      <div class="row">
        <span class="icon">{{ h.icon ?? '⭐' }}</span>
        <div>
          <h2>{{ h.title }}</h2>
          <p class="meta">+{{ h.xp_reward }} {{ t('growth.xp') }} · +{{ h.coin_reward }} {{ t('common.growthCoin') }}</p>
        </div>
      </div>
      <button type="button" class="big" :disabled="busy === h.id" @click="checkIn(h)">{{ t('common.checkIn') }}</button>
    </article>

    <div v-if="toast" class="toast">{{ toast }}</div>
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
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 16px;
}
.head h1 {
  margin: 0;
  font-size: 22px;
  flex: 1;
  text-align: center;
}
.back,
.link {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  text-decoration: none;
  cursor: pointer;
  min-height: 44px;
}
.card {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  padding: 20px;
  margin-bottom: 14px;
}
.row {
  display: flex;
  gap: 14px;
  align-items: center;
  margin-bottom: 14px;
}
.icon {
  font-size: 36px;
}
.card h2 {
  margin: 0 0 4px;
  font-size: 20px;
}
.meta {
  margin: 0;
  opacity: 0.8;
  font-size: 14px;
}
.big {
  width: 100%;
  min-height: 56px;
  border: none;
  border-radius: 16px;
  background: var(--gp-sun);
  color: var(--gp-space-dark);
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
}
.big:disabled {
  opacity: 0.5;
}
.toast {
  position: fixed;
  left: 50%;
  bottom: calc(32px + env(safe-area-inset-bottom));
  transform: translateX(-50%);
  padding: 14px 24px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.95);
  color: var(--gp-space-dark);
  font-weight: 700;
}
.err {
  color: #ffb4b4;
  text-align: center;
}
.hint {
  text-align: center;
  opacity: 0.7;
}
</style>
