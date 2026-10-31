import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import MirrorPuzzle from '@/kid/components/MirrorPuzzle.vue'
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

vi.mock('@/kid/mirror', async () => {
  const actual = await vi.importActual<typeof import('@/kid/mirror')>('@/kid/mirror')
  return {
    ...actual,
    createMirrorPuzzle: (level: string) => {
      if (level === 'practice') {
        return { rows: 4, cols: 2, colors: 3, left: [0, 1, 2, 0, 1, 2, 0, 1] }
      }
      return { rows: 3, cols: 2, colors: 2, left: [0, 1, 1, 0, 0, 1] }
    },
  }
})

let wrapper: VueWrapper | undefined

function slots() {
  if (!wrapper) throw new Error('missing puzzle')
  return wrapper.findAll('button.slot')
}

function slot(index: number) {
  const button = slots()[index]
  if (!button) throw new Error(`missing slot ${index}`)
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

describe('MirrorPuzzle', () => {
  it('locks a matching color and ignores a wrong one', async () => {
    wrapper = mount(MirrorPuzzle, { props: { sounds: true } })
    await flushPromises()
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('先选颜色，再点右边的空格')
    expect(wrapper.text()).toContain('还差 6 块')

    await slot(0).trigger('click')
    expect(slot(0).classes()).toContain('miss')
    expect(slot(0).attributes('style') ?? '').not.toContain('background')
    expect(playMiss).toHaveBeenCalledOnce()
    expect(playDrop).not.toHaveBeenCalled()

    await slot(1).trigger('click')
    expect(slot(1).classes()).toContain('filled')
    expect(playDrop).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('还差 5 块')

    await wrapper.get('button.swatch[aria-label="蓝色"]').trigger('click')
    await slot(0).trigger('click')
    await slot(3).trigger('click')
    await slot(4).trigger('click')
    await wrapper.get('button.swatch[aria-label="橙色"]').trigger('click')
    await slot(2).trigger('click')
    await slot(5).trigger('click')

    await flushPromises()
    expect(wrapper.text()).toContain('拼好啦！')
    expect(wrapper.text()).toContain('+1 积分')
    expect(wrapper.text()).toContain('再拼一幅')
    expect(playWin).toHaveBeenCalledOnce()
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/games/mirror', { level: 'easy', play_id: 'play-1' })
    expect(slots().every((button) => button.classes().includes('filled'))).toBe(true)
  })

  it('still shakes a wrong tap when sounds are off', async () => {
    wrapper = mount(MirrorPuzzle, { props: { sounds: false } })
    await flushPromises()
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    await slot(0).trigger('click')
    expect(slot(0).classes()).toContain('miss')
    expect(playMiss).not.toHaveBeenCalled()
  })

  it('starts a larger pattern when the level changes', async () => {
    wrapper = mount(MirrorPuzzle)
    await flushPromises()
    await wrapper.get('button.level[aria-pressed="false"]').trigger('click')
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('.source')).toHaveLength(8)
    expect(wrapper.findAll('button.swatch')).toHaveLength(3)
  })

  it('clears the board for another collage', async () => {
    wrapper = mount(MirrorPuzzle, { props: { sounds: true } })
    await flushPromises()
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    const order = [1, 2, 5, 0, 3, 4]
    const colors = ['橙色', '橙色', '橙色', '蓝色', '蓝色', '蓝色']
    for (let step = 0; step < order.length; step += 1) {
      const color = colors[step]
      const index = order[step]
      if (color === undefined || index === undefined) throw new Error('missing step')
      await wrapper.get(`button.swatch[aria-label="${color}"]`).trigger('click')
      await slot(index).trigger('click')
    }
    expect(wrapper.text()).toContain('拼好啦！')
    await wrapper.get('button.again').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('还差 6 块')
    expect(slots().every((button) => !button.classes().includes('filled'))).toBe(true)
  })
})
