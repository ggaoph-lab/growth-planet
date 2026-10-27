<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import { clearWarmGets } from '@/shared/api/warmGet'
import type { Preferences } from '@/shared/api/types'
import LanguageSwitch from '@/shared/LanguageSwitch.vue'
import { avatarEmoji } from '@/shared/avatars'
import { clearSpeakCache } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const child = computed(() => session.me?.child ?? null)
const settingsOpen = ref(false)
const saveError = ref('')
const clearingCache = ref(false)

async function toggle(key: keyof Preferences): Promise<void> {
  if (!child.value) return
  saveError.value = ''
  try {
    const prefs = await api.patch<Preferences>('/kid/preferences', { [key]: !child.value.preferences[key] })
    session.setChildPreferences(prefs)
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : t('common.prefsSaveFailed')
  }
}

async function clearLocalCache(): Promise<void> {
  if (clearingCache.value) return
  clearingCache.value = true
  saveError.value = ''
  try {
    clearWarmGets()
    clearSpeakCache()
    if ('caches' in window) {
      const keys = await caches.keys()
      await Promise.all(keys.map((key) => caches.delete(key)))
    }
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations()
      await Promise.all(regs.map((reg) => reg.unregister()))
    }
    window.location.reload()
  } catch {
    clearingCache.value = false
    saveError.value = t('kid.clearCacheFailed')
  }
}

async function switchUser(): Promise<void> {
  await session.logout()
  await router.push({ name: 'kid-select' })
}

function goHome(): void {
  if (router.currentRoute.value.name === 'kid-home') return
  void router.push({ name: 'kid-home' })
}
</script>

<template>
  <div class="kid" :class="{ calm: !child?.preferences.animations }">
    <header v-if="child" class="bar">
      <button type="button" class="me" :aria-label="t('kid.homeTitle')" @click="goHome">
        <span class="avatar" :style="{ background: child.theme_color }">{{ avatarEmoji(child.avatar) }}</span>
        <span class="name">{{ child.nickname }}</span>
      </button>
      <div class="bar-end">
        <div id="kid-header-actions" class="header-actions" />
        <button
          type="button"
          class="round cache-button"
          :aria-label="clearingCache ? t('kid.clearingCache') : t('kid.clearCache')"
          :title="t('kid.clearCache')"
          :disabled="clearingCache"
          @click="clearLocalCache"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 7v5h-5" />
            <path d="M4 17v-5h5" />
            <path d="M5.6 9A7 7 0 0 1 18 6.6L20 12" />
            <path d="M18.4 15A7 7 0 0 1 6 17.4L4 12" />
          </svg>
        </button>
        <button type="button" class="round" :aria-label="t('kid.settings')" @click="settingsOpen = true">⚙️</button>
      </div>
    </header>
    <p v-if="saveError && !settingsOpen" class="cache-error" role="alert">{{ saveError }}</p>

    <RouterView />

    <div v-if="settingsOpen && child" class="sheet-mask" @click.self="settingsOpen = false">
      <div class="sheet" role="dialog" :aria-label="t('kid.settings')">
        <h2>{{ t('kid.settings') }}</h2>
        <div class="lang-wrap"><LanguageSwitch /></div>
        <button type="button" class="opt" @click="toggle('animations')">
          <span>✨ {{ t('kid.animations') }}</span><span class="switch" :class="{ on: child.preferences.animations }" />
        </button>
        <button type="button" class="opt" @click="toggle('sounds')">
          <span>🔔 {{ t('kid.sounds') }}</span><span class="switch" :class="{ on: child.preferences.sounds }" />
        </button>
        <button type="button" class="opt" :disabled="clearingCache" @click="clearLocalCache">
          <span>🧹 {{ clearingCache ? t('kid.clearingCache') : t('kid.clearCache') }}</span>
        </button>
        <p v-if="saveError" class="err">{{ saveError }}</p>
        <button type="button" class="big" @click="switchUser">👋 {{ t('kid.switchUser') }}</button>
        <button type="button" class="big ghost" @click="settingsOpen = false">{{ t('common.back') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kid {
  min-height: 100vh;
  color: var(--gp-text-light);
  background:
    radial-gradient(2px 2px at 20% 30%, #fff 50%, transparent 51%),
    radial-gradient(2px 2px at 70% 15%, #fff 50%, transparent 51%),
    radial-gradient(1.5px 1.5px at 40% 80%, #fff 50%, transparent 51%),
    radial-gradient(1.5px 1.5px at 85% 60%, #fff 50%, transparent 51%),
    radial-gradient(circle at 80% 0%, #3d3f9a 0, var(--gp-space-dark) 55%);
  padding: env(safe-area-inset-top) 0 env(safe-area-inset-bottom);
}
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
}
.me {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  padding: 4px 8px 4px 4px;
  border: none;
  border-radius: 999px;
  background: transparent;
  color: inherit;
  cursor: pointer;
  touch-action: manipulation;
}
.me:active {
  background: rgba(255, 255, 255, 0.1);
}
.avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 26px;
}
.name {
  font-size: 18px;
  font-weight: 700;
}
.bar-end {
  display: flex;
  align-items: center;
  gap: 8px;
}
.header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 48px;
}
.round {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.14);
  font-size: 22px;
  cursor: pointer;
}
.round:focus-visible {
  outline: 3px solid #fff;
  outline-offset: 2px;
}
.round:disabled {
  opacity: 0.6;
  cursor: wait;
}
.cache-button {
  display: grid;
  place-items: center;
  color: inherit;
}
.cache-button svg {
  width: 23px;
  height: 23px;
}
.cache-error {
  margin: 0 18px 8px;
  color: #fff;
  background: #8d2c3e;
  border-radius: 12px;
  padding: 10px 14px;
}
.sheet-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 50;
}
.sheet {
  width: min(480px, 100%);
  max-height: min(88vh, 640px);
  overflow-y: auto;
  background: #262a66;
  border-radius: 24px 24px 0 0;
  padding: 20px 20px calc(20px + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 12px;
}
@media (orientation: landscape) {
  .sheet-mask {
    align-items: center;
    padding: 8px 12px;
  }
  .sheet {
    max-height: min(92vh, 480px);
    border-radius: 20px;
    padding: 16px 16px calc(16px + env(safe-area-inset-bottom));
  }
}
.sheet h2 {
  margin: 0 0 4px;
  font-size: 20px;
}
.lang-wrap {
  display: flex;
  justify-content: flex-end;
}
.lang-wrap :deep(.select) {
  min-height: 40px;
  max-width: none;
  font-size: 15px;
}
.opt {
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-height: 56px;
  padding: 0 16px;
  border: none;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  font-size: 17px;
  cursor: pointer;
}
.opt:disabled {
  opacity: 0.55;
  cursor: wait;
}
.switch {
  width: 48px;
  height: 28px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.25);
  position: relative;
  transition: background 0.2s;
}
.switch::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #fff;
  transition: left 0.2s;
}
.switch.on {
  background: #4caf50;
}
.switch.on::after {
  left: 23px;
}
.big {
  min-height: 56px;
  border: none;
  border-radius: 16px;
  background: var(--gp-sun);
  color: var(--gp-space-dark);
  font-size: 17px;
  font-weight: 700;
  cursor: pointer;
}
.big.ghost {
  background: transparent;
  color: #fff;
}
.err {
  margin: 0;
  color: #ffb4b4;
}
.calm :deep(*) {
  animation: none !important;
  transition: none !important;
}
</style>
