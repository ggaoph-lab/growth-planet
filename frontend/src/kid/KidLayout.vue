<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { Preferences } from '@/shared/api/types'
import LanguageSwitch from '@/shared/LanguageSwitch.vue'
import { avatarEmoji } from '@/shared/avatars'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const child = computed(() => session.me?.child ?? null)
const settingsOpen = ref(false)
const saveError = ref('')

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

async function switchUser(): Promise<void> {
  await session.logout()
  await router.push({ name: 'kid-select' })
}
</script>

<template>
  <div class="kid" :class="{ calm: !child?.preferences.animations }">
    <header v-if="child" class="bar">
      <div class="me">
        <span class="avatar" :style="{ background: child.theme_color }">{{ avatarEmoji(child.avatar) }}</span>
        <span class="name">{{ child.nickname }}</span>
      </div>
      <button type="button" class="round" :aria-label="t('kid.settings')" @click="settingsOpen = true">⚙️</button>
    </header>

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
.round {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.14);
  font-size: 22px;
  cursor: pointer;
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
  background: #262a66;
  border-radius: 24px 24px 0 0;
  padding: 20px 20px calc(20px + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sheet h2 {
  margin: 0 0 4px;
  font-size: 20px;
}
.lang-wrap {
  display: flex;
  justify-content: flex-end;
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
