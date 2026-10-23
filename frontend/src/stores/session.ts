import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api, ensureCsrfCookie } from '@/shared/api/client'
import { clearWarmGets } from '@/shared/api/warmGet'
import type { Me, Preferences } from '@/shared/api/types'
import { setAppLocale, type AppLocale } from '@/i18n'
import { useKidHomeStore } from '@/stores/kidHome'

export const useSessionStore = defineStore('session', () => {
  const me = ref<Me | null>(null)
  const loaded = ref(false)
  const deviceAuthorized = ref(false)

  const role = computed(() => me.value?.user.role ?? null)
  const isParent = computed(() => role.value === 'parent')
  const isChild = computed(() => role.value === 'child')
  const timezone = computed(() => me.value?.family.timezone ?? 'Asia/Shanghai')

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    try {
      // GET /auth/me only needs the session cookie — skip an extra CSRF round-trip.
      me.value = await api.get<Me>('/auth/me')
      applyFamilyLocale(me.value)
    } catch (e) {
      // Keep the shell usable when the API is briefly down or the session expired.
      if (e instanceof ApiError && (e.status === 401 || e.status === 0 || e.status >= 500)) {
        me.value = null
      } else {
        throw e
      }
    }
    loaded.value = true
  }

  function applyFamilyLocale(profile: Me | null): void {
    if (!profile || profile.user.role !== 'parent') return
    const locale = profile.family.locale
    if (locale === 'zh-CN' || locale === 'en' || locale === 'zh-en') {
      setAppLocale(locale as AppLocale)
    }
  }

  async function checkDevice(): Promise<boolean> {
    const result = await api.get<{ authorized: boolean }>('/auth/device')
    deviceAuthorized.value = result.authorized
    return result.authorized
  }

  async function loginParent(login: string, password: string): Promise<void> {
    useKidHomeStore().clear()
    clearWarmGets()
    me.value = await api.post<Me>('/auth/login', { login, password })
    applyFamilyLocale(me.value)
    loaded.value = true
    // Login Set-Cookie already refreshes XSRF — avoid a forced /sanctum/csrf-cookie hop.
  }

  async function loginKid(childId: number, pin: string | null): Promise<void> {
    const kidHome = useKidHomeStore()
    kidHome.clear()
    clearWarmGets()
    me.value = await api.post<Me>('/auth/kid/login', { child_id: childId, pin })
    loaded.value = true
    // Warm /kid/home while the router is still navigating so the planet grid is ready sooner.
    void kidHome.load()
    // Login regenerates the session and already refreshes XSRF via Set-Cookie — don't add another round-trip.
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      me.value = null
      useKidHomeStore().clear()
      clearWarmGets()
      await ensureCsrfCookie(true)
    }
  }

  function setChildPreferences(preferences: Preferences): void {
    if (me.value?.child) me.value.child.preferences = preferences
  }

  return { me, loaded, deviceAuthorized, role, isParent, isChild, timezone, load, checkDevice, loginParent, loginKid, logout, setChildPreferences }
})
