import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, expect, it, vi } from 'vitest'
import KidEnglishBetaPage from '@/kid/pages/KidEnglishBetaPage.vue'
import { api } from '@/shared/api/client'
import { speakPhrase, unlockSpeak } from '@/shared/speak'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))
vi.mock('@/shared/api/client', () => ({
  ApiError: class extends Error {},
  api: { get: vi.fn(), post: vi.fn(), upload: vi.fn() },
}))
vi.mock('@/shared/speak', () => ({
  unlockSpeak: vi.fn().mockResolvedValue(true),
  speakPhrase: vi.fn().mockResolvedValue(true),
  stopSpeaking: vi.fn(),
  probeSpeakEngine: vi.fn().mockResolvedValue('neural'),
  warmPhrases: vi.fn(),
  warmPhrasesNow: vi.fn(),
}))

class FakeRecorder {
  static isTypeSupported = (type: string) => type === 'audio/webm'
  state = 'inactive'
  ondataavailable: ((event: { data: Blob }) => void) | null = null
  onstop: (() => void) | null = null
  onerror: (() => void) | null = null
  start() { this.state = 'recording' }
  stop() {
    if (this.state !== 'recording') return
    this.state = 'inactive'
    this.ondataavailable?.({ data: new Blob([new Uint8Array(64)], { type: 'audio/webm' }) })
    this.onstop?.()
  }
}

const home = {
  settings: { minutes_per_session: 20, days_per_week: 5, allow_voice_input: true, voice_available: true, speech_rate_percent: 85 },
  scenes: [{ code: 'supermarket', title_zh: '超市星球', title_en: 'Supermarket Planet', greeting: 'Hello', words: ['milk'], missions: [{ code: 'ask_for_milk', zh: '询问牛奶', en: 'Ask for milk' }] }],
  vocabulary: [], weekly_plan: null,
  active_session: {
    id: 7, scene: 'supermarket', started_at: '2026-10-26', greeting: 'Hello', greeting_zh: '你好',
    turns: [], completed_tasks: [], listening_target: null, listening_progress: { completed: 1, total: 1 }, practice_word: 'milk',
  },
}

beforeEach(() => {
  vi.restoreAllMocks()
  vi.clearAllMocks()
  push.mockReset()
  push.mockResolvedValue(undefined)
  let clock = 0
  const turns: Array<{ speaker: string; text: string; text_zh?: string }> = []
  vi.spyOn(Date, 'now').mockImplementation(() => { clock += 600; return clock })
  Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true })
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [{ stop: vi.fn(), addEventListener: vi.fn() }] }) } })
  vi.stubGlobal('MediaRecorder', FakeRecorder)
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home,
    active_session: home.active_session
      ? { ...home.active_session, turns: [...turns] }
      : null,
  }))
  vi.mocked(api.upload).mockResolvedValue({ text: 'I would like milk, please.', voice_draft_id: 'draft-1' })
  vi.mocked(api.post).mockImplementation(async (url: string, body?: unknown) => {
    if (String(url).includes('/turns')) {
      const text = String((body as { text?: string } | undefined)?.text ?? '')
      turns.push(
        { speaker: 'child', text },
        { speaker: 'partner', text: 'Great!', text_zh: '太棒了！' },
      )
      return { text: 'Great!', text_zh: '太棒了！', completed_tasks: [] }
    }
    return { text: 'Great!', text_zh: '太棒了！', completed_tasks: [] }
  })
})

it('expands the speaking card and restores the page on Escape', async () => {
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const originalOverflow = document.body.style.overflow
  await wrapper.get('button[aria-label="全屏显示对话"]').trigger('click')
  expect(wrapper.get('[data-card-id="talk"]').classes()).toContain('fullscreen')
  expect(wrapper.get('button[aria-label="退出全屏"]').attributes('aria-pressed')).toBe('true')
  expect(document.body.style.overflow).toBe('hidden')
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
  await nextTick()
  expect(wrapper.get('[data-card-id="talk"]').classes()).not.toContain('fullscreen')
  expect(document.body.style.overflow).toBe(originalOverflow)
  wrapper.unmount()
})

