<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, ApiError } from '@/shared/api/client'
import { canSpeak, speakPhrase, stopSpeaking, warmPhrases } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

type Item = { key: string; theme: string; kind: string; text: string; meaning: string; emoji: string; options: string[]; review: boolean; stage: number }
type Plan = { items: Item[]; completed: number; due: number; mastered: number; learned: number; total: number; next_review: string | null }
type Result = { correct: boolean; meaning: string; xp: number; coins: number; next_review: string; plan: Plan }
const { locale } = useI18n()
const session = useSessionStore()
const label = (zh: string, en: string) => locale.value === 'en' ? en : locale.value === 'zh-en' ? `${zh} / ${en}` : zh
const plan = ref<Plan | null>(null)
const current = ref<Item | null>(null)
const phase = ref<'learn' | 'quiz' | 'result'>('learn')
const result = ref<Result | null>(null)
const busy = ref(false)
const error = ref('')
const audioMessage = ref('')
const speaking = ref(false)
const choices = ref<string[]>([])
const audioSupported = canSpeak()
const finished = computed(() => plan.value && !current.value)
function choose(item?: Item) {
  current.value = item ?? null
  phase.value = item?.review ? 'quiz' : 'learn'
  result.value = null
  choices.value = [...(item?.options ?? [])]
  for (let i = choices.value.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[choices.value[i], choices.value[j]] = [choices.value[j]!, choices.value[i]!]
  }
  audioMessage.value = ''
  if (item?.text) warmPhrases([item.text], 'en-US')
}
async function load() {
  busy.value = true
  error.value = ''
  try {
    plan.value = await api.get<Plan>('/kid/english/learning')
    choose(plan.value.items[0])
  } catch (e) { error.value = e instanceof ApiError ? e.message : label('学习任务加载失败，请重试。', 'Could not load your mission. Please retry.') }
  finally { busy.value = false }
}
let speakToken = 0
async function speak() {
  if (!current.value) return
  if (speaking.value) {
    stopSpeaking()
    speaking.value = false
    speakToken += 1
    return
  }
  const token = ++speakToken
  speaking.value = true
  audioMessage.value = ''
  try {
    await speakPhrase(current.value.text, 'en-US')
  } catch {
    if (token === speakToken) audioMessage.value = label('暂时无法朗读，可以请家长陪你读。', 'Audio is unavailable. Ask a grown-up to read with you.')
  } finally {
    if (token === speakToken) speaking.value = false
  }
}
function listenText(idle: string, idleEn: string) {
  return speaking.value ? `⏹ ${label('停止', 'Stop')}` : `🔊 ${label(idle, idleEn)}`
}
async function answer(value: string) {
  if (!current.value || busy.value || phase.value !== 'quiz') return
  busy.value = true
  error.value = ''
  try {
    result.value = await api.postIdempotent<Result>('/kid/english/learning/answer', { key: current.value.key, answer: value })
    plan.value = result.value.plan
    phase.value = 'result'
    // A balance refresh should not hide a successfully saved answer.
    if (result.value.correct) void session.load(true).catch(() => {})
  } catch (e) { error.value = e instanceof ApiError ? e.message : label('提交失败，请重试。', 'Could not save your answer. Please retry.') }
  finally { busy.value = false }
}
function next() {
  if (!result.value?.correct) {
    // Interleave a different card before returning to the missed item, when possible.
    const other = plan.value?.items.find(item => item.key !== current.value?.key)
    if (other) choose(other)
    else {
      choose(plan.value?.items.find(item => item.key === current.value?.key))
      phase.value = 'learn'
    }
  } else choose(plan.value?.items[0])
}
onMounted(load)
onUnmounted(() => { stopSpeaking() })
</script>

