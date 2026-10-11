import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KidMoviesPage from '@/kid/pages/KidMoviesPage.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'
import type { KidCatalogItem, KidScreenTimeSummary } from '@/shared/api/types'

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

vi.mock('@/shared/api/client', () => ({
  ApiError: class ApiError extends Error {},
  api: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))

const screenTime: KidScreenTimeSummary = {
  movie: { period: 'week', period_start: '2026-10-05', limit_seconds: 3600, used_seconds: 600, remaining_seconds: 3000 },
  english: { period: 'week', period_start: '2026-10-05', limit_seconds: 1800, used_seconds: 0, remaining_seconds: 1800 },
}

const catalog: KidCatalogItem[] = [
  {
    kind: 'movie',
    movie: {
      id: 1,
      title_zh: '海洋奇缘',
      original_title: 'Moana',
      runtime_minutes: 100,
      overview: '一个航海的故事\n\nA story about sailing',
      release_year: 2016,
      poster_key: null,
      library: {
        id: 9,
        shelf: 'want',
        slot: null,
        progress: { position_seconds: 1800, status: 'WATCHING' },
      },
    },
  },
  {
    kind: 'collection',
    collection: {
      id: 4,
      name: '冰雪奇缘系列',
      visible_count: 2,
      watched_count: 1,
      completed_count: 1,
      progress_ratio: 0.5,
      next_movie_id: 3,
      sort_mode: 'story',
    },
    movies: [
      {
        id: 2,
        title_zh: '冰雪奇缘',
        runtime_minutes: 90,
        overview: '艾莎的故事',
        poster_key: null,
        library: { id: 10, shelf: 'want', slot: null, progress: { position_seconds: 0, status: 'COMPLETED' } },
      },
      {
        id: 3,
        title_zh: '冰雪奇缘2',
        runtime_minutes: 90,
        overview: '续集出发了',
        poster_key: null,
        library: { id: 11, shelf: 'want', slot: null, progress: { position_seconds: 0, status: 'NOT_STARTED' } },
      },
    ],
  },
]

describe('KidMoviesPage', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setAppLocale('zh-CN')
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/movies') return catalog
      if (path === '/kid/screen-time') return screenTime
      throw new Error(path)
    })
  })

  async function openPage() {
    const wrapper = mount(KidMoviesPage, { global: { plugins: [createPinia()] } })
    await flushPromises()
    return wrapper
  }

  it('shows parent-opened movies without asking the child to add them', async () => {
    const wrapper = await openPage()

    expect(wrapper.text()).toContain('海洋奇缘')
    expect(wrapper.text()).toContain('冰雪奇缘系列')
    expect(wrapper.text()).not.toContain('加入库')
    expect(wrapper.find('.series-fold').exists()).toBe(false)
  })

  it('expands a series when the card itself is clicked', async () => {
    const wrapper = await openPage()

    await wrapper.get('.card.series').trigger('click')

    expect(wrapper.find('.series-fold').exists()).toBe(true)
    expect(wrapper.get('.series-fold').text()).toContain('冰雪奇缘')
  })

  it('unfolds a series into an ordered filmstrip', async () => {
    const wrapper = await openPage()

    await wrapper.get('.fold-btn').trigger('click')

    const fold = wrapper.get('.series-fold')
    expect(fold.text()).toContain('冰雪奇缘')
    expect(fold.text()).toContain('冰雪奇缘2')
    expect(fold.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100')
  })

  it('collapses an open series when clicking another card or empty space', async () => {
    const wrapper = await openPage()

    await wrapper.get('.fold-btn').trigger('click')
    expect(wrapper.find('.series-fold').exists()).toBe(true)

    await wrapper.get('[aria-label="海洋奇缘"]').trigger('click')
    expect(wrapper.find('.series-fold').exists()).toBe(false)

    await wrapper.get('.fold-btn').trigger('click')
    expect(wrapper.find('.series-fold').exists()).toBe(true)

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find('.series-fold').exists()).toBe(false)
  })

  it('opens the poster into the introduction and watch progress', async () => {
    const wrapper = await openPage()

    await wrapper.get('[aria-label="海洋奇缘"]').trigger('click')

    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.text()).toContain('一个航海的故事')
    expect(dialog.text()).toContain('A story about sailing')
    expect(dialog.find('.overview[lang="en"]').text()).toBe('A story about sailing')
    expect(dialog.text()).toContain('Moana')
    expect(dialog.text()).toContain('2016')
    expect(dialog.text()).toContain('已看 30 / 100 分钟')
    expect(dialog.get('[role="slider"]').attributes('aria-valuenow')).toBe('30')
  })

  it('lets a finished movie be dragged backward', async () => {
    const wrapper = await openPage()
    await wrapper.get('.fold-btn').trigger('click')
    const member = wrapper.findAll('button.member').find((button) => button.get('strong').text() === '冰雪奇缘')
    if (!member) throw new Error('missing movie')
    await member.trigger('click')

    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.get('[role="slider"]').attributes('aria-valuenow')).toBe('100')
    await dialog.get('input[type="range"]').setValue('40')

    expect(dialog.get('[role="slider"]').attributes('aria-valuenow')).toBe('40')
    expect(dialog.text()).not.toContain('完成并领取奖励')
  })

  it('shows the reward button only at 90 percent and adds another bar', async () => {
    vi.mocked(api.put).mockImplementation(async (_path: string, body?: unknown) => {
      const patch = (body ?? {}) as { position_percent?: number; language?: 'zh' | 'en' }
      return {
        viewings: [{
          id: 21,
          sequence: 1,
          language: patch.language ?? 'zh',
          position_percent: patch.position_percent ?? 0,
          status: 'WATCHING',
          reward_eligible: true,
          rewarded: false,
        }],
        reward: null,
      }
    })
    vi.mocked(api.post).mockResolvedValue({
      viewings: [
        { id: 21, sequence: 1, language: 'zh', position_percent: 30, status: 'WATCHING', reward_eligible: true, rewarded: false },
        { id: 22, sequence: 2, language: 'zh', position_percent: 0, status: 'NOT_STARTED', reward_eligible: true, rewarded: false },
      ],
      reward: null,
    })
    const wrapper = await openPage()
    await wrapper.get('[aria-label="海洋奇缘"]').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')

    expect(dialog.text()).not.toContain('完成并领取奖励')
    await dialog.get('input[type="range"]').setValue('90')
    expect(dialog.text()).toContain('完成并领取奖励')

    await dialog.get('button[aria-pressed="false"]').trigger('click')
    await flushPromises()
    expect(api.put).toHaveBeenCalledWith('/kid/movie-library/9/viewings', expect.objectContaining({ language: 'en', position_percent: 90 }))

    await dialog.get('button.add-watch').trigger('click')
    await flushPromises()
    expect(dialog.findAll('input[type="range"]')).toHaveLength(2)
  })

  it('locks the slider after a reward is claimed', async () => {
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/movies') {
        return [{
          kind: 'movie',
          movie: {
            id: 1,
            title_zh: '海洋奇缘',
            runtime_minutes: 100,
            overview: '一个航海的故事',
            poster_key: null,
            library: {
              id: 9,
              shelf: 'completed',
              slot: null,
              progress: { position_seconds: 6000, status: 'COMPLETED' },
              viewings: [
                { id: 21, sequence: 1, language: 'zh', position_percent: 100, status: 'COMPLETED', reward_eligible: false, rewarded: true },
              ],
            },
          },
        }]
      }
      if (path === '/kid/screen-time') return screenTime
      throw new Error(path)
    })
    vi.mocked(api.put).mockImplementation(async (_path: string, body?: unknown) => {
      const patch = (body ?? {}) as { position_percent?: number }
      return {
        viewings: [{
          id: 21,
          sequence: 1,
          language: 'zh',
          position_percent: patch.position_percent ?? 100,
          status: 'COMPLETED',
          reward_eligible: false,
          rewarded: true,
        }],
        reward: null,
      }
    })

    const wrapper = await openPage()
    await wrapper.get('[aria-label="海洋奇缘"]').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.text()).toContain('已领取积分')
    expect(dialog.text()).not.toContain('还可以继续调整进度')

    const slider = dialog.get('input[type="range"]')
    expect(slider.attributes('disabled')).toBeDefined()
    await slider.setValue('35')

    const english = dialog.get('button.lang-btn[aria-pressed="false"]')
    expect(english.attributes('disabled')).toBeDefined()
    await english.trigger('click')
    await flushPromises()

    expect(dialog.get('[role="slider"]').attributes('aria-valuenow')).toBe('100')
    expect(dialog.get('button.lang-btn[aria-pressed="true"]').text()).toContain('中文')
    expect(api.put).not.toHaveBeenCalled()
  })

  it('offers no points from the fifth watch', async () => {
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/movies') {
        return [{
          kind: 'movie',
          movie: {
            id: 8,
            title_zh: '头脑特工队',
            runtime_minutes: 100,
            overview: '情绪小人群',
            poster_key: null,
            library: {
              id: 80,
              shelf: 'watching',
              slot: null,
              progress: { position_seconds: 0, status: 'WATCHING' },
              viewings: [
                { id: 1, sequence: 1, language: 'zh', position_percent: 100, status: 'COMPLETED', reward_eligible: false, rewarded: true },
                { id: 2, sequence: 2, language: 'zh', position_percent: 100, status: 'COMPLETED', reward_eligible: false, rewarded: true },
                { id: 3, sequence: 3, language: 'en', position_percent: 100, status: 'COMPLETED', reward_eligible: false, rewarded: true },
                { id: 4, sequence: 4, language: 'zh', position_percent: 100, status: 'COMPLETED', reward_eligible: false, rewarded: true },
                { id: 5, sequence: 5, language: 'zh', position_percent: 95, status: 'WATCHING', reward_eligible: false, rewarded: false },
              ],
            },
          },
        }]
      }
      if (path === '/kid/screen-time') return screenTime
      throw new Error(path)
    })

    const wrapper = await openPage()
    await wrapper.get('[aria-label="头脑特工队"]').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')

    expect(dialog.findAll('input[type="range"]')).toHaveLength(5)
    expect(dialog.text()).toContain('完成（这次没有积分）')
    expect(dialog.text()).not.toContain('完成并领取奖励')
  })
})