it('cancels without uploading, then transcribes and sends a confirmed voice draft', async () => {
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const button = (label: string) => {
    const found = wrapper.findAll('button').find(item => item.text().includes(label))
    if (!found) throw new Error(`Missing button: ${label}`)
    return found
  }
  await button('说话').trigger('click')
  await flushPromises()
  expect(button('取消录音').exists()).toBe(true)
  await button('取消录音').trigger('click')
  await flushPromises()
  expect(api.upload).not.toHaveBeenCalled()

  await button('说话').trigger('click')
  await flushPromises()
  await button('停止并识别').trigger('click')
  await flushPromises()
  expect(api.upload).toHaveBeenCalledTimes(1)
  expect(wrapper.get('#beta-input').element).toHaveProperty('value', 'I would like milk, please.')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/sessions/7/turns', {
    text: 'I would like milk, please.', input_mode: 'speech', voice_draft_id: 'draft-1',
  })
  expect(unlockSpeak).toHaveBeenCalled()
  expect(speakPhrase).toHaveBeenCalledWith('Great!', 'en-US', undefined, 0.85)
  const zhSpeakers = wrapper.findAll('button.icon-speak').filter(btn => btn.attributes('title') === '中文翻译')
  expect(zhSpeakers.length).toBeGreaterThanOrEqual(2)
  const lastZhSpeaker = zhSpeakers.at(-1)
  if (!lastZhSpeaker) throw new Error('Missing Chinese speaker')
  await lastZhSpeaker.trigger('click')
  expect(speakPhrase).toHaveBeenCalledWith('太棒了！', 'zh-CN')
  wrapper.unmount()
})

it('auto-reads partner replies after typed send and keeps icon speakers transparent', async () => {
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  await wrapper.get('#beta-input').setValue('I would like milk, please.')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/sessions/7/turns', {
    text: 'I would like milk, please.', input_mode: 'text',
  })
  expect(speakPhrase).toHaveBeenCalledWith('Great!', 'en-US', undefined, 0.85)
  const speakers = wrapper.findAll('button.icon-speak')
  expect(speakers.length).toBeGreaterThanOrEqual(2)
  expect(speakers.every(btn => !btn.classes().includes('primary'))).toBe(true)
  wrapper.unmount()
})

it('uses the family English speaking speed while leaving Chinese speed unchanged', async () => {
  vi.mocked(api.get).mockResolvedValue({ ...home, settings: { ...home.settings, speech_rate_percent: 70 } })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  await wrapper.get('#beta-input').setValue('I would like milk, please.')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(speakPhrase).toHaveBeenCalledWith('Great!', 'en-US', undefined, 0.7)
  const zhSpeaker = wrapper.findAll('button.icon-speak').find(btn => btn.attributes('title') === '中文翻译')
  if (!zhSpeaker) throw new Error('Missing Chinese speaker')
  await zhSpeaker.trigger('click')
  expect(speakPhrase).toHaveBeenCalledWith(expect.any(String), 'zh-CN')
  wrapper.unmount()
})

