import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EnglishLearning from '@/kid/components/EnglishLearning.vue'
import { setAppLocale } from '@/i18n'
import { api } from '@/shared/api/client'
import { isSpeakUnlocked, unlockSpeak, speakPhrase, warmPhrases } from '@/shared/speak'

function answerButton(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('.answers .answer').find(button => button.find('.answer-main').text() === label)
}

vi.mock('@/shared/api/client', () => ({ ApiError: class extends Error {}, api: { get: vi.fn(), post: vi.fn().mockResolvedValue({ items: [] }), postIdempotent: vi.fn(), upload: vi.fn() } }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ load: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/shared/speak', () => ({
  canSpeak: () => true,
  isSpeakUnlocked: vi.fn(() => true),
  unlockSpeak: vi.fn().mockResolvedValue(true),
  speakPhrase: vi.fn().mockResolvedValue(true),
  stopSpeaking: vi.fn(),
  warmPhrases: vi.fn(),
}))
const card = { key: 'apple', theme: '水果野餐', kind: 'word', text: 'apple', meaning: '苹果', emoji: '🍎', ipa: '/ˈæpl/', pos: 'n.', options: ['苹果', '香蕉', '水'], review: false, stage: 0 }
const plan = {
  items: [card],
  completed: 0,
  due: 0,
  mastered: 0,
  learned: 0,
  total: 18,
  next_review: null,
  ai_status: 'ok' as const,
  new_words_goal: 10,
  new_words_completed: 0,
  fairy_tale_unlocked: false,
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(isSpeakUnlocked).mockReturnValue(true)
  vi.mocked(unlockSpeak).mockResolvedValue(true)
  vi.mocked(speakPhrase).mockResolvedValue(true)
  vi.useRealTimers()
  setAppLocale('zh-CN')
  vi.mocked(api.get).mockImplementation(async (path: string) => {
    if (path === '/kid/english/learning/speak') {
      return {
        available: true,
        target: { key: 'like-apples', text: 'I like apples.', meaning: '我喜欢苹果。', kind: 'sentence' },
        attempt: null,
        retention_days: 7,
      }
    }
    if (path === '/kid/english/learning/fairy-tale' || path.startsWith('/kid/english/learning/fairy-tale?')) {
      return {
        available: false,
        reason: 'need_new_words',
        new_words_completed: 0,
        new_words_goal: 10,
        heard: false,
        tale: null,
      }
    }
    if (path === '/kid/english/learning/records') {
      return {
        as_of: '2026-10-13',
        completed_today: [],
        new_words_today: [
          { key: 'word:apple', text: 'apple', meaning: '苹果', emoji: '🍎', status: 'pending' },
        ],
        learned: [],
        mastered: [],
        known_words: [],
        fairy_tales: [],
        new_words_goal: 10,
        new_words_completed: 0,
        fairy_tales_heard: 0,
        estimated_size: 0,
      }
    }
    return plan
  })
  vi.mocked(api.upload).mockResolvedValue({
    id: 1, target_text: 'I like apples.', target_meaning: '我喜欢苹果。', score: 4, feedback: '跟读很接近，继续加油！', status: 'local',
  })
})

