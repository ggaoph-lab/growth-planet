import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import KidGamesPage from '@/kid/pages/KidGamesPage.vue'
import { setAppLocale } from '@/i18n'

const push = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ me: null }),
}))

vi.mock('@/shared/sfx', () => ({ playTap: vi.fn() }))

describe('KidGamesPage', () => {
  it('opens each game from the game planet', async () => {
    setAppLocale('zh-CN')
    const wrapper = mount(KidGamesPage)
    const cards = wrapper.findAll('button.card')
    expect(cards).toHaveLength(3)
    const schulte = cards[0]
    const mirror = cards[1]
    const sudoku = cards[2]
    if (!schulte || !mirror || !sudoku) throw new Error('missing game cards')
    expect(schulte.text()).toContain('舒尔特方格')
    expect(mirror.text()).toContain('镜像对称拼贴')
    expect(sudoku.text()).toContain('数独')
    await schulte.trigger('click')
    expect(push).toHaveBeenCalledWith({ name: 'kid-schulte' })
    await mirror.trigger('click')
    expect(push).toHaveBeenCalledWith({ name: 'kid-mirror' })
    await sudoku.trigger('click')
    expect(push).toHaveBeenCalledWith({ name: 'kid-sudoku' })
  })
})
