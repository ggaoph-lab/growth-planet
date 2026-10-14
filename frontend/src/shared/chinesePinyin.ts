import { pinyin } from 'pinyin-pro'

const HAS_HAN = /[\u4e00-\u9fff]/

export type ChinesePinyinUnit = {
  char: string
  pinyin: string
}

/** Tone-marked pinyin for Chinese option labels; empty when the text is not Chinese. */
export function chinesePinyin(text: string): string {
  const value = text.trim()
  if (!value || !HAS_HAN.test(value)) return ''
  return pinyin(value, { toneType: 'symbol', nonZh: 'consecutive' })
    .replace(/\s+/g, ' ')
    .replace(/\s+([。，、！？；：,.!?])/g, '$1')
    .trim()
}

/** Per-character units for ruby layout (pinyin above each Han character). */
export function chinesePinyinUnits(text: string): ChinesePinyinUnit[] {
  const value = text.trim()
  if (!value) return []
  if (!HAS_HAN.test(value)) return [{ char: value, pinyin: '' }]

  const items = pinyin(value, {
    toneType: 'symbol',
    type: 'all',
    nonZh: 'consecutive',
  }) as Array<{ origin: string; result: string; isZh: boolean }>

  return items.map(item => ({
    char: item.origin,
    pinyin: item.isZh ? String(item.result || '').trim() : '',
  }))
}
