import { onBeforeUnmount, ref, type Ref } from 'vue'

export function useMediaQuery(query: string): Ref<boolean> {
  const mql = window.matchMedia(query)
  const matches = ref(mql.matches)
  const update = (e: MediaQueryListEvent) => {
    matches.value = e.matches
  }
  mql.addEventListener('change', update)
  onBeforeUnmount(() => mql.removeEventListener('change', update))
  return matches
}

/** Phones get dedicated card/bottom-nav layouts rather than a shrunken desktop table. */
export function useIsPhone(): Ref<boolean> {
  return useMediaQuery('(max-width: 767px)')
}