describe('English learning', () => {
  it('keeps a blocked first word pending without adding an enable button', async () => {
    vi.mocked(isSpeakUnlocked).mockReturnValue(false)
    vi.mocked(speakPhrase).mockResolvedValueOnce(false).mockResolvedValue(true)
    vi.mocked(unlockSpeak).mockImplementation(async () => { vi.mocked(isSpeakUnlocked).mockReturnValue(true); return true })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).not.toContain('开启自动朗读')
    await wrapper.find('.lesson').trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledTimes(2)
    expect(speakPhrase).toHaveBeenLastCalledWith('apple', 'en-US', expect.any(Function))
    wrapper.unmount()
  })

  it('shows loading until the actual audio-start callback fires', async () => {
    let start!: () => void
    let finish!: (value: boolean) => void
    vi.mocked(speakPhrase).mockImplementation((_text, _lang, onStarted) => { start = onStarted!; return new Promise(resolve => { finish = resolve }) })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('准备语音')
    expect(wrapper.text()).not.toContain('⏹ 停止')
    start()
    await flushPromises()
    expect(wrapper.text()).toContain('⏹ 停止')
    finish(true)
    await flushPromises()
    wrapper.unmount()
  })

  it('does not double replay when the first interaction is the listen button', async () => {
    vi.mocked(isSpeakUnlocked).mockReturnValue(false)
    vi.mocked(speakPhrase).mockResolvedValueOnce(false).mockResolvedValue(true)
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    const listen = wrapper.findAll('button').find(button => button.text().includes('再听一次'))!
    await listen.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('labels phrase cards separately from words and sentences', async () => {
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [{ ...card, kind: 'phrase', text: 'a red apple', meaning: '一个红苹果', ipa: '', pos: '' }] })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('词组')
    expect(wrapper.text()).not.toContain('单词 ·')
    expect(wrapper.find('h3').text()).toContain('a red apple')
    expect(wrapper.find('.meta').exists()).toBe(false)
    wrapper.unmount()
  })

  it('starts with a challenge and displays only server-confirmed rewards', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('.ai-dot').classes()).toContain('on')
    expect(wrapper.find('.meta').text()).toContain('/ˈæpl/')
    expect(wrapper.find('.meaning').exists()).toBe(false)
    expect(wrapper.findAll('.answers .answer').length).toBeGreaterThan(0)
    let finish!: (value: unknown) => void
    vi.mocked(api.postIdempotent).mockImplementation(() => new Promise(resolve => { finish = resolve }))
    await answerButton(wrapper, '苹果')!.trigger('click')
    expect(wrapper.find('.answers .answer').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('+2 XP')
    finish({ correct: true, meaning: '苹果', xp: 2, coins: 1, next_review: '2026-10-14', plan: { ...plan, items: [], completed: 1 } })
    await flushPromises()
    expect(wrapper.text()).toContain('+2 XP')
    await wrapper.find('.primary').trigger('click')
    expect(wrapper.text()).toContain('这一轮完成啦')
    wrapper.unmount()
  })

  it('pauses after a new word for its phrase and sentence and warms companion audio', async () => {
    vi.useFakeTimers()
    const word = { ...card, usage: {
      phrase: { text: 'a red apple', meaning: '一个红苹果' },
      sentence: { text: 'I eat a red apple.', meaning: '我吃一个红苹果。' },
    } }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [word] })
    const nextCard = { ...card, key: 'banana', text: 'banana', meaning: '香蕉', emoji: '🍌', options: ['香蕉', '苹果', '水'] }
    vi.mocked(api.postIdempotent).mockResolvedValue({
      correct: true, meaning: '苹果', xp: 2, coins: 1, next_review: '2026-10-14',
      plan: { ...plan, items: [nextCard], completed: 1 },
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await answerButton(wrapper, '苹果')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('答对了')
    expect(warmPhrases).toHaveBeenCalledWith(expect.arrayContaining(['banana']), 'en-US')
    expect(warmPhrases).toHaveBeenCalledWith(expect.arrayContaining(['香蕉']), 'zh-CN')
    expect(wrapper.find('[data-usage="phrase"]').text()).toContain('a red apple')
    expect(wrapper.find('[data-usage="phrase"] .usage-pinyin').text()).toMatch(/hóng/)
    expect(wrapper.find('[data-usage="sentence"] .usage-pinyin').text()).toMatch(/wǒ/)
    expect(wrapper.find('[data-usage="sentence"]').text()).toContain('I eat a red apple.')
    expect(warmPhrases).toHaveBeenCalledWith(expect.arrayContaining(['a red apple', 'I eat a red apple.']), 'en-US')
    await vi.advanceTimersByTimeAsync(10000)
    expect(wrapper.find('.feedback').exists()).toBe(true)
    await wrapper.find('[data-usage="phrase"] button').trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('a red apple', 'en-US')
    expect(api.postIdempotent).toHaveBeenCalledTimes(1)
    await wrapper.find('.feedback > .primary').trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toContain('banana')
    expect(wrapper.find('.word-emoji').text()).toContain('🍌')
    expect(speakPhrase).toHaveBeenCalledWith('banana', 'en-US', expect.any(Function))
    wrapper.unmount()
  })

  it('after a correct answer follows plan order even if a same-text rematch is first', async () => {
    vi.useFakeTimers()
    const yellowRematch = {
      ...card, key: 'probe:yellow:r2', text: 'yellow', meaning: '黄色', emoji: '🟡',
      options: ['黄色', '紫色', '黑色'], probe: true,
    }
    const cloud = {
      ...card, key: 'probe:cloud', text: 'cloud', meaning: '云', emoji: '☁️',
      options: ['云', '河', '山'], probe: true,
    }
    vi.mocked(api.get).mockResolvedValue({
      ...plan,
      items: [{ ...card, key: 'probe:yellow', text: 'yellow', meaning: '黄色', emoji: '🟡', options: ['黄色', '紫色', '黑色'], probe: true }],
    })
    vi.mocked(api.postIdempotent).mockResolvedValue({
      correct: true, meaning: '黄色', xp: 0, coins: 0, next_review: '2026-10-13',
      plan: { ...plan, items: [yellowRematch, cloud], completed: 1 },
      probe: true,
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await answerButton(wrapper, '黄色')!.trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(1000)
    await flushPromises()
    // Plan order wins — do not skip yellow rematch to ping-pong onto cloud.
    expect(wrapper.find('h3').text()).toContain('yellow')
    wrapper.unmount()
  })

  it('auto-reads each new word when the card appears', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('apple', 'en-US', expect.any(Function))
    wrapper.unmount()
  })

  it('auto-reads English on spelling cards', async () => {
    const spell = {
      key: 'spell:garden', theme: '拼写填空', kind: 'spelling', text: 'garden', meaning: '花园',
      emoji: '✍️', options: [], review: false, stage: 0, spelling: true, blank_hint: 'g _ r _ e n',
    }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [spell] })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('根据意思，把单词拼写出来')
    expect(wrapper.text()).toContain('我不会')
    expect(wrapper.text()).toContain('听中文')
    expect(wrapper.text()).not.toContain('听中文意思')
    expect(speakPhrase).toHaveBeenCalledWith('garden', 'en-US', expect.any(Function))
    wrapper.unmount()
  })

  it('records a spelling skip and reveals the word', async () => {
    const spell = {
      key: 'spell:garden', theme: '拼写填空', kind: 'spelling', text: 'garden', meaning: '花园',
      emoji: '✍️', options: [], review: false, stage: 0, spelling: true, blank_hint: 'g _ r _ e n', probe: true,
    }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [spell] })
    vi.mocked(api.postIdempotent).mockResolvedValue({
      correct: false, meaning: '花园', xp: 0, coins: 0, next_review: '2026-10-13',
      plan: { ...plan, items: [spell] }, spelling: true,
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === '我不会')!.trigger('click')
    await flushPromises()
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/english/learning/answer', { key: 'spell:garden', answer: '__skip__' })
    expect(wrapper.text()).toContain('garden')
    expect(wrapper.text()).toContain('词汇小测试')
    wrapper.unmount()
  })

  it('keeps the quiz on screen and marks a wrong Chinese option red', async () => {
    vi.mocked(api.postIdempotent).mockResolvedValue({
      correct: false, meaning: '苹果', xp: 0, coins: 0, next_review: '2026-10-13',
      plan: { ...plan, items: [card] },
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await answerButton(wrapper, '香蕉')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toContain('apple')
    expect(answerButton(wrapper, '香蕉')!.classes()).toContain('wrong')
    expect(wrapper.text()).toContain('不对，再选一次')
    expect(wrapper.text()).not.toContain('刚才没答对')
    expect(answerButton(wrapper, '苹果')).toBeTruthy()
    wrapper.unmount()
  })

  it('reads a Chinese option from the speaker beside it', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    vi.mocked(speakPhrase).mockClear()
    expect(wrapper.findAll('.answers .option-speak').length).toBe(3)
    await wrapper.find('.answers .option-speak').trigger('click')
    await flushPromises()
    const spoken = vi.mocked(speakPhrase).mock.calls.map(call => call[0])
    expect(spoken.some(text => ['苹果', '香蕉', '水'].includes(String(text)))).toBe(true)
    expect(vi.mocked(speakPhrase).mock.calls.some(call => call[1] === 'zh-CN')).toBe(true)
    expect(api.postIdempotent).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows pinyin under Chinese meaning options', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    const pinyinLines = wrapper.findAll('.answer-pinyin').map(node => node.text())
    expect(pinyinLines.some(text => /píng\s*guǒ/i.test(text))).toBe(true)
    expect(pinyinLines.some(text => /xiāng\s*jiāo/i.test(text))).toBe(true)
    wrapper.unmount()
  })

  it('hides option speakers for English review choices', async () => {
    const review = { ...card, review: true, options: ['apple', 'banana', 'water'] }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [review] })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.findAll('.answers .option-speak').length).toBe(0)
    expect(wrapper.findAll('.answer-pinyin').length).toBe(0)
    wrapper.unmount()
  })

  it('starts reviews with Chinese recall and marks a wrong English option red', async () => {
    const review = { ...card, review: true, options: ['apple', 'banana', 'water'] }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [review] })
    vi.mocked(api.postIdempotent).mockResolvedValue({ correct: false, meaning: '苹果', xp: 0, coins: 0, next_review: '2026-10-13', plan: { ...plan, items: [review] }, coach_tip: '再听一遍：apple 意思是「苹果」。' })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('苹果', 'zh-CN', expect.any(Function))
    expect(speakPhrase).not.toHaveBeenCalledWith('apple', 'en-US', expect.any(Function))
    expect(wrapper.find('h3').text()).toBe('苹果')
    expect(wrapper.find('.meaning').exists()).toBe(false)
    await answerButton(wrapper, 'banana')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toBe('苹果')
    expect(answerButton(wrapper, 'banana')!.classes()).toContain('wrong')
    expect(wrapper.text()).toContain('不对，再选一次')
    expect(wrapper.find('.feedback').exists()).toBe(false)
    expect(answerButton(wrapper, 'apple')).toBeTruthy()
    wrapper.unmount()
  })

  it('warms audio for the whole daily plan on load', async () => {
    const nextCard = { ...card, key: 'banana', text: 'banana', meaning: '香蕉', emoji: '🍌', options: ['香蕉', '苹果', '水'] }
    const review = { ...card, key: 'water', text: 'water', meaning: '水', review: true, options: ['water', 'milk', 'juice'] }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [card, nextCard, review] })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(warmPhrases).toHaveBeenCalledWith(expect.arrayContaining(['apple', 'banana', 'water', 'milk', 'juice']), 'en-US')
    expect(warmPhrases).toHaveBeenCalledWith(expect.arrayContaining(['苹果', '香蕉', '水']), 'zh-CN')
    wrapper.unmount()
  })

  it('shows speak-along mic on the finished page', async () => {
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/speak') {
        return {
          available: true,
          target: { key: 'like-apples', text: 'I like apples.', meaning: '我喜欢苹果。', kind: 'sentence' },
          attempt: null,
          retention_days: 7,
        }
      }
      return { ...plan, items: [] }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('这一轮完成啦')
    expect(wrapper.text()).toContain('跟读今天一句')
    expect(wrapper.text()).toContain('I like apples.')
    expect(wrapper.text()).toContain('再学一轮')
    expect(wrapper.text()).toContain('回星球')
    wrapper.unmount()
  })

  it('starts another round when skipping speak-along', async () => {
    const nextCard = { ...card, key: 'banana', text: 'banana', meaning: '香蕉', emoji: '🍌', options: ['香蕉', '苹果', '水'] }
    let learningCalls = 0
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/speak') {
        return {
          available: true,
          target: { key: 'like-apples', text: 'I like apples.', meaning: '我喜欢苹果。', kind: 'sentence' },
          attempt: null,
          retention_days: 7,
        }
      }
      learningCalls += 1
      if (learningCalls === 1) return { ...plan, items: [] }
      return { ...plan, items: [nextCard], completed: 10 }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('先跳过跟读，再学一轮')
    await wrapper.findAll('button.primary').find(button => button.text().includes('再学一轮'))!.trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toContain('banana')
    expect(wrapper.find('.word-emoji').text()).toContain('🍌')
    wrapper.unmount()
  })

  it('shows challenge badge and coaching for grammar cards', async () => {
    const grammar = {
      key: 'grammar:1', theme: '语法小练', kind: 'grammar', text: 'I ___ happy.', meaning: '选正确的词',
      emoji: '🧩', options: ['am', 'is', 'are'], review: false, stage: 0, challenge: true, prompt: 'I ___ happy.', question: '选正确的词',
    }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [grammar] })
    vi.mocked(api.postIdempotent).mockResolvedValue({
      correct: true, meaning: '选正确的词', xp: 0, coins: 0, next_review: '2026-10-13',
      plan: { ...plan, items: [] }, coach_tip: '真棒，记住这个表达！', challenge: true,
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('探索挑战')
    expect(wrapper.findAll('.answers .answer').length).toBeGreaterThan(0)
    await answerButton(wrapper, 'am')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('不发金币')
    expect(wrapper.find('.coach').exists()).toBe(true)
    wrapper.unmount()
  })

  it('lets the child pick again after a miss without leaving the quiz', async () => {
    vi.mocked(api.postIdempotent)
      .mockResolvedValueOnce({ correct: false, meaning: '苹果', xp: 0, coins: 0, next_review: '2026-10-13', plan: { ...plan, items: [{ ...card, review: true, options: ['apple', 'banana', 'water'] }] } })
      .mockResolvedValueOnce({ correct: true, meaning: '苹果', xp: 2, coins: 1, next_review: '2026-10-14', plan: { ...plan, items: [] } })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await answerButton(wrapper, '香蕉')!.trigger('click')
    await flushPromises()
    expect(answerButton(wrapper, '香蕉')!.classes()).toContain('wrong')
    expect(wrapper.find('h3').text()).toContain('apple')
    await answerButton(wrapper, '苹果')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('答对了')
    wrapper.unmount()
  })

  it('asks for an English neural voice when the child taps listen', async () => {
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('apple', 'en-US', expect.any(Function))
    vi.mocked(speakPhrase).mockClear()
    await wrapper.get('button.secondary').trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('apple', 'en-US', expect.any(Function))
    wrapper.unmount()
  })

  it('offers recovery when loading fails', async () => {
    vi.mocked(api.get).mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper.find('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('h3').text()).toContain('apple')
    expect(wrapper.find('.word-emoji').exists()).toBe(true)
    expect(wrapper.find('.word-emoji').text()).toContain('🍎')
    wrapper.unmount()
  })

  it('shows connecting status while the mission plan is still loading', async () => {
    let release!: () => void
    const pending = new Promise<typeof plan>(resolve => { release = () => resolve(plan) })
    vi.mocked(api.get).mockImplementationOnce(() => pending)
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('正在准备探索任务')
    expect(wrapper.text()).toContain('正在连接')
    expect(wrapper.text()).not.toContain('未选择 AI 引擎')
    release()
    await flushPromises()
    expect(wrapper.find('.ai-dot').classes()).toContain('on')
    expect(wrapper.text()).toContain('AI 已接入')
    wrapper.unmount()
  })

  it('shows a sanitized AI failure reason on the status chip', async () => {
    vi.mocked(api.get).mockResolvedValue({ ...plan, ai_status: 'failed', ai_error: 'http_429' })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.find('.ai-dot').classes()).toContain('off')
    expect(wrapper.text()).toContain('AI 接入失败，已用本地内容')
    expect(wrapper.text()).toContain('请求过多，请稍后再试')
    expect(wrapper.find('.ai-status-chip').attributes('title')).toContain('请求过多')
    wrapper.unmount()
  })

  it('shows word emoji beside the quiz prompt without a duplicate banner', async () => {
    const probe = { ...card, probe: true, review: false, options: ['苹果', '香蕉', '水'] }
    vi.mocked(api.get).mockResolvedValue({ ...plan, items: [probe] })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).not.toContain('选出它的意思')
    expect(wrapper.find('.emoji-quiz').exists()).toBe(false)
    expect(wrapper.find('h3').text()).toContain('🍎')
    expect(wrapper.find('h3').text()).toContain('apple')
    wrapper.unmount()
  })

  it('shows today’s new-word progress chip', async () => {
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/records') {
        return {
          as_of: '2026-10-13',
          completed_today: [],
          new_words_today: [{ key: 'word:apple', text: 'apple', meaning: '苹果', emoji: '🍎', status: 'done' }],
          learned: [{ key: 'word:apple', text: 'apple', meaning: '苹果', emoji: '🍎', stage: 1 }],
          mastered: [],
          known_words: [{ key: 'known:apple', text: 'apple', meaning: '', emoji: '📖' }],
          fairy_tales: [],
          new_words_goal: 10,
          new_words_completed: 3,
          fairy_tales_heard: 0,
          estimated_size: 1,
        }
      }
      return { ...plan, new_words_completed: 3, new_words_goal: 10, fairy_tales_heard: 0 }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('今日新词')
    expect(wrapper.text()).toContain('3/10')
    expect(wrapper.text()).toContain('已读童话')
    await wrapper.findAll('.stat-chip').find(btn => btn.text().includes('今日新词'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('今日新词记录')
    expect(wrapper.text()).toContain('apple')
    wrapper.unmount()
  })

  it('opens fairy-tale history and plays Chinese or English from title actions', async () => {
    const tale = {
      id: 'red-riding-hood',
      title_zh: '小红帽',
      title_en: 'Little Red Riding Hood',
      source: '格林童话',
      zh_lines: ['从前有一个小女孩。'],
      en_lines: ['Once there was a little girl.'],
    }
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/records') {
        return {
          as_of: '2026-10-13',
          completed_today: [],
          new_words_today: [],
          learned: [],
          mastered: [],
          known_words: [],
          fairy_tales: [{ date: '2026-10-12', id: 'red-riding-hood', title_zh: '小红帽', title_en: 'Little Red Riding Hood', source: '格林童话', heard: true }],
          new_words_goal: 10,
          new_words_completed: 10,
          fairy_tales_heard: 1,
          estimated_size: 0,
        }
      }
      if (path.startsWith('/kid/english/learning/fairy-tale?')) {
        return { available: true, reason: null, new_words_completed: 10, new_words_goal: 10, heard: true, tale, date: '2026-10-12' }
      }
      return { ...plan, items: [], new_words_completed: 10, fairy_tale_unlocked: true, fairy_tales_heard: 1 }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    await wrapper.findAll('.stat-chip').find(btn => btn.text().includes('已读童话'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('已读童话')
    await wrapper.find('.record-title-btn').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('再读一遍')
    expect(wrapper.find('.fairy-head .fairy-actions').exists()).toBe(true)
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.fairy-actions button')[0]!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('从前有一个小女孩。', 'zh-CN')
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.fairy-actions button')[1]!.trigger('click')
    await flushPromises()
    expect(speakPhrase).toHaveBeenCalledWith('Once there was a little girl.', 'en-US')
    wrapper.unmount()
  })

  it('shows centered fairy-tale panel and marks heard after English', async () => {
    const tale = {
      id: 'red-riding-hood',
      title_zh: '小红帽',
      title_en: 'Little Red Riding Hood',
      source: '格林童话',
      source_en: 'Grimm',
      zh_lines: ['从前有一个小女孩。', '她去看望奶奶。'],
      en_lines: ['Once there was a little girl.', 'She went to see Grandma.'],
    }
    vi.mocked(api.get).mockImplementation(async (path: string) => {
      if (path === '/kid/english/learning/speak') {
        return { available: false, target: null, attempt: null, retention_days: 7 }
      }
      if (path === '/kid/english/learning/fairy-tale') {
        return {
          available: true,
          reason: null,
          new_words_completed: 10,
          new_words_goal: 10,
          heard: false,
          tale,
        }
      }
      return { ...plan, items: [], completed: 10, new_words_completed: 10, fairy_tale_unlocked: true }
    })
    const wrapper = mount(EnglishLearning)
    await flushPromises()
    expect(wrapper.text()).toContain('童话时间')
    expect(wrapper.find('.fairy-head').exists()).toBe(true)
    expect(wrapper.findAll('.fairy-box .word-chip').map(button => button.text())).toEqual(
      expect.arrayContaining(['Little', 'girl', 'Grandma']),
    )
    expect(wrapper.findAll('.fairy-actions button')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('先听中文再听英文')
    const titleChars = wrapper.findAll('.fairy-title .fairy-char').map(node => node.text())
    expect(titleChars.join('')).toBe('小红帽')
    expect(wrapper.find('.fairy-title .fairy-pinyin').text()).toMatch(/xiǎo/i)
    expect(wrapper.find('.fairy-title .fairy-en-text').text()).toBe('Little Red Riding Hood')
    expect(wrapper.find('.fairy-body .fairy-pinyin').exists()).toBe(true)
    expect(wrapper.find('.fairy-body .fairy-pinyin').text()).toMatch(/cóng/i)
    const lines = wrapper.findAll('.fairy-body .fairy-line')
    expect(lines).toHaveLength(2)
    const firstChars = lines[0]!.findAll('.fairy-char').map(node => node.text())
    expect(firstChars.join('')).toBe('从前有一个小女孩。')
    expect(lines[0]!.find('.fairy-en-text').text()).toBe('Once there was a little girl.')
    expect(lines[1]!.find('.fairy-en-text').text()).toBe('She went to see Grandma.')
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.fairy-actions button')[0]!.trigger('click')
    await flushPromises()
    expect(vi.mocked(speakPhrase).mock.calls.map(call => [String(call[0]), call[1]])).toEqual([
      ['从前有一个小女孩。', 'zh-CN'],
      ['她去看望奶奶。', 'zh-CN'],
    ])
    expect(api.postIdempotent).not.toHaveBeenCalledWith('/kid/english/learning/fairy-tale/heard', {})
    vi.mocked(speakPhrase).mockClear()
    await wrapper.findAll('.fairy-actions button')[1]!.trigger('click')
    await flushPromises()
    expect(vi.mocked(speakPhrase).mock.calls.map(call => [String(call[0]), call[1]])).toEqual([
      ['Once there was a little girl.', 'en-US'],
      ['She went to see Grandma.', 'en-US'],
    ])
    expect(api.postIdempotent).toHaveBeenCalledWith('/kid/english/learning/fairy-tale/heard', {})
    wrapper.unmount()
  })

  it('adds a tapped fairy-tale word and continues it from today’s new words', async () => {
    const tale = {
      id: 'red-riding-hood',
      title_zh: '小红帽',
      title_en: 'Little Red Riding Hood',
      source: '格林童话',
      source_en: 'Grimm',
      zh_lines: ['她穿过森林。'],
      en_lines: ['She walked through the forest.'],
    }
    const forest = {
      key: 'word:forest',
      theme: '今日新词',
      kind: 'word',
      text: 'forest',
      meaning: '森林',
      emoji: '🌲',
      options: ['森林', '海洋', '沙漠'],
      review: false,
      stage: 0,
      daily_word: true,
      collected: true,
    }
    vi.mocked(api.get).mockImplementation(async (path: string, query?: { prefer?: string }) => {
      if (path === '/kid/english/learning/speak') {
        return { available: false, target: null, attempt: null, retention_days: 7 }
      }
      if (path === '/kid/english/learning/fairy-tale') {
        return {
          available: true,
          reason: null,
          new_words_completed: 10,
          new_words_goal: 10,
          heard: true,
          tale,
          pack_words: [],
        }
      }
      if (path === '/kid/english/learning/records') {
        return {
          as_of: '2026-10-13',
          completed_today: [],
          new_words_today: [
            { key: 'word:shoemaker', text: 'shoemaker', meaning: '鞋匠', emoji: '👞', status: 'pending', collected: true },
            { key: 'word:forest', text: 'forest', meaning: '森林', emoji: '🌲', status: 'pending', collected: true },
          ],
          learned: [],
          mastered: [],
          known_words: [],
          fairy_tales: [],
          new_words_goal: 10,
          new_words_completed: 10,
          fairy_tales_heard: 1,
          estimated_size: 0,
        }
      }
      const resumed = query?.prefer === 'word:forest'
      return {
        ...plan,
        items: resumed ? [forest] : query?.prefer === 'word:shoemaker' ? [{
          key: 'word:shoemaker',
          theme: '今日新词',
          kind: 'word',
          text: 'shoemaker',
          meaning: '鞋匠',
          emoji: '👞',
          options: ['鞋匠', '厨师', '老师'],
          review: false,
          stage: 0,
          daily_word: true,
          collected: true,
        }] : [],
        completed: 10,
        new_words_completed: 10,
        fairy_tale_unlocked: true,
        fairy_tale_heard_today: true,
      }
    })
    vi.mocked(api.post).mockResolvedValue({
      word: 'forest',
      added: true,
      already: false,
      learned: false,
      pack_words: ['forest'],
    })

    const wrapper = mount(EnglishLearning)
    await flushPromises()
    const chip = wrapper.findAll('.fairy-box .word-chip').find(button => button.text() === 'forest')
    expect(chip).toBeTruthy()
    await chip!.trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/kid/english/learning/fairy-tale/words', { word: 'forest' })
    expect(wrapper.text()).toContain('已把「forest」加入今日新词')
    expect(chip!.classes()).toContain('added')

    await wrapper.findAll('.stat-chip').find(button => button.text().includes('今日新词'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('继续学习（2）')
    await wrapper.find('.records-continue').trigger('click')
    await flushPromises()
    expect(wrapper.find('.lesson h3').text()).toContain('forest')
    expect(wrapper.text()).toContain('森林')
    wrapper.unmount()
  })
})
