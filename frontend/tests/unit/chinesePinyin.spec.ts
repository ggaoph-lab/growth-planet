import { beforeAll, describe, expect, it } from 'vitest'
import { chinesePinyin, chinesePinyinUnits, loadChinesePinyin } from '@/shared/chinesePinyin'

describe('chinesePinyin', () => {
  beforeAll(async () => {
    await loadChinesePinyin()
  })

  it('returns tone-marked pinyin for Chinese labels', () => {
    expect(chinesePinyin('苹果')).toMatch(/píng\s*guǒ/i)
    expect(chinesePinyin('我喜欢苹果。')).toMatch(/xǐ\s*huan/i)
    expect(chinesePinyin('我喜欢苹果。')).toMatch(/píng\s*guǒ。?$/i)
  })

  it('returns empty for English options', () => {
    expect(chinesePinyin('apple')).toBe('')
    expect(chinesePinyin('I like apples.')).toBe('')
  })

  it('returns per-character units for ruby layout', () => {
    const units = chinesePinyinUnits('苹果。')
    expect(units[0]).toMatchObject({ char: '苹', pinyin: expect.stringMatching(/píng/i) })
    expect(units[1]).toMatchObject({ char: '果', pinyin: expect.stringMatching(/guǒ/i) })
    expect(units[2]).toEqual({ char: '。', pinyin: '' })
    expect(chinesePinyinUnits('apple')).toEqual([{ char: 'apple', pinyin: '' }])
  })
})
