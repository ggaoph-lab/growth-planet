import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KidEncyclopediaPage from '@/kid/pages/KidEncyclopediaPage.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'
import { speakPhrase } from '@/shared/speak'

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
  stopSpeaking: vi.fn(),
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
    {
      id: 'mt_oAg79ju344',
      domain_key: 'ocean-life',
      name: '广阔的海洋 / What Is the Ocean?',
      name_zh: '广阔的海洋',
      name_en: 'What Is the Ocean?',
      emoji: '🌊',
      age_start: 5,
      age_end: 7,
      read: false,
      locked: false,
    },
    {
      id: 'mt_locked',
      domain_key: 'ocean-life',
      name: '锁定篇 / Locked',
      name_zh: '锁定篇',
      name_en: 'Locked',
      emoji: '🔒',
      age_start: 5,
      age_end: 7,
      read: false,
      locked: true,
      unlock_hint: { id: 'mt_oAg79ju344', name_zh: '广阔的海洋', name_en: 'What Is the Ocean?', emoji: '🌊' },
    },
  ],
}

const article = {
  id: 'mt_oAg79ju344',
  domain_key: 'ocean-life',
  domain: '海洋生物 / Ocean Life',
  name: '广阔的海洋 / What Is the Ocean?',
  name_zh: '广阔的海洋',
  name_en: 'What Is the Ocean?',
  body: '海洋很大。 / The ocean is big.',
  body_zh: '海洋很大。',
  body_en: 'The ocean is big.',
  emoji: '🌊',
  age_start: 5,
  age_end: 7,
  read: false,
  locked: false,
  words: [{ word: 'ocean', meaning: '海洋' }],
  video: { provider: 'bilibili' as const, id: 'BV1xx411c7mD' },
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
    vi.mocked(api.post).mockResolvedValue({ word: 'ocean', added: true, already: false, meaning: '海洋' })
  })

  it('opens a domain then an article and marks it read', async () => {
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    expect(wrapper.text()).toContain('科学小百科')
    expect(wrapper.text()).toContain('海洋生物')

    await wrapper.findAll('button.domain')[0]!.trigger('click')
    await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/kid/encyclopedia/domains/ocean-life')
    expect(wrapper.text()).toContain('广阔的海洋')
    expect(wrapper.text()).toContain('What Is the Ocean?')

    await wrapper.findAll('button.topic')[0]!.trigger('click')
    await flushPromises()
    expect(api.get).toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_oAg79ju344')
    expect(api.postIdempotent).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('海洋很大')
    expect(wrapper.text()).toContain('The ocean is big')
    expect(wrapper.find('iframe').attributes('src')).toContain('player.bilibili.com')
    expect(wrapper.text()).toContain('听中文')
    expect(wrapper.text()).toContain('听英文')
    expect(wrapper.findAll('button.word-chip').length).toBe(0)
    await wrapper.find('button.finish-read').trigger('click')
    await flushPromises()
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_oAg79ju344/read', {})
    expect(wrapper.text()).toContain('读过')
  })

  it('shows Chinese above English and can collect a word by tapping English text', async () => {
    setAppLocale('zh-en')
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    await wrapper.find('button.domain').trigger('click')
    await flushPromises()
    await wrapper.findAll('button.topic')[0]!.trigger('click')
    await flushPromises()

    expect(wrapper.find('.body-zh').text()).toBe('海洋很大。')
    expect(wrapper.find('.body-en').text()).toContain('ocean')
    expect(wrapper.findAll('button.word-chip').length).toBe(0)
    await wrapper.findAll('button.en-word').find(btn => btn.text() === 'ocean')!.trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_oAg79ju344/words', { word: 'ocean' })
    expect(wrapper.text()).toContain('已加入生词表')
  })

  it('can read the article in English', async () => {
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    await wrapper.find('button.domain').trigger('click')
    await flushPromises()
    await wrapper.findAll('button.topic')[0]!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button.speak').find(btn => btn.text() === '听英文')!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('What Is the Ocean? The ocean is big.', 'en-US')
    wrapper.unmount()
  })

  it('blocks opening locked topics from the list', async () => {
    const wrapper = mount(KidEncyclopediaPage)
    await flushPromises()
    await wrapper.find('button.domain').trigger('click')
    await flushPromises()
    await wrapper.findAll('button.topic')[1]!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('先读完「广阔的海洋」')
    expect(api.get).not.toHaveBeenCalledWith('/kid/encyclopedia/topics/mt_locked')
  })
})