it('waits for a meal choice before showing the next conversation', async () => {
  const choice = { code: 'pasta', title_zh: '番茄意面', title_en: 'Tomato pasta' }
  const choices = [choice,
    { code: 'sandwich', title_zh: '三明治', title_en: 'Sandwich' },
    { code: 'fruit_salad', title_zh: '水果沙拉', title_en: 'Fruit salad' }]
  let selected = false
  const active = {
    ...home.active_session,
    scene_data: { ...home.scenes[0], menu: { ...choice, ingredients: [], words: ['milk'] } },
    turns: [{ speaker: 'partner', text: 'What would you like tomorrow?' }],
    completed_tasks: ['collect_ingredients', 'ask_for_ingredient', 'checkout'],
    tomorrow_options: choices,
  }
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home,
    active_session: { ...active, tomorrow_choice: selected ? choice : null },
  }))
  vi.mocked(api.post).mockImplementation(async () => {
    selected = true
    return { text: 'Great! Tomorrow we will make Tomato pasta.', text_zh: '好呀！', completed_tasks: active.completed_tasks }
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  expect(wrapper.find('#beta-input').exists()).toBe(false)
  expect(wrapper.find('.dialogue-waiting').exists()).toBe(true)
  expect(wrapper.find('.dialogue p').exists()).toBe(false)
  expect(wrapper.findAll('.tomorrow-options button')).toHaveLength(3)
  expect(wrapper.findAll('.tomorrow-options .meal-emoji').map(emoji => emoji.text())).toEqual(['🍝', '🥪', '🍓'])
  const option = wrapper.findAll('.tomorrow-options button').find(button => button.text().includes('番茄意面'))
  if (!option) throw new Error('Missing meal option')
  await option.trigger('click')
  await flushPromises()
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/sessions/7/turns', {
    text: 'I would like Tomato pasta tomorrow.', input_mode: 'text',
  })
  expect(wrapper.find('#beta-input').exists()).toBe(true)
  expect(wrapper.find('.dialogue p').exists()).toBe(true)
  wrapper.unmount()
})

