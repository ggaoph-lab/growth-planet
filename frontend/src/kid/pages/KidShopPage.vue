<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidWallet, Reward, Wish } from '@/shared/api/types'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'
import WishProgress from '@/kid/components/WishProgress.vue'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const rewards = ref<Reward[]>([])
const wishes = ref<Wish[]>([])
const wallet = ref<KidWallet | null>(null)
const loading = ref(true)
const error = ref('')
const busy = ref<number | null>(null)
const wishBusy = ref(false)
const toast = ref('')
const wishName = ref('')
const wishNote = ref('')
const wishPrice = ref('')

const available = computed(() => wallet.value?.coin.available ?? 0)

const kindEmoji: Record<string, string> = {
  physical: '🎁',
  family_activity: '🎉',
  virtual_decoration: '✨',
}

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [shop, wishList, walletData] = await Promise.all([
      api.get<Reward[]>('/kid/shop'),
      api.get<Wish[]>('/kid/wishes'),
      api.get<KidWallet>('/kid/wallet'),
    ])
    rewards.value = shop
    wishes.value = wishList
    wallet.value = walletData
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

function parsePrice(raw: string): number | null {
  const trimmed = raw.trim()
  if (trimmed === '') return null
  if (!/^\d+$/.test(trimmed)) return Number.NaN
  const price = Number(trimmed)
  return price >= 1 ? price : Number.NaN
}

async function addWish(): Promise<void> {
  const name = wishName.value.trim()
  if (!name) {
    error.value = t('kid.wishes.fillName')
    return
  }
  const coinPrice = parsePrice(wishPrice.value)
  if (Number.isNaN(coinPrice)) {
    error.value = t('kid.wishes.badPrice')
    return
  }
  tap()
  wishBusy.value = true
  error.value = ''
  try {
    const wish = await api.postIdempotent<Wish>('/kid/wishes', {
      name,
      description: wishNote.value.trim() || null,
      coin_price: coinPrice,
    })
    wishes.value.unshift(wish)
    wishName.value = ''
    wishNote.value = ''
    wishPrice.value = ''
    toast.value = t('kid.wishes.saved')
    setTimeout(() => (toast.value = ''), 2500)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.createFailed')
  } finally {
    wishBusy.value = false
  }
}

async function redeem(reward: Reward): Promise<void> {
  tap()
  busy.value = reward.id
  try {
    await api.postIdempotent('/kid/redemptions', { reward_id: reward.id })
    toast.value = t('common.redeemSent')
    setTimeout(() => (toast.value = ''), 2500)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.redeemFailed')
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
      <h1>{{ t('kid.shop') }}</h1>
      <RouterLink class="link" :to="{ name: 'kid-wallet' }">{{ t('kid.navWallet') }}</RouterLink>
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <section class="wishes">
      <h2>{{ t('kid.wishes.title') }}</h2>
      <p class="hint left">{{ t('kid.wishes.hint') }}</p>
      <form class="wish-form" @submit.prevent="addWish">
        <input
          v-model="wishName"
          type="text"
          maxlength="128"
          class="input"
          :placeholder="t('kid.wishes.namePlaceholder')"
          :aria-label="t('kid.wishes.namePlaceholder')"
        />
        <textarea
          v-model="wishNote"
          maxlength="500"
          rows="2"
          class="input"
          :placeholder="t('kid.wishes.notePlaceholder')"
          :aria-label="t('kid.wishes.notePlaceholder')"
        />
        <input
          v-model="wishPrice"
          type="text"
          inputmode="numeric"
          class="input"
          :placeholder="t('kid.wishes.pricePlaceholder')"
          :aria-label="t('kid.wishes.pricePlaceholder')"
        />
        <button type="submit" class="big wish-add" :disabled="wishBusy">{{ t('kid.wishes.add') }}</button>
      </form>

      <el-empty v-if="!loading && wishes.length === 0" :description="t('kid.wishes.empty')" />

      <article v-for="w in wishes" :key="w.id" class="card">
        <h3>{{ w.name }}</h3>
        <p v-if="w.description" class="desc">{{ w.description }}</p>
        <p class="status">{{ t(`kid.wishes.status.${w.status}`) }}</p>
        <WishProgress :available="available" :price="w.coin_price" />
      </article>
    </section>

    <section>
      <h2>{{ t('kid.wishes.items') }}</h2>
      <el-empty v-if="!loading && rewards.length === 0" :description="t('kid.shopEmpty')" />
      <article v-for="r in rewards" :key="r.id" class="card">
        <div class="row">
          <img v-if="r.image_url" class="thumb" :src="r.image_url" :alt="r.name" />
          <span v-else class="emoji">{{ kindEmoji[r.kind] ?? '🎁' }}</span>
          <div>
            <h3>{{ r.name }}</h3>
            <p v-if="r.description" class="desc">{{ r.description }}</p>
            <p class="price">🪙 {{ r.coin_price }} {{ t('common.growthCoin') }}</p>
          </div>
        </div>
        <button type="button" class="big" :disabled="busy === r.id" @click="redeem(r)">{{ t('common.redeem') }}</button>
      </article>
    </section>

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
h2 {
  margin: 8px 0 8px;
  font-size: 18px;
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
.wishes {
  margin-bottom: 28px;
}
.wish-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 16px;
}
.input {
  width: 100%;
  box-sizing: border-box;
  min-height: 48px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(0, 0, 0, 0.2);
  color: #fff;
  font: inherit;
  font-size: 16px;
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
  margin-bottom: 14px;
}
.emoji {
  font-size: 36px;
}
.thumb {
  width: 64px;
  height: 64px;
  object-fit: cover;
  border-radius: 14px;
  flex: none;
  background: rgba(255, 255, 255, 0.08);
}
.card h3 {
  margin: 0 0 4px;
  font-size: 20px;
}
.desc,
.status {
  margin: 0 0 8px;
  opacity: 0.85;
  font-size: 14px;
}
.price {
  margin: 0;
  font-size: 15px;
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
.wish-add {
  background: linear-gradient(135deg, #6b9fff, #5b8def);
  color: #fff;
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
.hint.left {
  text-align: left;
  margin: 0 0 12px;
  font-size: 14px;
}
</style>
