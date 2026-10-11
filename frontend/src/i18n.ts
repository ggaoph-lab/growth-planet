import { createI18n } from 'vue-i18n'
import zhCN from './locales/zh-CN'
import enRaw from './locales/en'
import { buildBilingual } from './locales/bilingual'

export type MessageSchema = typeof zhCN
const en = enRaw as unknown as MessageSchema
export type AppLocale = 'zh-en' | 'zh-CN' | 'en'

const STORAGE_KEY = 'gp_locale'

export function readStoredLocale(): AppLocale {
  const v = localStorage.getItem(STORAGE_KEY)
  if (v === 'zh-CN' || v === 'en' || v === 'zh-en') return v
  return 'zh-en'
}

export function storeLocale(locale: AppLocale): void {
  localStorage.setItem(STORAGE_KEY, locale)
}

const zhEn = buildBilingual(zhCN, en)

/** Default is bilingual (中文 / English). Users can switch to Chinese-only or English-only. */
export const i18n = createI18n<[MessageSchema], AppLocale>({
  legacy: false,
  locale: typeof localStorage !== 'undefined' ? readStoredLocale() : 'zh-en',
  fallbackLocale: 'zh-en',
  messages: {
    'zh-en': zhEn,
    'zh-CN': zhCN,
    en,
  },
})

export function setAppLocale(locale: AppLocale): void {
  ;(i18n.global as unknown as { locale: { value: AppLocale } }).locale.value = locale
  storeLocale(locale)
  document.documentElement.lang = locale === 'en' ? 'en' : 'zh-CN'
}
