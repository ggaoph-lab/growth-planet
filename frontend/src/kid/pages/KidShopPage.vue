<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
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
const preview = ref<Reward | null>(null)
const previewClose = ref<HTMLButtonElement | null>(null)
const brokenImages = ref<Record<number, boolean>>({})

const coinAvailable = computed(() => wallet.value?.coin?.available ?? 0)
const pointAvailable = computed(() => wallet.value?.points?.available ?? 0)
/** Wishes still quote growth-coin prices for physical gifts. */
const available = computed(() => coinAvailable.value)

/** Converted wishes become shop gifts — don't keep the "家长答应啦" card around. */
const activeWishes = computed(() => wishes.value.filter((w) => w.status !== 'converted'))

const kindEmoji: Record<string, string> = {
  physical: '🎁',
  family_activity: '🎉',
  virtual_decoration: '✨',
  screen_time: '📺',
}

function priceLabel(reward: Reward): string {
  const amount = reward.price ?? (reward.currency === 'point' ? reward.point_price : reward.coin_price)
  if (reward.currency === 'point') return `⭐ ${amount} ${t('common.pointsLabel')}`
  return `🪙 ${amount} ${t('common.growthCoin')}`
}

function canAfford(reward: Reward): boolean {
  const amount = reward.price ?? (reward.currency === 'point' ? reward.point_price : reward.coin_price)
  return reward.currency === 'point' ? pointAvailable.value >= amount : coinAvailable.value >= amount
}

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

function hasImage(reward: Reward): boolean {
  return Boolean(reward.image_url) && !brokenImages.value[reward.id]
}

function onImageError(reward: Reward): void {
  brokenImages.value = { ...brokenImages.value, [reward.id]: true }
  if (preview.value?.id === reward.id) closePreview()
}

function openPreview(reward: Reward): void {
  if (!hasImage(reward)) return
  tap()
  preview.value = reward
  void nextTick(() => previewClose.value?.focus())
}

function closePreview(): void {
  preview.value = null
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && preview.value) closePreview()
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

watch(preview, (reward) => {
  document.body.style.overflow = reward ? 'hidden' : ''
})

onMounted(() => {
  window.addEventListener('keydown', onKey)
  void load()
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})
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

    <section class="gifts">
      <div class="section-head">
        <h2>{{ t('kid.wishes.items') }}</h2>
        <p v-if="!loading" class="count">{{ rewards.length }}</p>
      </div>
      <el-empty v-if="!loading && rewards.length === 0" :description="t('kid.shopEmpty')" />
      <div v-else class="gift-grid">
        <article v-for="r in rewards" :key="r.id" class="gift-card">
          <button
            v-if="hasImage(r)"
            type="button"
            class="poster-hit"
            :aria-label="t('kid.viewImage', { name: r.name })"
            @click="openPreview(r)"
          >
            <span class="poster">
              <img :src="r.image_url!" :alt="r.name" @error="onImageError(r)" />
              <span class="zoom" aria-hidden="true">🔍</span>
            </span>
          </button>
          <div v-else class="poster fallback" aria-hidden="true">
            <span class="emoji">{{ kindEmoji[r.kind] ?? '🎁' }}</span>
          </div>
          <div class="copy">
            <h3>{{ r.name }}</h3>
            <p v-if="r.description" class="desc">{{ r.description }}</p>
            <p class="price">{{ priceLabel(r) }}</p>
          </div>
          <button type="button" class="big" :disabled="busy === r.id || !canAfford(r)" @click="redeem(r)">
            {{ t('common.redeem') }}
          </button>
        </article>
      </div>
    </section>

    <section class="wishes">
      <div class="section-head">
        <h2>{{ t('kid.wishes.title') }}</h2>
        <p v-if="!loading" class="count">{{ activeWishes.length }}</p>
      </div>
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
        <div class="wish-row">
          <input
            v-model="wishPrice"
            type="text"
            inputmode="numeric"
            class="input price-input"
            :placeholder="t('kid.wishes.pricePlaceholder')"
            :aria-label="t('kid.wishes.pricePlaceholder')"
          />
          <button type="submit" class="big wish-add" :disabled="wishBusy">{{ t('kid.wishes.add') }}</button>
        </div>
        <input
          v-model="wishNote"
          type="text"
          maxlength="500"
          class="input note"
          :placeholder="t('kid.wishes.notePlaceholder')"
          :aria-label="t('kid.wishes.notePlaceholder')"
        />
      </form>

      <el-empty v-if="!loading && activeWishes.length === 0" :description="t('kid.wishes.empty')" />

      <article v-for="w in activeWishes" :key="w.id" class="wish-card" :class="{ rejected: w.status === 'rejected' }">
        <div class="wish-top">
          <h3>{{ w.name }}</h3>
          <span class="badge">{{ t(`kid.wishes.status.${w.status}`) }}</span>
        </div>
        <p v-if="w.description" class="desc">{{ w.description }}</p>
        <WishProgress v-if="w.status === 'pending'" :available="available" :price="w.coin_price" />
      </article>
    </section>

    <div v-if="toast" class="toast">{{ toast }}</div>

    <Teleport to="body">
      <div v-if="preview?.image_url" class="lightbox" @click.self="closePreview">
        <div class="lightbox-panel" role="dialog" aria-modal="true" :aria-label="preview.name">
          <button ref="previewClose" type="button" class="lightbox-close" :aria-label="t('common.close')" @click="closePreview">
            ×
          </button>
          <img :src="preview.image_url" :alt="preview.name" />
          <p class="lightbox-caption">{{ preview.name }}</p>
          <p v-if="preview.description" class="lightbox-desc">{{ preview.description }}</p>
        </div>
      </div>
    </Teleport>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 32px;
  max-width: 960px;
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
.section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin: 0 0 10px;
}
.section-head h2 {
  margin: 0;
  font-size: 18px;
}
.count {
  margin: 0;
  min-width: 28px;
  padding: 2px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.14);
  font-size: 13px;
  font-weight: 700;
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

