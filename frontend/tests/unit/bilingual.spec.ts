import { describe, expect, it } from 'vitest'
import { biLabel, buildBilingual } from '@/locales/bilingual'

describe('biLabel', () => {
  it('stacks Chinese above English in bilingual mode', () => {
    expect(biLabel('我的积分', 'My points', 'zh-en')).toBe('我的积分\nMy points')
  })

  it('returns a single language when not bilingual', () => {
    expect(biLabel('我的积分', 'My points', 'zh-CN')).toBe('我的积分')
    expect(biLabel('我的积分', 'My points', 'en')).toBe('My points')
  })

  it('collapses identical strings', () => {
    expect(biLabel('OK', 'OK', 'zh-en')).toBe('OK')
  })
})

describe('buildBilingual', () => {
  it('deep-merges leaf strings with newlines', () => {
    const zh = { common: { save: '保存', back: '返回' }, same: 'OK' } as never
    const en = { common: { save: 'Save', back: 'Back' }, same: 'OK' } as never
    expect(buildBilingual(zh, en)).toEqual({
      common: { save: '保存\nSave', back: '返回\nBack' },
      same: 'OK',
    })
  })
})
