import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import SudokuBoard from '@/kid/components/SudokuBoard.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'
import { playDrop, playMiss, playWin } from '@/shared/sfx'

vi.mock('@/shared/sfx', () => ({
  playTap: vi.fn(),
  playDrop: vi.fn(),
  playMiss: vi.fn(),
  playWin: vi.fn(),
}))

vi.mock('@/shared/api/client', () => ({
  ApiError: class extends Error {},
  api: { get: vi.fn(), postIdempotent: vi.fn() },
}))

vi.mock('@/kid/sudoku', async () => {
  const actual = await vi.importActual<typeof import('@/kid/sudoku')>('@/kid/sudoku')
  return {
    ...actual,
    createSudokuPuzzle: (level: string) => {
      if (level === 'practice' || level === 'challenge') {
        return {
          size: 6,
          boxRows: 2,
          boxCols: 3,
          solution: [
            1, 2, 3, 4, 5, 6,
            4, 5, 6, 1, 2, 3,
            2, 3, 4, 5, 6, 1,
            5, 6, 1, 2, 3, 4,
            3, 4, 5, 6, 1, 2,
            6, 1, 2, 3, 4, 5,
          ],
          start: [
            1, 0, 3, 4, 0, 6,
            4, 5, 0, 1, 2, 0,
            0, 3, 4, 0, 6, 1,
            5, 0, 1, 2, 0, 4,
            3, 4, 0, 6, 1, 0,
            0, 1, 2, 0, 4, 5,
          ],
        }
      }
      return {
        size: 4,
        boxRows: 2,
        boxCols: 2,
        solution: [1, 2, 3, 4, 3, 4, 1, 2, 2, 1, 4, 3, 4, 3, 2, 1],
        start: [1, 0, 3, 0, 0, 4, 0, 2, 2, 0, 4, 0, 0, 3, 0, 1],
      }
    },
  }
})

let wrapper: VueWrapper | undefined

function cells() {
  if (!wrapper) throw new Error('missing board')
  return wrapper.findAll('button.cell')
}

function emptyCell(index: number) {
  const button = cells()[index]
  if (!button) throw new Error(`missing cell ${index}`)
  return button
}

beforeEach(() => {
  vi.clearAllMocks()
  setAppLocale('zh-CN')
  vi.mocked(api.get).mockResolvedValue({
    date: '2026-10-20',
    levels: [
      { id: 'easy', coins: 1, awarded: false, remaining: 2 },
      { id: 'practice', coins: 2, awarded: false, remaining: 2 },
      { id: 'challenge', coins: 3, awarded: false, remaining: 2 },
    ],
  })
  vi.mocked(api.postIdempotent).mockImplementation(async (path) => path.endsWith('/start') ? { play_id: 'play-1', remaining: 1, date: '2026-10-20' } : ({
    awarded_coins: 1,
    board: {
      date: '2026-10-20',
      levels: [
        { id: 'easy', coins: 1, awarded: true, remaining: 1 },
        { id: 'practice', coins: 2, awarded: false, remaining: 2 },
        { id: 'challenge', coins: 3, awarded: false, remaining: 2 },
      ],
    },
  }))
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
})

describe('SudokuBoard', () => {
  it('locks a matching digit and ignores a wrong one', async () => {
    wrapper = mount(SudokuBoard, { props: { sounds: true } })
    await flushPromises()
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('先选数字，再点空格')
    expect(wrapper.text()).toContain('还差 8 格')

    await emptyCell(1).trigger('click')
    expect(emptyCell(1).classes()).toContain('miss')
    expect(emptyCell(1).text()).toBe('')
    expect(playMiss).toHaveBeenCalledOnce()

    await wrapper.get('button.digit[aria-pressed="false"]').trigger('click')
    await emptyCell(1).trigger('click')
    expect(emptyCell(1).classes()).toContain('filled')
    expect(emptyCell(1).text()).toBe('2')
    expect(playDrop).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('还差 7 格')
  })

  it('finishes a seeded board and can start another', async () => {
    wrapper = mount(SudokuBoard, { props: { sounds: true } })
    await flushPromises()
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    const order = [
      { index: 1, digit: 2 },
      { index: 3, digit: 4 },
      { index: 4, digit: 3 },
      { index: 6, digit: 1 },
      { index: 9, digit: 1 },
      { index: 11, digit: 3 },
      { index: 12, digit: 4 },
      { index: 14, digit: 2 },
    ]
    for (const step of order) {
      await wrapper.get(`button.digit[aria-label="数字 ${step.digit}"]`).trigger('click')
      await emptyCell(step.index).trigger('click')
    }
    await flushPromises()
    expect(wrapper.text()).toContain('数独完成啦！')
    expect(wrapper.text()).toContain('+1 积分')
    expect(playWin).toHaveBeenCalledOnce()
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/games/sudoku', { level: 'easy', play_id: 'play-1' })
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('还差 8 格')
  })

  it('switches to a larger grid for practice', async () => {
    wrapper = mount(SudokuBoard)
    await flushPromises()
    await wrapper.get('button.level[aria-pressed="false"]').trigger('click')
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(cells()).toHaveLength(36)
    expect(wrapper.findAll('button.digit')).toHaveLength(6)
  })
})