it('shows the speaking task before checkout without tomorrow meal choices', async () => {
  const menuScene = {
    ...home.scenes[0],
    menu: { code: 'pasta', title_zh: '番茄意面', title_en: 'Tomato pasta', ingredients: [] },
  }
  vi.mocked(api.get).mockResolvedValue({
    ...home,
    active_session: {
      ...home.active_session, scene_data: menuScene, completed_tasks: ['collect_ingredients'],
      tomorrow_options: [{ code: 'sandwich', title_zh: '三明治', title_en: 'Sandwich' }],
      tomorrow_choice: null,
    },
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  expect(wrapper.find('#beta-input').exists()).toBe(true)
  expect(wrapper.find('.dialogue p').exists()).toBe(true)
  expect(wrapper.findAll('.tomorrow-options button')).toHaveLength(0)
  wrapper.unmount()
})

it('scrolls the conversation to the latest reply after a new turn', async () => {
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const dialogue = wrapper.get('.dialogue').element as HTMLElement
  Object.defineProperty(dialogue, 'scrollHeight', { configurable: true, value: 500 })
  dialogue.scrollTop = 0
  await wrapper.get('#beta-input').setValue('I would like milk, please.')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  await nextTick()
  expect((wrapper.get('.dialogue').element as HTMLElement).scrollTop).toBe(500)
  wrapper.unmount()
})

it('lets the partner suggest a line without sending a child answer', async () => {
  let hinted = false
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home,
    active_session: {
      ...home.active_session,
      turns: hinted ? [{ speaker: 'hint', text: 'I would like milk, please.', text_zh: '我想要牛奶，谢谢。' }] : [],
    },
  }))
  vi.mocked(api.post).mockImplementation(async (url: string) => {
    if (url.endsWith('/hint')) {
      hinted = true
      return { text: 'I would like milk, please.', text_zh: '我想要牛奶，谢谢。', provider: 'mock' }
    }
    return {}
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  await wrapper.get('button.help').trigger('click')
  await flushPromises()
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/sessions/7/hint')
  expect(vi.mocked(api.post).mock.calls.some(([url]) => url.endsWith('/turns'))).toBe(false)
  expect(wrapper.text()).toContain('伙伴提示 / Hint')
  expect(wrapper.get('#beta-input').element).toHaveProperty('value', '')
  expect(speakPhrase).toHaveBeenCalledWith('I would like milk, please.', 'en-US', undefined, 0.85)
  wrapper.unmount()
})

it('moves from the final listening item to the next speaking mission', async () => {
  let target: string | null = 'milk'
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home,
    active_session: {
      ...home.active_session,
      listening_target: target,
      listening_progress: { completed: target ? 0 : 1, total: 1 },
    },
  }))
  vi.mocked(api.post).mockImplementation(async (url: string) => {
    if (url.includes('/listen')) {
      target = null
      return {
        correct: true,
        target: 'milk',
        next_target: null,
        listening_progress: { completed: 1, total: 1 },
        completed_tasks: [],
      }
    }
    return {}
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  expect(wrapper.find('[data-card-id="talk"]').exists()).toBe(false)
  expect(wrapper.find('[data-card-id="missions"]').exists()).toBe(false)
  await wrapper.find('.products button').trigger('click')
  await flushPromises()
  expect(wrapper.find('[data-card-id="listen"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('下一步：询问牛奶')
  expect(wrapper.findAll('.card-shell').map(card => card.attributes('data-card-id')).slice(0, 2)).toEqual(['intro', 'talk'])
  expect(speakPhrase).toHaveBeenCalledWith('Hello', 'en-US', undefined, 0.85)
  expect(speakPhrase).not.toHaveBeenCalledWith('Great! Now say: I would like milk, please.', 'en-US', undefined, 0.85)
  wrapper.unmount()
})

it('opens a scene with the first listening prompt before showing the speaking step', async () => {
  let started = false
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home,
    active_session: started
      ? { ...home.active_session, listening_target: 'milk', listening_progress: { completed: 0, total: 1 } }
      : null,
  }))
  vi.mocked(api.post).mockImplementation(async (url: string) => {
    if (url === '/kid/english-beta/sessions') {
      started = true
      return { ...home.active_session, listening_target: 'milk', listening_progress: { completed: 0, total: 1 } }
    }
    return {}
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  await wrapper.find('.scene-card').trigger('click')
  await flushPromises()
  expect(unlockSpeak).toHaveBeenCalled()
  expect(speakPhrase).toHaveBeenCalledWith('Find the milk.', 'en-US', undefined, 0.85)
  expect(speakPhrase).not.toHaveBeenCalledWith('Hello', 'en-US', undefined, 0.85)
  expect(wrapper.find('[data-card-id="listen"]').exists()).toBe(true)
  expect(wrapper.find('[data-card-id="talk"]').exists()).toBe(false)
  wrapper.unmount()
})

it('reads the next listening item immediately after the first correct choice', async () => {
  const listening = {
    ...home.active_session,
    scene_data: { ...home.scenes[0], words: ['apple', 'banana'] },
    listening_target: 'apple',
    listening_progress: { completed: 0, total: 2 },
  }
  vi.mocked(api.get).mockResolvedValue({ ...home, active_session: listening })
  vi.mocked(api.post).mockResolvedValue({
    correct: true, target: 'apple', next_target: 'banana',
    listening_progress: { completed: 1, total: 2 }, completed_tasks: [],
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const apple = wrapper.findAll('.products button').find(button => button.text().includes('apple'))
  if (!apple) throw new Error('Missing apple choice')
  await apple.trigger('click')
  await flushPromises()
  expect(speakPhrase).toHaveBeenCalledWith('Find the banana.', 'en-US', undefined, 0.85)
  expect(wrapper.text()).toContain('Found 1 of 2')
  expect(wrapper.find('[data-card-id="listen"]').exists()).toBe(true)
  expect(api.get).toHaveBeenCalledTimes(1)
  wrapper.unmount()
})

it('shows the daily recipe first and lets the child choose tomorrow through dialogue', async () => {
  const menuScene = {
    ...home.scenes[0], words: ['tomato', 'cheese'],
    menu: { code: 'pizza', title_zh: '披萨', title_en: 'Pizza', ingredients: [
      { word: 'tomato', zh: '番茄', icon: '🍅' }, { word: 'cheese', zh: '奶酪', icon: '🧀' },
    ] },
    missions: [
      { code: 'collect_ingredients', zh: '找齐今天的食材', en: 'Collect ingredients' },
      { code: 'ask_for_ingredient', zh: '询问食材', en: 'Ask for an ingredient' },
      { code: 'checkout', zh: '结账', en: 'Checkout' },
    ],
  }
  let collected = false
  vi.mocked(api.get).mockImplementation(async () => ({
    ...home, scenes: [menuScene], active_session: {
      ...home.active_session, scene_data: menuScene,
      listening_target: collected ? null : 'tomato',
      listening_progress: { completed: collected ? 2 : 0, total: 2 },
      completed_tasks: collected ? ['collect_ingredients', 'ask_for_ingredient', 'checkout'] : [],
      tomorrow_options: [{ code: 'sandwich', title_zh: '三明治', title_en: 'Sandwich' }],
      tomorrow_choice: null,
    },
  }))
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  expect(wrapper.text()).toContain('今天做 披萨')
  expect(wrapper.text()).toContain('番茄')
  expect(wrapper.find('[data-card-id="talk"]').exists()).toBe(false)
  collected = true
  wrapper.unmount()

  const talk = mount(KidEnglishBetaPage)
  await flushPromises()
  expect(talk.find('[data-card-id="listen"]').exists()).toBe(false)
  expect(talk.text()).toContain('明天想吃什么')
  await talk.find('.tomorrow-options button').trigger('click')
  await flushPromises()
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/sessions/7/turns', {
    text: 'I would like Sandwich tomorrow.', input_mode: 'text',
  })
  talk.unmount()
})

it('does not upload a recording when the child leaves the page', async () => {
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const record = wrapper.findAll('button').find(item => item.text().includes('说话'))
  if (!record) throw new Error('Missing record button')
  await record.trigger('click')
  await flushPromises()
  wrapper.unmount()
  await flushPromises()
  expect(api.upload).not.toHaveBeenCalled()
})

it('releases a microphone granted after the child cancelled the permission wait', async () => {
  let grant: (stream: { getTracks: () => Array<{ stop: ReturnType<typeof vi.fn> }> }) => void = () => { throw new Error('Permission not requested') }
  const stop = vi.fn()
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: { getUserMedia: vi.fn().mockReturnValue(new Promise(resolve => { grant = resolve })) },
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const record = wrapper.findAll('button').find(item => item.text().includes('说话'))
  if (!record) throw new Error('Missing record button')
  await record.trigger('click')
  const cancel = wrapper.findAll('button').find(item => item.text().includes('取消录音'))
  if (!cancel) throw new Error('Missing cancel button')
  await cancel.trigger('click')
  grant({ getTracks: () => [{ stop }] })
  await flushPromises()
  expect(stop).toHaveBeenCalledOnce()
  expect(api.upload).not.toHaveBeenCalled()
  wrapper.unmount()
})

it('pronounces a tapped partner word and shows its Chinese meaning in a bubble', async () => {
  vi.mocked(api.post).mockResolvedValueOnce({ word: 'Hello', meaning_zh: '你好', pos_zh: '感叹词' })
  const wrapper = mount(KidEnglishBetaPage, { attachTo: document.body })
  await flushPromises()
  const word = wrapper.findAll('span.dialogue-word').find(button => button.text() === 'Hello')
  if (!word) throw new Error('Missing tappable partner word')
  await word.trigger('click')
  await flushPromises()
  expect(speakPhrase).toHaveBeenCalledWith('Hello', 'en-US', undefined, 0.85)
  expect(api.post).toHaveBeenCalledWith('/kid/english-beta/words/lookup', { word: 'Hello', context: 'Hello' })
  expect(document.querySelector('.word-bubble')?.textContent).toContain('你好')
  expect(document.querySelector('.word-bubble')?.textContent).toContain('感叹词')
  expect(document.querySelector('.word-bubble-emoji')?.textContent).toBe('👋')
  document.querySelector<HTMLButtonElement>('.word-bubble-play[aria-label="播放中文：你好"]')?.click()
  await flushPromises()
  expect(speakPhrase).toHaveBeenCalledWith('你好', 'zh-CN')
  document.querySelector<HTMLButtonElement>('.word-bubble-close')?.click()
  await nextTick()
  expect(document.querySelector('.word-bubble')).toBeNull()
  wrapper.unmount()
})

it('pauses mid-visit without finishing so re-entry can restore progress', async () => {
  vi.mocked(api.get).mockResolvedValue({
    ...home,
    active_session: {
      ...home.active_session,
      listening_target: 'milk',
      listening_progress: { completed: 0, total: 1 },
      completed_tasks: [],
    },
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const leave = wrapper.findAll('button').find(item => item.text().includes('保存并离开'))
  if (!leave) throw new Error('Missing save-and-leave')
  await leave.trigger('click')
  await flushPromises()
  expect(vi.mocked(api.post).mock.calls.some(([url]) => String(url).includes('/finish'))).toBe(false)
  expect(push).toHaveBeenCalledWith({ name: 'kid-home' })
  wrapper.unmount()
})

it('finishes and scores only after scene missions are complete', async () => {
  vi.mocked(api.get).mockResolvedValue({
    ...home,
    active_session: {
      ...home.active_session,
      listening_target: null,
      completed_tasks: ['ask_for_milk', 'checkout'],
      scene_data: {
        ...home.scenes[0],
        missions: [
          { code: 'ask_for_milk', zh: '询问牛奶', en: 'Ask for milk' },
          { code: 'checkout', zh: '结账', en: 'Checkout' },
        ],
      },
    },
  })
  vi.mocked(api.post).mockImplementation(async (url: string) => {
    if (String(url).includes('/finish')) {
      return { score: 90, score_status: 'scored', score_feedback: 'Nice work!', point_reward: 9, coin_reward: 9 }
    }
    return { text: 'Great!', text_zh: '太棒了！', completed_tasks: ['ask_for_milk', 'checkout'] }
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const done = wrapper.findAll('button').find(item => item.text().includes('结束并保存'))
  if (!done) throw new Error('Missing finish-and-save')
  await done.trigger('click')
  await flushPromises()
  expect(vi.mocked(api.post).mock.calls.some(([url]) => String(url).includes('/finish'))).toBe(true)
  expect(push).not.toHaveBeenCalled()
  expect(wrapper.text()).toContain('本次探索已记录')
  wrapper.unmount()
})

it('shows only today’s two scenes and locks the other after one is chosen', async () => {
  vi.mocked(api.get).mockResolvedValue({
    ...home,
    active_session: null,
    selected_scene: 'bakery',
    scenes: [
      { ...home.scenes[0], code: 'supermarket', title_zh: '超市星球' },
      { ...home.scenes[0], code: 'bakery', title_zh: '面包店星球' },
    ],
  })
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const cards = wrapper.findAll('button.scene-card')
  expect(cards).toHaveLength(2)
  expect(cards[0]?.attributes('disabled')).toBeDefined()
  expect(cards[1]?.attributes('disabled')).toBeUndefined()
  wrapper.unmount()
})

it('keeps a sentence intact and waits to pronounce a tapped word without restarting speech', async () => {
  vi.mocked(api.get).mockResolvedValue({
    ...home,
    active_session: { ...home.active_session, greeting: 'Hello friend.', greeting_zh: '你好，朋友。' },
  })
  let finishSentence: ((played: boolean) => void) | undefined
  vi.mocked(speakPhrase).mockImplementation((text) => text === 'Hello friend.'
    ? new Promise<boolean>(resolve => { finishSentence = resolve })
    : Promise.resolve(true))
  const wrapper = mount(KidEnglishBetaPage)
  await flushPromises()
  const sentenceSpeaker = wrapper.findAll('button.icon-speak').find(button => button.attributes('aria-label') === '播放英语：Hello friend.')
  if (!sentenceSpeaker) throw new Error('Missing sentence speaker')
  await sentenceSpeaker.trigger('click')
  await flushPromises()
  await sentenceSpeaker.trigger('click')
  await flushPromises()
  expect(vi.mocked(speakPhrase).mock.calls.filter(call => call[0] === 'Hello friend.')).toHaveLength(1)
  const friend = wrapper.findAll('span.dialogue-word').find(word => word.text() === 'friend')
  if (!friend) throw new Error('Missing tappable word')
  await friend.trigger('click')
  await flushPromises()
  expect(vi.mocked(speakPhrase).mock.calls.some(call => call[0] === 'friend')).toBe(false)
  finishSentence?.(true)
  await flushPromises()
  expect(speakPhrase).toHaveBeenCalledWith('friend', 'en-US', undefined, 0.85)
  wrapper.unmount()
})
