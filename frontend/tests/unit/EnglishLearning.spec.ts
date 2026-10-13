import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EnglishLearning from '@/kid/components/EnglishLearning.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'

vi.mock('@/shared/api/client', () => ({ ApiError: class extends Error {}, api: { get: vi.fn(), post: vi.fn().mockResolvedValue({ items: [] }), postIdempotent: vi.fn() } }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ load: vi.fn().mockResolvedValue(undefined) }) }))
const card = { key: 'apple', theme: '水果野餐', kind: 'word', text: 'apple', meaning: '苹果', emoji: '🍎', options: ['苹果', '香蕉', '水'], review: false, stage: 0 }
const plan = { items: [card], completed: 0, due: 0, mastered: 0, learned: 0, total: 18, next_review: null }

beforeEach(() => { vi.clearAllMocks(); setAppLocale('zh-CN'); vi.mocked(api.get).mockResolvedValue(plan) })

describe('English learning', () => {
  it('hides study hints in a quiz and displays only server-confirmed rewards', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('.meaning').text()).toBe('苹果')
    await wrapper.find('.primary').trigger('click')
    expect(wrapper.find('.meaning').exists()).toBe(false)
    let finish!: (value: unknown) => void
    vi.mocked(api.postIdempotent).mockImplementation(() => new Promise(resolve => { finish = resolve }))
    await wrapper.findAll('.answers button').find(button => button.text() === '苹果')!.trigger('click')
    expect(wrapper.find('.answers button').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('+2 XP')
    finish({ correct: true, meaning: '苹果', xp: 2, coins: 1, next_review: '2026-10-14', plan: { ...plan, items: [], completed: 1 } })
    await flushPromises()
    expect(wrapper.text()).toContain('+2 XP')
    await wrapper.find('.primary').trigger('click')
    expect(wrapper.text()).toContain('今天的小任务完成啦')
    wrapper.unmount()
  })

  it('starts reviews with recall and gives a learning step after a mistake', async () => {
    const review = { ...card, review: true, options: ['apple', 'banana', 'water'] }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [review] })
    vi.mocked(api.postIdempotent).mockResolvedValue({ correct: false, meaning: '苹果', xp: 0, coins: 0, next_review: '2026-10-13', plan: { ...plan, items: [review] } })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('h3').text()).toBe('苹果')
    expect(wrapper.find('.meaning').exists()).toBe(false)
    await wrapper.findAll('.answers button').find(button => button.text() === 'banana')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('答错不扣分')
    await wrapper.find('.primary').trigger('click')
    expect(wrapper.find('.meaning').text()).toBe('苹果')
    expect(wrapper.find('h3').text()).toBe('apple')
    wrapper.unmount()
  })

  it('uses the refreshed review choices when retrying the only missed new card', async () => {
    vi.mocked(api.postIdempotent).mockResolvedValue({ correct: false, meaning: '苹果', xp: 0, coins: 0, next_review: '2026-10-13', plan: { ...plan, items: [{ ...card, review: true, options: ['apple', 'banana', 'water'] }] } })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await wrapper.find('.primary').trigger('click')
    await wrapper.findAll('.answers button').find(button => button.text() === '香蕉')!.trigger('click')
    await flushPromises()
    await wrapper.find('.primary').trigger('click')
    await wrapper.find('.primary').trigger('click')
    expect(wrapper.find('h3').text()).toBe('苹果')
    expect(wrapper.findAll('.answers button').map(button => button.text())).toContain('apple')
    wrapper.unmount()
  })

  it('asks for an English neural voice when the child taps listen', async () => {
    vi.mocked(api.post).mockResolvedValue({ available: false, audio_base64: null, mime: null, provider: null })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await wrapper.get('button.secondary').trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/kid/tts', { text: 'apple', lang: 'en-US' })
    wrapper.unmount()
  })

  it('offers recovery when loading fails', async () => {
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper.find('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toBe('apple')
    wrapper.unmount()
  })
})
