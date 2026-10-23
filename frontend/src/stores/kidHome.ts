import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/shared/api/client'
import type { KidHome } from '@/shared/api/types'

/** Shared kid home payload so the shell and planet gate reuse one fetch. */
export const useKidHomeStore = defineStore('kidHome', () => {
  const home = ref<KidHome | null>(null)
  let inflight: Promise<KidHome> | null = null

  async function load(force = false): Promise<KidHome> {
    if (!force && home.value) return home.value
    if (!force && inflight) return inflight
    inflight = api
      .get<KidHome>('/kid/home')
      .then((data) => {
        home.value = data
        return data
      })
      .finally(() => {
        inflight = null
      })
    return inflight
  }

  function clear(): void {
    home.value = null
    inflight = null
  }

  return { home, load, clear }
})
