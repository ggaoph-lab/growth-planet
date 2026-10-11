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
  api: { get: vi.fn() },
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
      overview: '一个航海的故事',
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

  it('unfolds a series into an ordered filmstrip', async () => {
    const wrapper = await openPage()

    await wrapper.get('.fold-btn').trigger('click')

    const fold = wrapper.get('.series-fold')
    expect(fold.text()).toContain('冰雪奇缘')
    expect(fold.text()).toContain('冰雪奇缘2')
    expect(fold.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100')
  })

  it('opens the poster into the introduction and watch progress', async () => {
    const wrapper = await openPage()

    await wrapper.get('[aria-label="海洋奇缘"]').trigger('click')

    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.text()).toContain('一个航海的故事')
    expect(dialog.text()).toContain('Moana')
    expect(dialog.text()).toContain('2016')
    expect(dialog.text()).toContain('已看 30 / 100 分钟')
    expect(dialog.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('30')
  })
})
