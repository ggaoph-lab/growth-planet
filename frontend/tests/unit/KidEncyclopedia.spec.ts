import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KidEncyclopediaPage from '@/kid/pages/KidEncyclopediaPage.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'

vi.mock('@/shared/api/client', () => ({
  ApiError: class extends Error {},
  api: {
    get: vi.fn(),
    postIdempotent: vi.fn(),
    post: vi.fn(),
  },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ me: { child: { preferences: { sounds: false } } } }),
}))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))
vi.mock('@/shared/sfx', () => ({ playTap: vi.fn() }))
vi.mock('@/shared/speak', () => ({
  unlockSpeak: vi.fn().mockResolvedValue(true),
  speakPhrase: vi.fn().mockResolvedValue(true),
}))

const subject = {
  subject: 'Science',
  title: '科学 / Science',
  emoji: '🔭',
  topic_count: 2,
  attribution: 'Marble',
  domains: [
    { key: 'ocean-life', domain: 'Ocean Life', title: '海洋生物 / Ocean Life', emoji: '🐋', topic_count: 2 },
  ],
}

const domain = {
  key: 'ocean-life',
  title: '海洋生物 / Ocean Life',
  emoji: '🐋',
  topics: [
    { id: 'mt_oAg79ju344', domain_key: 'ocean-life', name: '什么是海洋？ / What Is the Ocean?', emoji: '🌊', age_start: 5, age_end: 7, read: false },
  ],
}

const article = {
  id: 'mt_oAg79ju344',
  domain_key: 'ocean-life',
  domain: '海洋生物 / Ocean Life',
  name: '什么是海洋？ / What Is the Ocean?',
  body: '海洋很大。 / The ocean is big.',
  emoji: '🌊',
  age_start: 5,
  age_end: 7,
  read: false,
  prerequisites: [],
  next_topics: [],
  attribution: 'Marble',
}

describe('KidEncyclopediaPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setAppLocale('zh-CN')
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/encyclopedia') return subject
      if (path.startsWith('/kid/encyclopedia/domains/')) return domain
      if (path.startsWith('/kid/encyclopedia/topics/')) return article
      throw new Error(`unexpected get ${path}`)
    })
    vi.mocked(api.postIdempotent).mockResolvedValue({ ...article, read: true })
  })

  it('opens a domain then an article and marks it read', async () => {
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    expect(wrapper.text()).toContain('科学小百科')
    expect(wrapper.text()).toContain('海洋生物')

    await wrapper.findAll('button.domain')[0]!.trigger('click')
    await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/kid/encyclopedia/domains/ocean-life')
    expect(wrapper.text()).toContain('什么是海洋？')

    await wrapper.findAll('button.topic')[0]!.trigger('click')
    await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_oAg79ju344')
    expect(api.postIdempotent).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('海洋很大')
    expect(wrapper.text()).toContain('读给我听')
    await wrapper.find('button.speak:last-of-type').trigger('click')
    await flushPromises()
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_oAg79ju344/read', {})
    expect(wrapper.text()).toContain('读过')
  })

  it('shows the reviewed Chinese article body in bilingual mode', async () => {
    setAppLocale('zh-en')
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    await wrapper.find('button.domain').trigger('click')
    await flushPromises()
    await wrapper.find('button.topic').trigger('click')
    await flushPromises()

    expect(wrapper.find('.body').text()).toBe('海洋很大。')
  })
})
