<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { peekWarmGet, warmGet } from '@/shared/api/warmGet'
import type { ChildLedger, KidWallet, LedgerEntry } from '@/shared/api/types'
import { ledgerEntryKind, ledgerEntryTitle } from '@/shared/ledgerLabels'
import { formatInTimezone } from '@/shared/time'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const wallet = ref<KidWallet | null>(peekWarmGet<KidWallet>('/kid/wallet') ?? null)
const loading = ref(wallet.value == null)
const error = ref('')

const activeAsset = ref<'point' | 'coin' | null>(null)
const ledger = ref<ChildLedger | null>(null)
const ledgerLoading = ref(false)
const ledgerError = ref('')

const ledgerTitle = computed(() =>
  activeAsset.value === 'coin' ? t('kid.ledgerTitleCoins') : t('kid.ledgerTitlePoints'),
)

onMounted(async () => {
  try {
    wallet.value = await warmGet<KidWallet>('/kid/wallet')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
})

async function openLedger(asset: 'point' | 'coin'): Promise<void> {
  if (activeAsset.value === asset && ledger.value) {
    activeAsset.value = null
    ledger.value = null
    return
  }
  activeAsset.value = asset
  ledgerLoading.value = true
  ledgerError.value = ''
  try {
    ledger.value = await api.get<ChildLedger>('/kid/wallet/ledger', { asset })
  } catch (e) {
    ledger.value = null
    ledgerError.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    ledgerLoading.value = false
  }
}

function amountText(entry: LedgerEntry): string {
  const sign = entry.amount > 0 ? '+' : ''
  return `${sign}${entry.amount}`
}

function whenText(entry: LedgerEntry): string {
  if (entry.created_at) return formatInTimezone(entry.created_at, session.timezone, 'MM-DD HH:mm')
  return entry.business_date ?? ''
}

</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.wallet') }}</h1>
      <span class="spacer" />
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <template v-if="wallet">
      <section class="hero">
        <div class="orb xp">
          <div class="label">{{ t('common.xpLabel') }}</div>
          <div class="num">{{ wallet.xp?.balance ?? 0 }}</div>
          <div class="sub">
            {{ t('kid.levelLine', { level: wallet.xp?.level ?? 1 }) }}
            <template v-if="(wallet.xp?.to_next ?? 0) > 0"> · {{ t('kid.toNextXp', { n: wallet.xp?.to_next ?? 0 }) }}</template>
          </div>
          <div class="bar" aria-hidden="true">
            <span :style="{ width: `${Math.round((wallet.xp?.progress || 0) * 100)}%` }" />
          </div>
          <div class="sub muted">{{ t('common.lifetimeEarned', { n: wallet.xp?.lifetime_earned ?? 0 }) }}</div>
        </div>
        <button
          type="button"
          class="orb points clickable"
          :class="{ active: activeAsset === 'point' }"
          :aria-pressed="activeAsset === 'point'"
          @click="openLedger('point')"
        >
          <div class="label">{{ t('common.pointsLabel') }}</div>
          <div class="num">{{ wallet.points?.available ?? 0 }}</div>
          <div class="sub">{{ t('growth.available') }} · {{ t('growth.held') }} {{ wallet.points?.held ?? 0 }}</div>
          <div class="sub muted tip-line">{{ t('kid.tapForLedger') }}</div>
        </button>
        <button
          type="button"
          class="orb coin clickable"
          :class="{ active: activeAsset === 'coin' }"
          :aria-pressed="activeAsset === 'coin'"
          @click="openLedger('coin')"
        >
          <div class="label">{{ t('common.growthCoin') }}</div>
          <div class="num">{{ wallet.coin?.available ?? 0 }}</div>
          <div class="sub">{{ t('growth.available') }} · {{ t('growth.held') }} {{ wallet.coin?.held ?? 0 }}</div>
          <div class="sub muted tip-line">{{ t('kid.tapForLedger') }}</div>
        </button>
      </section>

      <section v-if="activeAsset" class="ledger" aria-live="polite">
        <div class="ledger-head">
          <h2>{{ ledgerTitle }}</h2>
          <button type="button" class="close" @click="activeAsset = null; ledger = null">{{ t('kid.closeLedger') }}</button>
        </div>
        <p v-if="ledgerLoading" class="hint">{{ t('common.loading') }}</p>
        <p v-else-if="ledgerError" class="err">{{ ledgerError }}</p>
        <p v-else-if="!ledger?.entries?.length" class="hint">{{ t('kid.ledgerEmpty') }}</p>
        <ul v-else class="entries">
          <li v-for="entry in ledger.entries" :key="entry.ulid" class="entry">
            <div class="entry-main">
              <span class="entry-note">{{ ledgerEntryTitle(entry) }}</span>
              <span class="entry-amt" :class="{ plus: entry.amount > 0, minus: entry.amount < 0 }">{{ amountText(entry) }}</span>
            </div>
            <div class="entry-meta">
              <span>{{ whenText(entry) }} · {{ ledgerEntryKind(entry) }}</span>
              <span>{{ t('kid.ledgerBalanceAfter', { n: entry.balance_after }) }}</span>
            </div>
          </li>
        </ul>
      </section>

      <p class="tip">{{ t('kid.shopTip') }}</p>
      <RouterLink class="shop-link" :to="{ name: 'kid-shop' }">{{ t('kid.goShop') }}</RouterLink>
    </template>
  </main>
</template>

<style scoped>
.page {
  padding: 0 16px 32px;
  max-width: 560px;
  margin: 0 auto;
}
@media (min-width: 768px) {
  .page {
    max-width: 720px;
    padding: 0 24px 40px;
  }
}
@media (min-width: 1024px) {
  .page { max-width: 960px; }
}
.head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}
.head h1 {
  margin: 0;
  font-size: 22px;
  flex: 1;
  text-align: center;
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
  min-height: 44px;
}
.spacer {
  width: 72px;
}
.hero {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
  margin-bottom: 20px;
}
@media (min-width: 640px) {
  .hero {
    grid-template-columns: 1fr 1fr 1fr;
  }
}
.orb {
  border-radius: 20px;
  padding: 20px;
  text-align: center;
  border: none;
  color: inherit;
  font: inherit;
}
.orb.clickable {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.orb.clickable:hover,
.orb.clickable:focus-visible {
  transform: translateY(-2px);
  outline: none;
}
.orb.clickable.active {
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55);
}
.orb.xp {
  background: linear-gradient(160deg, #5b8def, #3d5fc4);
  color: #fff;
}
.orb.points {
  background: linear-gradient(160deg, #6bcf8e, #3fa66a);
  color: #fff;
}
.orb.coin {
  background: linear-gradient(160deg, #f5c542, #e89b1f);
  color: var(--gp-space-dark);
}
.label {
  font-size: 14px;
  opacity: 0.9;
}
.num {
  font-size: 32px;
  font-weight: 800;
  margin: 8px 0;
}
.sub {
  font-size: 12px;
  opacity: 0.85;
}
.sub.muted {
  margin-top: 6px;
  opacity: 0.7;
}
.tip-line {
  margin-top: 8px;
}
.bar {
  margin: 10px auto 0;
  height: 8px;
  max-width: 160px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.25);
  overflow: hidden;
}
.bar span {
  display: block;
  height: 100%;
  background: #fff;
  border-radius: inherit;
}
.ledger {
  margin-bottom: 20px;
  padding: 16px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.1);
}
.ledger-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
.ledger-head h2 {
  margin: 0;
  font-size: 16px;
}
.close {
  border: none;
  background: rgba(255, 255, 255, 0.14);
  color: inherit;
  padding: 8px 12px;
  border-radius: 999px;
  cursor: pointer;
  font-size: 13px;
}
.entries {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
  /* Show ~5 rows; older entries stay reachable by scroll. */
  max-height: calc(5 * 4.25rem + 4 * 10px);
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  scrollbar-gutter: stable;
}
.entry {
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.18);
}
.entry-main {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: baseline;
}
.entry-note {
  font-weight: 600;
  text-align: left;
}
.entry-amt {
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.entry-amt.plus {
  color: #9dffb8;
}
.entry-amt.minus {
  color: #ffb4b4;
}
.entry-meta {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-top: 6px;
  font-size: 12px;
  opacity: 0.75;
}
.tip {
  text-align: center;
  opacity: 0.85;
  margin-bottom: 12px;
}
.shop-link {
  display: block;
  text-align: center;
  padding: 16px;
  border-radius: 16px;
  background: var(--gp-sun);
  color: var(--gp-space-dark);
  font-weight: 700;
  text-decoration: none;
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
