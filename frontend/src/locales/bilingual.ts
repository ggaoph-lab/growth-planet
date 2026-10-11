import type zhCN from './zh-CN'

type Dict = typeof zhCN

/** Deep-merge Chinese + English into "中文 / English" strings (params placeholders kept once). */
export function buildBilingual(zh: Dict, en: Dict): Dict {
  const walk = (a: unknown, b: unknown): unknown => {
    if (typeof a === 'string' && typeof b === 'string') {
      if (a === b) return a
      return `${a} / ${b}`
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
