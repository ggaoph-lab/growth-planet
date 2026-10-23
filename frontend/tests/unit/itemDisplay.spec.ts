import { describe, expect, it } from 'vitest'
import { itemDisplay } from '@/shared/itemDisplay'

describe('itemDisplay', () => {
  it('maps common school items to emoji + english', () => {
    expect(itemDisplay('水杯')).toEqual({ emoji: '🥤', zh: '水杯', en: 'Water bottle' })
    expect(itemDisplay('文具盒')).toEqual({ emoji: '✏️', zh: '文具盒', en: 'Pencil case' })
  })

  it('keeps leading emoji and still resolves english', () => {
    expect(itemDisplay('🥤我的水杯')).toMatchObject({ emoji: '🥤', zh: '我的水杯', en: 'Water bottle' })
  })

  it('parses bilingual names', () => {
    expect(itemDisplay('画具 / Art kit')).toEqual({ emoji: '🎨', zh: '画具', en: 'Art kit' })
    expect(itemDisplay('画具\nArt kit')).toEqual({ emoji: '🎨', zh: '画具', en: 'Art kit' })
  })

  it('falls back for unknown items', () => {
    expect(itemDisplay('神秘盒子')).toEqual({ emoji: '📦', zh: '神秘盒子', en: '' })
  })
})
