import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import SchulteGrid from '@/kid/components/SchulteGrid.vue'
import { rememberBest, type SchulteBoard } from '@/kid/schulte'
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

function board(bestMs: number | null = null, awarded = false): SchulteBoard {
  return {
    date: '2026-10-20',
    sizes: [
      { size: 3, limit_ms: 9000, coins: 1, best_ms: bestMs, awarded, ranks: bestMs == null ? [] : [{ child_id: 4, nickname: '小星星', best_ms: bestMs, coins: awarded ? 1 : 0 }] },
      { size: 4, limit_ms: 16000, coins: 2, best_ms: null, awarded: false, ranks: [] },
      { size: 5, limit_ms: 25000, coins: 3, best_ms: null, awarded: false, ranks: [] },
    ],
  }
}

let wrapper: VueWrapper | undefined

function cell(label: string) {
  const button = wrapper?.findAll('button.cell').find((item) => item.text() === label)
  if (!button) throw new Error(`missing cell ${label}`)
  return button
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  setAppLocale('zh-CN')
  vi.mocked(api.get).mockResolvedValue(board())
  vi.mocked(api.postIdempotent).mockResolvedValue({
    elapsed_ms: 1,
    improved: true,
    awarded_coins: 0,
    board: board(),
  })
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
})

describe('SchulteGrid', () => {
  it('advances only when the next number is tapped', async () => {
    wrapper = mount(SchulteGrid, { props: { sounds: true, childId: 4 } })
    await wrapper.get('button.go').trigger('click')

    expect(wrapper.get('.next-num').text()).toBe('1')
    await cell('2').trigger('click')
    expect(wrapper.get('.next-num').text()).toBe('1')
    expect(cell('2').classes()).toContain('miss')
    expect(playMiss).toHaveBeenCalledOnce()

    await cell('1').trigger('click')
    expect(wrapper.get('.next-num').text()).toBe('2')
    expect(cell('1').classes()).toContain('found')
    expect(cell('1').classes()).toContain('hit')
    expect((cell('1').element as HTMLButtonElement).disabled).toBe(true)
    expect(playDrop).toHaveBeenCalledOnce()
  })

  it('finishes a grid and keeps a slower run off the record', async () => {
    let now = 10_000
    const clock = vi.spyOn(Date, 'now').mockImplementation(() => now)
    vi.mocked(api.get).mockResolvedValue(board(1500))
    vi.mocked(api.postIdempotent).mockResolvedValue({
      elapsed_ms: 5000,
      improved: false,
      awarded_coins: 0,
      board: board(1500),
    })
    rememberBest(4, 3, 1500)
    wrapper = mount(SchulteGrid, { props: { animate: false, sounds: true, childId: 4 } })
    await flushPromises()
    expect(wrapper.text()).toContain('今天最好 1.5')
    expect(wrapper.text()).toContain('9 秒内 +1 积分')
    expect(wrapper.text()).toContain('从 1 点到 9')

    await wrapper.get('button.go').trigger('click')
    now = 20_000
    expect(wrapper.find('.cell').classes()).not.toContain('animate')
    expect((wrapper.find('.size').element as HTMLButtonElement).disabled).toBe(true)

    for (let n = 1; n <= 9; n += 1) await cell(String(n)).trigger('click')
    clock.mockRestore()
    await flushPromises()

    expect(wrapper.text()).toContain('找完啦')
    expect(wrapper.text()).toContain('再快一点')
    expect(wrapper.text()).not.toContain('新纪录')
    expect(wrapper.text()).toContain('今天最好 1.5')
    expect(playWin).toHaveBeenCalledOnce()
  })

  it('records the first finish and can switch to a larger grid', async () => {
    wrapper = mount(SchulteGrid, { props: { childId: 8 } })
    await wrapper.get('button.go').trigger('click')
    for (let n = 1; n <= 9; n += 1) await cell(String(n)).trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('新纪录')

    const larger = wrapper.findAll('button.size').find((button) => button.text().includes('4×4'))
    await larger?.trigger('click')
    expect(wrapper.text()).toContain('从 1 点到 16')
    expect(wrapper.find('.grid').exists()).toBe(false)
  })
})
