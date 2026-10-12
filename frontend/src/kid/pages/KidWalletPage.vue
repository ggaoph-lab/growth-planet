<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidWallet } from '@/shared/api/types'

const { t } = useI18n()
const router = useRouter()
const wallet = ref<KidWallet | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    wallet.value = await api.get<KidWallet>('/kid/wallet')
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
      <h1>{{ t('kid.wallet') }}</h1>
      <span class="spacer" />
    </header>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>

    <template v-if="wallet">
      <section class="hero">
        <div class="orb xp">
          <div class="label">{{ t('common.xpLabel') }}</div>
          <div class="num">{{ wallet.xp.balance }}</div>
          <div class="sub">{{ t('common.lifetimeEarned', { n: wallet.xp.lifetime_earned }) }}</div>
        </div>
        <div class="orb coin">
          <div class="label">{{ t('common.growthCoin') }}</div>
          <div class="num">{{ wallet.coin.available }}</div>
          <div class="sub">{{ t('growth.available') }} · {{ t('growth.held') }} {{ wallet.coin.held }}</div>
        </div>
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
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 20px;
}
.orb {
  border-radius: 20px;
  padding: 20px;
  text-align: center;
}
.orb.xp {
  background: linear-gradient(160deg, #5b8def, #3d5fc4);
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
