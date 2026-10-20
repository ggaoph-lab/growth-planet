import { describe, expect, it } from 'vitest'
import {
  SCHULTE_BEST_KEY,
  SCHULTE_REWARDS,
  formatSchulteTime,
  readBest,
  rememberBest,
  schulteNumbers,
  shuffleNumbers,
} from '@/kid/schulte'

function memoryStorage(): Storage {
  const data = new Map<string, string>()
  return {
    get length() {
      return data.size
    },
    clear: () => data.clear(),
    getItem: (key) => data.get(key) ?? null,
    key: (index) => [...data.keys()][index] ?? null,
    removeItem: (key) => {
      data.delete(key)
    },
    setItem: (key, value) => {
      data.set(key, value)
    },
  }
}

describe('schulte grid', () => {
  it('pays 1, 2, and 3 points inside 9s, 16s, and 25s', () => {
    expect(SCHULTE_REWARDS[3]).toEqual({ limitMs: 9000, coins: 1 })
    expect(SCHULTE_REWARDS[4]).toEqual({ limitMs: 16000, coins: 2 })
    expect(SCHULTE_REWARDS[5]).toEqual({ limitMs: 25000, coins: 3 })
  })

  it('shuffles each number into the grid once', () => {
    const cells = shuffleNumbers(3, () => 0)
    expect(cells).toEqual([2, 3, 4, 5, 6, 7, 8, 9, 1])
    expect(new Set(shuffleNumbers(5, () => 0.42))).toEqual(new Set(schulteNumbers(5)))
  })

  it('formats tenths and minutes for a child to read', () => {
    expect(formatSchulteTime(0)).toBe('0.0')
    expect(formatSchulteTime(18400)).toBe('18.4')
    expect(formatSchulteTime(65050)).toBe('1:05.1')
    expect(formatSchulteTime(-5)).toBe('0.0')
  })

  it('keeps the fastest time per child and grid size', () => {
    const storage = memoryStorage()
    expect(rememberBest(2, 5, 3000, storage)).toEqual({ best: 3000, improved: true })
    expect(rememberBest(2, 5, 4000, storage)).toEqual({ best: 3000, improved: false })
    expect(rememberBest(2, 5, 2000, storage)).toEqual({ best: 2000, improved: true })
    expect(readBest(2, 5, storage)).toBe(2000)
    expect(readBest(2, 3, storage)).toBeNull()
    expect(readBest(9, 5, storage)).toBeNull()
  })

  it('ignores a corrupt save', () => {
    const storage = memoryStorage()
    storage.setItem(SCHULTE_BEST_KEY, 'nope')
    expect(readBest(1, 3, storage)).toBeNull()
  })
})
