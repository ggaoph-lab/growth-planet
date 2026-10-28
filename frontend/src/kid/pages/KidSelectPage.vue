<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api, ensureCsrfCookie } from '@/shared/api/client'
import type { KidLoginProfile } from '@/shared/api/types'
import { avatarEmoji } from '@/shared/avatars'
import { useSessionStore } from '@/stores/session'
import PinPad from '../components/PinPad.vue'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const state = ref<'loading' | 'unauthorized' | 'ready'>('loading')
const profiles = ref<KidLoginProfile[]>([])
const selected = ref<KidLoginProfile | null>(null)
const message = ref('')
const busy = ref(false)
const pinpad = ref<InstanceType<typeof PinPad> | null>(null)
const pairingCode = ref('')
const pairingName = ref(t('auth.pairingDefaultName'))

async function loadProfiles(): Promise<void> {
  profiles.value = await api.get<KidLoginProfile[]>('/auth/kid/profiles')
  state.value = 'ready'
  prefetchKidHome()
}

function prefetchKidHome(): void {
  void ensureCsrfCookie()
  void import('@/kid/KidLayout.vue')
  void import('@/kid/pages/KidHomePage.vue')
}

onMounted(async () => {
  void ensureCsrfCookie()
  if (session.isParent) await session.logout()
  try {
    await loadProfiles()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'DEVICE_NOT_AUTHORIZED') state.value = 'unauthorized'
    else message.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  }
})

async function pairBrowser(): Promise<void> {
  const code = pairingCode.value.replace(/\s/g, '')
  const name = pairingName.value.trim()
  if (!/^\d{8}$/.test(code) || !name || busy.value) {
    message.value = t('auth.pairingInputHint')
    return
  }
  busy.value = true
  message.value = ''
  try {
    await ensureCsrfCookie()
    await api.post('/auth/device/pair', { code, name })
    await session.checkDevice()
    await loadProfiles()
    pairingCode.value = ''
  } catch (e) {
    message.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    busy.value = false
  }
}

async function login(pin: string | null): Promise<void> {
  if (!selected.value || busy.value) return
  busy.value = true
  message.value = ''
  try {
    prefetchKidHome()
    await ensureCsrfCookie()
    await session.loginKid(selected.value.id, pin)
    // /kid/home is already warming inside loginKid; navigate as soon as auth succeeds.
    await router.replace({ name: 'kid-home' })
  } catch (e) {
    message.value = e instanceof ApiError ? e.message : t('auth.loginFailed')
    pinpad.value?.clear()
  } finally {
    busy.value = false
  }
}

function choose(p: KidLoginProfile): void {
  message.value = ''
  selected.value = p
  prefetchKidHome()
  if (!p.pin_required) void login(null)
}
</script>

<template>
  <div class="select">
    <template v-if="state === 'unauthorized'">
      <div class="panel">
        <div class="big-emoji">🔒</div>
        <p>{{ t('auth.deviceNotAuthorized') }}</p>
        <p class="pairing-hint">{{ t('auth.pairingHint') }}</p>
        <form class="pairing-form" @submit.prevent="pairBrowser">
          <label for="pairing-code">{{ t('auth.pairingCode') }}</label>
          <input
            id="pairing-code"
            v-model="pairingCode"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="9"
            :placeholder="t('auth.pairingCodePlaceholder')"
          />
          <label for="pairing-name">{{ t('auth.pairingName') }}</label>
          <input id="pairing-name" v-model="pairingName" type="text" maxlength="64" />
          <button type="submit" :disabled="busy">{{ t('auth.pairBrowser') }}</button>
        </form>
      </div>
    </template>

    <template v-else-if="state === 'ready' && !selected">
      <h1>{{ t('auth.whoAreYou') }}</h1>
      <p v-if="profiles.length === 0" class="panel">{{ t('auth.noChildrenYet') }}</p>
      <div class="profiles">
        <button v-for="p in profiles" :key="p.id" type="button" class="profile" @click="choose(p)">
          <span class="avatar" :style="{ background: p.theme_color }">{{ avatarEmoji(p.avatar) }}</span>
          <span class="name">{{ p.nickname }}</span>
        </button>
      </div>
    </template>

    <template v-else-if="selected">
      <button type="button" class="back" @click="selected = null">← {{ t('common.back') }}</button>
      <span class="avatar large" :style="{ background: selected.theme_color }">{{ avatarEmoji(selected.avatar) }}</span>
      <h1>{{ selected.nickname }}</h1>
      <template v-if="selected.pin_required">
        <p class="sub">{{ t('auth.enterPin') }}</p>
        <PinPad ref="pinpad" :disabled="busy" @submit="login" />
      </template>
    </template>

    <p v-if="message" class="message" role="alert">{{ message }}</p>

    <RouterLink :to="{ name: 'login' }" class="parent-link">{{ t('auth.parentEntry') }}</RouterLink>
  </div>
</template>

<style scoped>
.select {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: calc(40px + env(safe-area-inset-top)) 20px calc(40px + env(safe-area-inset-bottom));
  color: var(--gp-text-light);
  background: radial-gradient(circle at 50% 0%, #3d3f9a 0, var(--gp-space-dark) 60%);
  text-align: center;
}
h1 {
  font-size: 30px;
  margin: 12px 0;
}
.sub {
  margin: 0 0 20px;
  opacity: 0.85;
  font-size: 18px;
}
.profiles {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 24px;
  margin-top: 24px;
}
.profile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  border: none;
  background: none;
  color: #fff;
  cursor: pointer;
  padding: 8px;
  touch-action: manipulation;
}
.avatar {
  width: 112px;
  height: 112px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 60px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}
.avatar.large {
  width: 96px;
  height: 96px;
  font-size: 52px;
  margin-top: 16px;
}
.name {
  font-size: 20px;
  font-weight: 700;
}
.back {
  align-self: flex-start;
  min-height: 44px;
  border: none;
  background: none;
  color: #fff;
  font-size: 17px;
  cursor: pointer;
}
.panel {
  max-width: 420px;
  padding: 24px;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.1);
  line-height: 1.7;
  font-size: 17px;
}
.big-emoji {
  font-size: 56px;
}
.pairing-hint { font-size: 15px; opacity: 0.9; }
.pairing-form { display: grid; gap: 10px; text-align: left; margin-top: 18px; }
.pairing-form label { font-size: 14px; }
.pairing-form input {
  min-height: 48px;
  width: 100%;
  padding: 10px 14px;
  border: 1px solid #aeb5da;
  border-radius: 12px;
  background: #fff;
  color: #1b2045;
  font-size: 18px;
}
.pairing-form button {
  min-height: 48px;
  margin-top: 8px;
  border-radius: 12px;
  background: #7fdbaf;
  color: #182b42;
  font-size: 17px;
  font-weight: 700;
}
.pairing-form button:disabled { opacity: 0.6; }
.message {
  margin-top: 20px;
  padding: 10px 18px;
  border-radius: 999px;
  background: rgba(255, 180, 71, 0.2);
  color: #ffd79a;
  font-size: 16px;
}
.parent-link {
  margin-top: auto;
  padding-top: 40px;
  color: rgba(255, 255, 255, 0.5);
  font-size: 13px;
  text-decoration: none;
}
</style>
