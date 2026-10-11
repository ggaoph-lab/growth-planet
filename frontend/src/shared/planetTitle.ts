import { i18n } from '@/i18n'

/** Prefer i18n planet name when backend key is known. */
export function planetTitle(key: string, fallback: string): string {
  const path = `planets.${key}`
  return i18n.global.te(path) ? String(i18n.global.t(path)) : fallback
}
