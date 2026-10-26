import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EnglishLearning from '@/kid/components/EnglishLearning.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'
import { loadChinesePinyin } from '@/shared/chinesePinyin'
import { speakPhrase, warmPhrases } from '@/shared/speak'

vi.mock('@/shared/api/client', () => ({ ApiError: class extends Error {}, api: { get: vi.fn(), post: vi.fn(), postIdempotent: vi.fn(), upload: vi.fn() } }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ load: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/shared/speak', () => ({
  canSpeak: () => true,
  isSpeakUnlocked: vi.fn(() => true),
  unlockSpeak: vi.fn().mockResolvedValue(true),
  speakPhrase: vi.fn().mockResolvedValue(true),
  stopSpeaking: vi.fn(),
  warmPhrases: vi.fn(),
  warmPhrasesNow: vi.fn(),
}))

const tale = {
  id: 'red-riding-hood',
  title_zh: '小红帽',
  title_en: 'Little Red Riding Hood',
  source: '格林童话',
  source_en: 'Grimm',
  zh_lines: ['从前有一个小女孩。', '她去看望奶奶。'],
  en_lines: ['Once there was a little girl.', 'She went to see Grandma.'],
}

beforeEach(async () => {
  vi.clearAllMocks()
  setAppLocale('zh-CN')
  await loadChinesePinyin()
  vi.mocked(speakPhrase).mockResolvedValue(true)
  vi.mocked(api.get).mockImplementation(async (path: string) => {
    if (path === '/kid/english/learning/fairy-tale' || path.startsWith('/kid/english/learning/fairy-tale?')) {
      return {
        available: true,
        reason: null,
        heard: false,
        tale,
      }
    }
    if (path === '/kid/english/learning/records') {
      return {
        as_of: '2026-10-13',
        fairy_tales: [],
        fairy_tales_heard: 0,
      }
    }
    return {}
  })
  vi.mocked(api.postIdempotent).mockResolvedValue({
    available: true,
    reason: null,
    heard: true,
    tale,
  })
})

describe('Fairy tale planet', () => {
  it('shows today’s fairy tale without word-learning chrome', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('童话时间')
    expect(wrapper.text()).toContain('今天，来读一篇童话')
    expect(wrapper.text()).not.toContain('今日新词')
    expect(wrapper.text()).not.toContain('跟读今天一句')
    expect(wrapper.text()).not.toContain('再学一轮')
    expect(wrapper.find('.fairy-head').exists()).toBe(true)
    expect(wrapper.findAll('.fairy-box .word-chip').map(button => button.text())).toEqual(
      expect.arrayContaining(['Little', 'girl', 'Grandma']),
    )
    const titleChars = wrapper.findAll('.fairy-title .fairy-char').map(node => node.text())
    expect(titleChars.join('')).toBe('小红帽')
    expect(wrapper.find('.fairy-title .fairy-pinyin').text()).toMatch(/xiǎo/i)
    expect(warmPhrases).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('plays Chinese then English and marks heard after English', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.listen-all')[0]!.trigger('click')
    await flushPromises()
    expect(vi.mocked(speakPhrase).mock.calls.map(call => [String(call[0]), call[1], call[3]])).toEqual([
      ['从前有一个小女孩。', 'zh-CN', 1],
      ['她去看望奶奶。', 'zh-CN', 1],
    ])
    expect(api.postIdempotent).not.toHaveBeenCalledWith('/kid/english/learning/fairy-tale/heard', {})
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.listen-all')[1]!.trigger('click')
    await flushPromises()
    expect(vi.mocked(speakPhrase).mock.calls.map(call => [String(call[0]), call[1], call[3]])).toEqual([
      ['Once there was a little girl.', 'en-US', 1],
      ['She went to see Grandma.', 'en-US', 1],
    ])
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/english/learning/fairy-tale/heard', {})
    wrapper.unmount()
  })

  it('speaks one line from the side horns and respects speech rate', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('.rate-group').exists()).toBe(true)
    await wrapper.findAll('.rate-chip').find(btn => btn.text() === '慢')!.trigger('click')
    vi.mocked(speakPhrase).mockClear()
    const horns = wrapper.findAll('.fairy-line .line-horn')
    expect(horns.length).toBeGreaterThanOrEqual(4)
    await horns[0]!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('从前有一个小女孩。', 'zh-CN', undefined, 0.65)
    vi.mocked(speakPhrase).mockClear()
    await horns[1]!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('Once there was a little girl.', 'en-US', undefined, 0.65)
    wrapper.unmount()
  })

  it('speaks a tapped English word and shows its Chinese meaning in a bubble', async () => {
    vi.mocked(api.post).mockResolvedValueOnce({ word: 'girl', meaning_zh: '女孩', pos_zh: '名词' })
    const wrapper = mount(EnglishLearning, { attachTo: document.body })
    await flushPromises()
    vi.mocked(speakPhrase).mockClear()
    const chip = wrapper.findAll('.fairy-box .word-chip').find(button => button.text() === 'girl')
    expect(chip).toBeTruthy()
    await chip!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('girl', 'en-US', undefined, 1)
    expect(api.post).toHaveBeenCalledWith('/kid/english-beta/words/lookup', {
      word: 'girl',
      context: 'Once there was a little girl.',
    })
    expect(document.querySelector('.fairy-word-bubble')?.textContent).toContain('女孩')
    expect(document.querySelector('.fairy-word-bubble')?.textContent).toContain('名词')
    expect(document.querySelector('.fairy-word-bubble-emoji')?.textContent).toBe('👧')
    document.querySelector<HTMLButtonElement>('.fairy-word-bubble-play[aria-label="播放中文：女孩"]')?.click()
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('女孩', 'zh-CN', undefined, 1)
    document.querySelector<HTMLButtonElement>('.fairy-word-bubble-close')?.click()
    await flushPromises()
    expect(document.querySelector('.fairy-word-bubble')).toBeNull()
    wrapper.unmount()
  })

  it('opens fairy-tale history and can reread with listen actions', async () => {
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/records') {
        return {
          as_of: '2026-10-13',
          fairy_tales: [{ date: '2026-10-12', id: 'red-riding-hood', title_zh: '小红帽', title_en: 'Little Red Riding Hood', source: '格林童话', heard: true }],
          fairy_tales_heard: 1,
        }
      }
      if (path.startsWith('/kid/english/learning/fairy-tale?')) {
        return { available: true, reason: null, heard: true, tale, date: '2026-10-12' }
      }
      return { available: true, reason: null, heard: false, tale }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('已读童话')
    await wrapper.find('.stat-chip').trigger('click')
    await flushPromises()
    await wrapper.find('.record-title-btn').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('再读一遍')
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.listen-all')[0]!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('从前有一个小女孩。', 'zh-CN', undefined, 1)
    wrapper.unmount()
  })
})
