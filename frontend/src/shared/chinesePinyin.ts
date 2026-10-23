export type ChinesePinyinUnit = {
  char: string
  pinyin: string
}

type PinyinFn = (
  text: string,
  options: Record<string, unknown>,
) => string | Array<{ origin: string; result: string; isZh: boolean }>

const HAS_HAN = /[\u4e00-\u9fff]/

let pinyinFn: PinyinFn | null = null
let loadPromise: Promise<void> | null = null
const readyListeners = new Set<() => void>()

/** Load pinyin-pro on demand so the English planet chunk stays lean until ruby text is needed. */
export function loadChinesePinyin(): Promise<void> {
  if (pinyinFn) return Promise.resolve()
  if (!loadPromise) {
    loadPromise = import('pinyin-pro').then((mod) => {
      pinyinFn = mod.pinyin as PinyinFn
      for (const listener of readyListeners) listener()
      readyListeners.clear()
    })
  }
  return loadPromise
}

export function onChinesePinyinReady(listener: () => void): () => void {
  if (pinyinFn) {
    listener()
    return () => undefined
  }
  readyListeners.add(listener)
  void loadChinesePinyin()
  return () => {
    readyListeners.delete(listener)
  }
}

/** Tone-marked pinyin for Chinese option labels; empty when the text is not Chinese or the lib is still loading. */
export function chinesePinyin(text: string): string {
  const value = text.trim()
  if (!value || !HAS_HAN.test(value) || !pinyinFn) return ''
  return String(pinyinFn(value, { toneType: 'symbol', nonZh: 'consecutive' }))
    .replace(/\s+/g, ' ')
    .replace(/\s+([。，、！？；：,.!?])/g, '$1')
    .trim()
}

/** Per-character units for ruby layout (pinyin above each Han character). */
export function chinesePinyinUnits(text: string): ChinesePinyinUnit[] {
  const value = text.trim()
  if (!value) return []
  if (!HAS_HAN.test(value)) return [{ char: value, pinyin: '' }]
  if (!pinyinFn) return [{ char: value, pinyin: '' }]

  const items = pinyinFn(value, {
    toneType: 'symbol',
    type: 'all',
    nonZh: 'consecutive',
  }) as Array<{ origin: string; result: string; isZh: boolean }>

  return items.map((item) => ({
    char: item.origin,
    pinyin: item.isZh ? String(item.result || '').trim() : '',
  }))
}
