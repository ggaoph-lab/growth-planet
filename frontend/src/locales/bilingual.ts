import type zhCN from './zh-CN'

type Dict = typeof zhCN

/** Join Chinese + English as stacked lines (中文 on top, English below). */
export function biLabel(zh: string, en: string, locale: string): string {
  if (locale === 'en') return en
  if (locale === 'zh-CN') return zh
  if (zh === en) return zh
  return `${zh}\n${en}`
}

/** Deep-merge Chinese + English into stacked bilingual strings (params placeholders kept once). */
export function buildBilingual(zh: Dict, en: Dict): Dict {
  const walk = (a: unknown, b: unknown): unknown => {
    if (typeof a === 'string' && typeof b === 'string') {
      if (a === b) return a
      return `${a}\n${b}`
    }
    if (a && typeof a === 'object' && b && typeof b === 'object') {
      const out: Record<string, unknown> = {}
      for (const key of Object.keys(a as object)) {
        out[key] = walk((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key])
      }
      return out
    }
    return a
  }
  return walk(zh, en) as Dict
}