.gifts {
  margin-bottom: 28px;
}
.gift-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 300px));
  justify-content: start;
  gap: 16px;
}
.gift-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  padding: 10px;
  background: rgba(255, 255, 255, 0.06);
  border-radius: 16px;
}
.poster-hit {
  display: block;
  width: 100%;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  cursor: zoom-in;
}
.poster {
  position: relative;
  display: block;
  aspect-ratio: 2 / 3;
  border-radius: 14px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.18);
  box-shadow: 0 8px 18px rgba(20, 24, 60, 0.18);
}
.poster img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.poster::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 1;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.22), transparent 36%, rgba(0, 0, 0, 0.16));
  pointer-events: none;
}
.zoom {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.45);
  font-size: 16px;
}
.fallback {
  display: grid;
  place-items: center;
  background: linear-gradient(165deg, #3d4ed7 0%, #8ea0ff 100%);
}
.emoji {
  position: relative;
  z-index: 1;
  font-size: 64px;
}
.copy {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.copy h3 {
  margin: 0;
  font-size: 16px;
  line-height: 1.3;
}
.copy .desc {
  margin: 0;
  opacity: 0.75;
  font-size: 13px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.copy .price {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
}
.gift-card .big {
  margin-top: auto;
}
.lightbox {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px calc(24px + env(safe-area-inset-bottom));
  background: rgba(8, 10, 28, 0.82);
}
.lightbox-panel {
  position: relative;
  width: min(520px, 100%);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lightbox-panel img {
  width: 100%;
  max-height: min(78vh, 820px);
  object-fit: contain;
  border-radius: 16px;
  background: rgba(0, 0, 0, 0.28);
}
@media (orientation: landscape) {
  .lightbox {
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
  }
  .lightbox-panel {
    width: min(420px, 100%);
    max-height: 92vh;
    overflow-y: auto;
  }
  .lightbox-panel img {
    max-height: min(68vh, 520px);
  }
}
.lightbox-caption {
  margin: 0;
  text-align: center;
  color: #fff;
  font-size: 18px;
  font-weight: 700;
}
.lightbox-desc {
  margin: -4px 0 0;
  text-align: center;
  color: rgba(255, 255, 255, 0.82);
  font-size: 14px;
  line-height: 1.5;
}
.lightbox-close {
  position: fixed;
  top: max(12px, env(safe-area-inset-top));
  right: 12px;
  z-index: 1;
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
  font-size: 28px;
  line-height: 1;
  cursor: pointer;
}

.wishes {
  max-width: 560px;
  margin: 0 auto;
  padding-top: 4px;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}
@media (min-width: 768px) {
  .page { padding: 0 24px 40px; }
  .wishes { max-width: 720px; }
}
@media (min-width: 1024px) {
  .wishes { max-width: 800px; }
}
.wish-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}
.wish-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 8px;
}
.input {
  width: 100%;
  box-sizing: border-box;
  min-height: 44px;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(0, 0, 0, 0.2);
  color: #fff;
  font: inherit;
  font-size: 15px;
}
.input.note {
  min-height: 40px;
  font-size: 14px;
  opacity: 0.95;
}
.price-input {
  min-width: 0;
}
.wish-card {
  background: rgba(255, 255, 255, 0.08);
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 8px;
}
.wish-card.rejected {
  opacity: 0.65;
}
.wish-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
}
.wish-card h3 {
  margin: 0;
  font-size: 16px;
  flex: 1;
}
.badge {
  flex: none;
  padding: 3px 8px;
  border-radius: 999px;
  background: rgba(255, 196, 86, 0.22);
  color: #ffe7a8;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.wish-card.rejected .badge {
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.75);
}
.wish-card .desc {
  margin: 0 0 8px;
  opacity: 0.8;
  font-size: 13px;
}

.big {
  width: 100%;
  min-height: 48px;
  border: none;
  border-radius: 14px;
  background: var(--gp-sun);
  color: var(--gp-space-dark);
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
}
.wish-add {
  width: auto;
  min-width: 108px;
  padding: 0 16px;
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
  margin: 0 0 10px;
  font-size: 13px;
}
</style>
