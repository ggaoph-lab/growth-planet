import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api, ensureCsrfCookie } from '@/shared/api/client'
import type { Me, Preferences } from '@/shared/api/types'

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
    await ensureCsrfCookie()
    try {
      me.value = await api.get<Me>('/auth/me')
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) throw e
      me.value = null
    }
    loaded.value = true
  }

  async function checkDevice(): Promise<boolean> {
    const result = await api.get<{ authorized: boolean }>('/auth/device')
    deviceAuthorized.value = result.authorized
    return result.authorized
  }

  async function loginParent(login: string, password: string): Promise<void> {
    me.value = await api.post<Me>('/auth/login', { login, password })
    loaded.value = true
    await ensureCsrfCookie(true)
  }

  async function loginKid(childId: number, pin: string | null): Promise<void> {
    me.value = await api.post<Me>('/auth/kid/login', { child_id: childId, pin })
    loaded.value = true
    await ensureCsrfCookie(true)
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      me.value = null
      await ensureCsrfCookie(true)
    }
  }

  function setChildPreferences(preferences: Preferences): void {
    if (me.value?.child) me.value.child.preferences = preferences
  }

  return { me, loaded, deviceAuthorized, role, isParent, isChild, timezone, load, checkDevice, loginParent, loginKid, logout, setChildPreferences }
})
