<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { FoodItem } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const foods = ref<FoodItem[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref<number | null>(null)
const toast = ref('')

const toggles = ref({
  looked: false,
  smelled: false,
  touched: false,
  tasted_voluntarily: false,
  ate: false,
})
const saidTasty = ref<boolean | null>(null)
const willingAgain = ref<boolean | null>(null)

function toggleLabel(key: keyof typeof toggles.value): string {
  const map: Record<keyof typeof toggles.value, string> = {
    looked: t('common.looked'),
    smelled: t('common.smelled'),
    touched: t('common.touched'),
    tasted_voluntarily: t('common.tastedVoluntarily'),
    ate: t('common.ate'),
  }
  return map[key]
}

async function load(): Promise<void> {
  loading.value = true
  try {
    foods.value = await api.get<FoodItem[]>('/kid/foods')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

async function record(food: FoodItem): Promise<void> {
  if (session.me?.child?.preferences.sounds) playTap()
  busy.value = food.id
  try {
    await api.postIdempotent('/kid/food-records', {
      food_id: food.id,
      ...toggles.value,
      said_tasty: saidTasty.value,
      willing_again: willingAgain.value,
    })
    toast.value = t('common.foodRecordSaved', { name: food.name })
    setTimeout(() => (toast.value = ''), 2200)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.saveFailed')
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
      <h1>{{ t('kid.food') }}</h1>
      <span class="spacer" />
    </header>

    <section class="prefs">
      <label v-for="(v, key) in toggles" :key="key" class="check">
        <input v-model="toggles[key as keyof typeof toggles]" type="checkbox" />
        {{ toggleLabel(key as keyof typeof toggles) }}
      </label>
      <div class="row">
        <span>{{ t('common.saidTasty') }}？</span>
        <button type="button" class="chip" :class="{ on: saidTasty === true }" @click="saidTasty = true">{{ t('common.yes') }}</button>
        <button type="button" class="chip" :class="{ on: saidTasty === false }" @click="saidTasty = false">{{ t('common.no') }}</button>
      </div>
      <div class="row">
        <span>{{ t('common.willingEatAgain') }}</span>
        <button type="button" class="chip" :class="{ on: willingAgain === true }" @click="willingAgain = true">{{ t('common.yes') }}</button>
        <button type="button" class="chip" :class="{ on: willingAgain === false }" @click="willingAgain = false">{{ t('common.no') }}</button>
      </div>
    </section>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <article v-for="f in foods" :key="f.id" class="card">
      <h2>{{ f.name }}</h2>
      <p v-if="f.category" class="meta">{{ f.category }}</p>
      <button type="button" class="big" :disabled="busy === f.id" @click="record(f)">{{ t('common.recordExploration') }}</button>
    </article>

    <el-empty v-if="!loading && foods.length === 0" :description="t('kid.foodEmpty')" />

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
  margin-bottom: 16px;
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
}
.prefs {
  background: rgba(255, 255, 255, 0.08);
  border-radius: 16px;
  padding: 14px;
  margin-bottom: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.check {
  display: flex;
  align-items: center;
  gap: 8px;
}
.row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.chip {
  border: 1px solid rgba(255, 255, 255, 0.3);
  background: transparent;
  color: inherit;
  border-radius: 999px;
  padding: 6px 12px;
  cursor: pointer;
}
.chip.on {
  background: var(--gp-accent, #5b8def);
  border-color: transparent;
}
.card {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 16px;
  margin-bottom: 12px;
}
.card h2 {
  margin: 0 0 4px;
}
.meta {
  margin: 0 0 12px;
  opacity: 0.8;
}
.big {
  width: 100%;
  min-height: 48px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(135deg, #7bc96f, #57a773);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}
.toast {
  position: fixed;
  left: 50%;
  bottom: 24px;
  transform: translateX(-50%);
  padding: 12px 20px;
  border-radius: 999px;
  background: #fff;
  color: var(--gp-space-dark);
  font-weight: 600;
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