<template>
  <section class="learning" :aria-label="label('每日英语任务', 'Daily English mission')" :aria-busy="busy">
    <div class="intro"><span class="eyebrow">ENGLISH LAB · {{ label('每天一小步', 'One small step a day') }}</span>
      <h2>🚀 {{ label('今天，用英语探索世界', 'Explore your world in English') }}</h2>
      <p>{{ label('每天约 5–8 分钟 · 最多 3 个新内容 · 先复习，再探索', 'About 5–8 minutes · Up to 3 new cards · Review first') }}</p>
    </div>
    <div v-if="plan" class="stats">
      <span>✨ {{ label('今日完成', 'Done today') }} <b>{{ plan.completed }}/{{ plan.completed + plan.items.length }}</b></span>
      <span>🌱 {{ label('已接触', 'Discovered') }} <b>{{ plan.learned }}/{{ plan.total }}</b></span>
      <span>🌟 {{ label('熟悉内容', 'Familiar cards') }} <b>{{ plan.mastered }}</b></span>
    </div>
    <p v-if="error" role="alert" class="error">{{ error }} <button :disabled="busy" @click="load">{{ label('重新加载', 'Reload') }}</button></p>
    <p v-if="!plan && busy">{{ label('正在准备探索任务…', 'Preparing your mission…') }}</p>
    <article v-if="current" class="lesson">
      <div class="card-top"><span>{{ current.theme }} · {{ current.kind === 'word' ? label('推荐单词', 'Word') : label('生活短句', 'Everyday phrase') }}</span><span class="badge">{{ current.review ? label('记忆巩固', 'Review') : label('新发现', 'New discovery') }}</span></div>
      <template v-if="phase === 'learn'">
        <div class="emoji" aria-hidden="true">{{ current.emoji }}</div>
        <h3 lang="en">{{ current.text }}</h3><p class="meaning">{{ current.meaning }}</p>
        <p>{{ label('听一听，跟着说，再试着不看提示回想。', 'Listen, say it aloud, then try to remember without a hint.') }}</p>
        <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
        <button class="primary" @click="phase = 'quiz'">{{ label('我准备好了，挑战一下', 'Ready for a challenge') }} →</button>
      </template>
      <template v-else-if="phase === 'quiz'">
        <p>{{ current.review ? label('这句话或单词，用英语怎么说？', 'How do you say this in English?') : label('选出它的意思', 'Choose the meaning') }}</p>
        <h3 :lang="current.review ? 'zh' : 'en'">{{ current.review ? current.meaning : current.text }}</h3>
        <button v-if="!current.review" class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('再听一次', 'Listen again') }}</button>
        <div class="answers"><button v-for="option in choices" :key="option" :disabled="busy" @click="answer(option)">{{ option }}</button></div>
      </template>
      <div v-else-if="result" class="feedback" aria-live="polite">
        <div class="emoji">{{ result.correct ? '🎉' : '🌱' }}</div>
        <h3>{{ result.correct ? label('答对了！', 'You got it!') : label('没关系，再认识它一次', 'Let’s learn this one again') }}</h3>
        <p><strong lang="en">{{ current.text }}</strong> · {{ result.meaning }}</p>
        <p v-if="result.correct">+{{ result.xp }} XP · +{{ result.coins }} {{ label('金币', 'coins') }}<br>{{ label('下次复习', 'Next review') }}：{{ result.next_review }}</p>
        <p v-else>{{ label('答错不扣分。看看正确表达，稍后再试一次。', 'No points lost. Read the expression and try again shortly.') }}</p>
        <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
        <button class="primary" @click="next">{{ label('继续探索', 'Continue') }} →</button>
      </div>
      <p v-if="!audioSupported">{{ label('当前浏览器不支持朗读，可以和家长一起读。', 'Read together with a grown-up; audio is not supported here.') }}</p>
      <p v-if="audioMessage" role="status">{{ audioMessage }}</p>
    </article>
    <div v-else-if="finished" class="lesson done"><div class="emoji">🪐</div><h3>{{ label('今天的小任务完成啦', 'Your mission is complete') }}</h3><p>{{ label('离开屏幕，选一句今天学过的英语，对家人说一说吧！', 'Take a screen break and say one of today’s phrases to your family!') }}</p><p v-if="plan?.next_review">{{ label('下次复习', 'Next review') }}：{{ plan.next_review }}</p><p v-if="plan && plan.due > 0">{{ label('其余复习留到明天，慢慢来。', 'Save the remaining reviews for tomorrow. Take your time.') }}</p></div>
    <details class="rules"><summary>{{ label('学习与奖励规则', 'Learning and rewards') }}</summary><p>{{ label('每项每天首次答对获得奖励，默认 2 XP + 1 金币；每天最多完成 8 项。实际奖励以家长设置为准。答错不扣分，重新学会后也能领奖。', 'Each card earns a reward once per day: normally 2 XP + 1 coin, up to 8 cards. Parent settings apply. Mistakes cost nothing; you can still earn the reward after learning again.') }}</p><p>{{ label('答对后间隔 1、3、7、14 天复习；答错则缩短间隔，重新巩固。熟悉内容仍会继续复习。', 'Correct answers schedule reviews after 1, 3, 7, then 14 days. Mistakes restart the review sequence. Familiar cards continue to return.') }}</p></details>
  </section>
</template>

<style scoped>
.learning { margin: 24px 0 32px; padding: 24px; border: 1px solid #77b7d944; border-radius: 24px; background: linear-gradient(135deg, #23577933, #34523c22); }
.eyebrow { color: #8cdbc8; font-size: 12px; letter-spacing: 1px; }
h2 { margin: 10px 0; font-size: clamp(21px, 3vw, 28px); } p { line-height: 1.7; }
.intro p, .rules { opacity: .8; font-size: 14px; }
.stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 20px 0; }.stats span { background: #ffffff0a; padding: 10px 14px; border-radius: 12px; font-size: 14px; }.stats b { margin-left: 6px; }
.lesson { background: #071e3280; border: 1px solid #ffffff1a; border-radius: 20px; padding: 24px; text-align: center; }
.card-top { display: flex; gap: 10px; justify-content: space-between; align-items: center; font-size: 13px; text-align: left; }.badge { background: #8cdbc81c; color: #9fe4d3; border-radius: 20px; padding: 5px 12px; white-space: nowrap; }
.emoji { font-size: 52px; margin-top: 20px; } h3 { font-size: clamp(24px, 4vw, 36px); margin: 18px 0; overflow-wrap: anywhere; }.meaning { font-size: 20px; }
button { font: inherit; cursor: pointer; border-radius: 12px; min-height: 46px; padding: 10px 18px; color: inherit; border: 1px solid #ffffff35; background: #ffffff0a; }button:disabled { opacity: .5; cursor: wait; }button:focus-visible { outline: 3px solid #8cdbc8; outline-offset: 3px; }
.primary { display: block; margin: 18px auto 0; background: #8cdbc8; color: #102c31; font-weight: 700; border: 0; }.secondary { font-size: 14px; }
.answers { display: grid; gap: 12px; max-width: 480px; margin: 24px auto 0; }.answers button:hover:not(:disabled) { border-color: #8cdbc8; background: #8cdbc81c; }
.rules { margin-top: 20px; }.rules summary { cursor: pointer; }.error { color: #ffb4b4; }
@media(max-width: 520px) { .learning { padding: 16px; }.lesson { padding: 18px 14px; }.card-top { flex-wrap: wrap; }.stats { gap: 6px; }.stats span { padding: 8px; } }
</style>
