<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, ApiError } from '@/shared/api/client'
import { canSpeak, speakPhrase, stopSpeaking, warmPhrases } from '@/shared/speak'
import { useSessionStore } from '@/stores/session'

type Item = {
  key: string
  theme: string
  kind: string
  text: string
  meaning: string
  emoji: string
  options: string[]
  review: boolean
  stage: number
  ipa?: string
  pos?: string
  probe?: boolean
  challenge?: boolean
  prompt?: string
  question?: string
}
type Plan = {
  items: Item[]
  completed: number
  due: number
  mastered: number
  learned: number
  total: number
  next_review: string | null
  vocabulary?: { estimated_size: number; band: number }
  ai_status?: 'ok' | 'failed' | 'off'
}
type Result = { correct: boolean; meaning: string; xp: number; coins: number; next_review: string; plan: Plan; coach_tip?: string; probe?: boolean; challenge?: boolean }
type SpeakAttempt = {
  id: number
  target_text: string
  target_meaning: string
  score: number | null
  feedback: string | null
  status: string
}
type SpeakPrompt = {
  available: boolean
  target: { key: string; text: string; meaning: string; kind: string } | null
  attempt: SpeakAttempt | null
  retention_days: number
}
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
const aiConnected = computed(() => plan.value?.ai_status === 'ok')
const aiStatusTitle = computed(() => {
  if (plan.value?.ai_status === 'ok') return label('AI 已接入', 'AI connected')
  if (plan.value?.ai_status === 'failed') return label('AI 接入失败，已用本地内容', 'AI unavailable — using local cards')
  return label('未选择 AI 引擎', 'No AI engine selected')
})

const speakPrompt = ref<SpeakPrompt | null>(null)
const speakBusy = ref(false)
const speakError = ref('')
const recording = ref(false)
const micSupported = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined'
let mediaRecorder: MediaRecorder | null = null
let mediaStream: MediaStream | null = null
let recordChunks: Blob[] = []
let recordStartedAt = 0
let recordTimer: ReturnType<typeof setTimeout> | null = null

function kindLabel(item: Item) {
  if (item.probe && item.kind === 'sentence') return label('生活短句', 'Everyday phrase')
  if (item.probe) return label('词汇小测试', 'Vocabulary check')
  if (item.challenge && item.kind === 'story') return label('短故事', 'Short story')
  if (item.challenge && item.kind === 'grammar') return label('语法小练', 'Grammar play')
  return item.kind === 'word' ? label('推荐单词', 'Word') : label('生活短句', 'Everyday phrase')
}
function badgeLabel(item: Item) {
  if (item.probe) return label('词汇小测试', 'Vocabulary check')
  if (item.challenge) return label('探索挑战', 'Lab challenge')
  return item.review ? label('记忆巩固', 'Review') : label('新发现', 'New discovery')
}
function wordMeta(item: Item | null) {
  if (!item || item.kind !== 'word') return ''
  return [item.pos, item.ipa].filter(Boolean).join(' ')
}
function stars(score: number | null | undefined) {
  const n = Math.max(0, Math.min(5, score ?? 0))
  return '★'.repeat(n) + '☆'.repeat(5 - n)
}
function choose(item?: Item) {
  current.value = item ?? null
  phase.value = item?.probe || item?.review || item?.challenge ? 'quiz' : 'learn'
  if (item?.challenge) phase.value = 'learn'
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
async function loadSpeakPrompt() {
  try {
    speakPrompt.value = await api.get<SpeakPrompt>('/kid/english/learning/speak')
    if (speakPrompt.value.target?.text) warmPhrases([speakPrompt.value.target.text], 'en-US')
  } catch {
    speakPrompt.value = null
  }
}
watch(finished, (done) => {
  if (done) void loadSpeakPrompt()
})
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
async function speakTarget() {
  const text = speakPrompt.value?.target?.text
  if (!text) return
  if (speaking.value) {
    stopSpeaking()
    speaking.value = false
    speakToken += 1
    return
  }
  const token = ++speakToken
  speaking.value = true
  try {
    await speakPhrase(text, 'en-US')
  } catch {
    speakError.value = label('暂时无法朗读范读。', 'Could not play the model reading.')
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
function stopMicTracks() {
  mediaStream?.getTracks().forEach(track => track.stop())
  mediaStream = null
  mediaRecorder = null
}
async function startRecording() {
  if (!micSupported || speakBusy.value || recording.value || !speakPrompt.value?.available) return
  speakError.value = ''
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    speakError.value = label('需要麦克风权限才能跟读。可以请家长帮忙打开权限。', 'Microphone permission is needed. Ask a grown-up to allow it.')
    return
  }
  recordChunks = []
  const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : undefined
  mediaRecorder = mime ? new MediaRecorder(mediaStream, { mimeType: mime }) : new MediaRecorder(mediaStream)
  mediaRecorder.ondataavailable = (event) => {
    if (event.data.size > 0) recordChunks.push(event.data)
  }
  mediaRecorder.onstop = () => { void finishRecording() }
  recordStartedAt = Date.now()
  recording.value = true
  mediaRecorder.start()
  recordTimer = setTimeout(() => stopRecording(), 8000)
}
function stopRecording() {
  if (recordTimer) {
    clearTimeout(recordTimer)
    recordTimer = null
  }
  if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop()
  else {
    recording.value = false
    stopMicTracks()
  }
}
async function finishRecording() {
  recording.value = false
  const duration = Date.now() - recordStartedAt
  const blob = new Blob(recordChunks, { type: mediaRecorder?.mimeType || 'audio/webm' })
  stopMicTracks()
  if (blob.size < 200) {
    speakError.value = label('好像没录到声音，再试一次吧。', 'That recording was too quiet. Try again.')
    return
  }
  speakBusy.value = true
  speakError.value = ''
  try {
    const form = new FormData()
    form.append('audio', blob, 'speak.webm')
    form.append('duration_ms', String(Math.min(8000, duration)))
    const attempt = await api.upload<SpeakAttempt>('/kid/english/learning/speak', form, { idempotent: true })
    if (speakPrompt.value) speakPrompt.value = { ...speakPrompt.value, attempt }
  } catch (e) {
    speakError.value = e instanceof ApiError ? e.message : label('上传失败，请再试一次。', 'Upload failed. Please try again.')
  } finally {
    speakBusy.value = false
  }
}
onMounted(load)
onUnmounted(() => {
  stopSpeaking()
  stopRecording()
  stopMicTracks()
})
</script>

<template>
  <section class="learning" :aria-label="label('每日英语任务', 'Daily English mission')" :aria-busy="busy">
    <div class="intro">
      <span class="eyebrow">
        ENGLISH LAB · {{ label('每天一小步', 'One small step a day') }}
        <span
          class="ai-dot"
          :class="aiConnected ? 'on' : 'off'"
          :title="aiStatusTitle"
          :aria-label="aiStatusTitle"
          role="img"
        />
      </span>
      <h2>🚀 {{ label('今天，用英语探索世界', 'Explore your world in English') }}</h2>
      <p>{{ label('每天最多 10 题 · 最多 3 个新内容 · 其余用来认识你的词汇与短句', 'Up to 10 questions · Up to 3 new cards · The rest checks words and short phrases') }}</p>
    </div>
    <div v-if="plan" class="stats">
      <span>✨ {{ label('今日完成', 'Done today') }} <b>{{ plan.completed }}/{{ plan.completed + plan.items.length }}</b></span>
      <span>🌱 {{ label('已接触', 'Discovered') }} <b>{{ plan.learned }}/{{ plan.total }}</b></span>
      <span>🌟 {{ label('熟悉内容', 'Familiar cards') }} <b>{{ plan.mastered }}</b></span>
      <span>📖 {{ label('大约认识', 'Expressions you know') }} <b>{{ plan.vocabulary?.estimated_size ?? 0 }}</b></span>
    </div>
    <p v-if="error" role="alert" class="error">{{ error }} <button :disabled="busy" @click="load">{{ label('重新加载', 'Reload') }}</button></p>
    <p v-if="!plan && busy">{{ label('正在准备探索任务…', 'Preparing your mission…') }}</p>
    <article v-if="current" class="lesson">
      <div class="card-top"><span>{{ current.theme }} · {{ kindLabel(current) }}</span><span class="badge">{{ badgeLabel(current) }}</span></div>
      <template v-if="phase === 'learn'">
        <div class="emoji" aria-hidden="true">{{ current.emoji }}</div>
        <h3 lang="en">{{ current.challenge ? (current.prompt || current.text) : current.text }}</h3>
        <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <p v-if="!current.challenge" class="meaning">{{ current.meaning }}</p>
        <p v-else class="meaning">{{ current.question || current.meaning }}</p>
        <p>{{ current.challenge ? label('先读一读，再挑战选择题。', 'Read first, then take the challenge.') : label('听一听，跟着说，再试着不看提示回想。', 'Listen, say it aloud, then try to remember without a hint.') }}</p>
        <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
        <button class="primary" @click="phase = 'quiz'">{{ label('我准备好了，挑战一下', 'Ready for a challenge') }} →</button>
      </template>
      <template v-else-if="phase === 'quiz'">
        <p>{{ current.challenge ? (current.question || label('选出正确答案', 'Choose the best answer')) : current.review ? label('这句话或单词，用英语怎么说？', 'How do you say this in English?') : label('选出它的意思', 'Choose the meaning') }}</p>
        <h3 :lang="current.review ? 'zh' : 'en'">{{ current.challenge ? (current.prompt || current.text) : current.review ? current.meaning : current.text }}</h3>
        <p v-if="!current.review && wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <button v-if="!current.review" class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('再听一次', 'Listen again') }}</button>
        <div class="answers"><button v-for="option in choices" :key="option" :disabled="busy" @click="answer(option)">{{ option }}</button></div>
      </template>
      <div v-else-if="result" class="feedback" aria-live="polite">
        <div class="emoji">{{ result.correct ? '🎉' : '🌱' }}</div>
        <h3>{{ result.correct ? label('答对了！', 'You got it!') : label('没关系，再认识它一次', 'Let’s learn this one again') }}</h3>
        <p><strong lang="en">{{ current.text }}</strong> · {{ result.meaning }}</p>
        <p v-if="wordMeta(current)" class="meta">{{ wordMeta(current) }}</p>
        <p v-if="result.coach_tip" class="coach">{{ result.coach_tip }}</p>
        <p v-if="result.correct && !current.probe && !current.challenge">+{{ result.xp }} XP · +{{ result.coins }} {{ label('金币', 'coins') }}<br>{{ label('下次复习', 'Next review') }}：{{ result.next_review }}</p>
        <p v-else-if="current.probe">{{ label('这题用来认识你的词汇，不发金币。估计会留在家里，换一个 AI 也接着用。', 'This check estimates your vocabulary and does not award coins. The estimate stays with your family if the AI provider changes.') }}</p>
        <p v-else-if="current.challenge">{{ label('探索挑战由家长发布，不发金币。', 'Lab challenges are published by a parent and do not award coins.') }}</p>
        <p v-else>{{ label('答错不扣分。看看正确表达，稍后再试一次。', 'No points lost. Read the expression and try again shortly.') }}</p>
        <button class="secondary" :disabled="!audioSupported" @click="speak">{{ listenText('听英语', 'Listen') }}</button>
        <button class="primary" @click="next">{{ label('继续探索', 'Continue') }} →</button>
      </div>
      <p v-if="!audioSupported">{{ label('当前浏览器不支持朗读，可以和家长一起读。', 'Read together with a grown-up; audio is not supported here.') }}</p>
      <p v-if="audioMessage" role="status">{{ audioMessage }}</p>
    </article>
    <div v-else-if="finished" class="lesson done">
      <div class="emoji">🪐</div>
      <h3>{{ label('今天的小任务完成啦', 'Your mission is complete') }}</h3>
      <p>{{ label('离开屏幕前，可以用小话筒跟读今天一句。练开口，不是正式考试。', 'Before you go, try the mic and read today’s line aloud. Practice only — not a real exam.') }}</p>
      <div v-if="speakPrompt?.available && speakPrompt.target" class="speak-box">
        <p class="speak-label">🎤 {{ label('跟读今天一句', 'Read today’s line') }}</p>
        <h3 lang="en" class="speak-text">{{ speakPrompt.target.text }}</h3>
        <p class="meaning">{{ speakPrompt.target.meaning }}</p>
        <button class="secondary" :disabled="!audioSupported || speakBusy" @click="speakTarget">{{ listenText('先听范读', 'Hear model') }}</button>
        <button
          class="mic"
          :class="{ recording }"
          :disabled="speakBusy || !micSupported"
          @click="recording ? stopRecording() : startRecording()"
        >
          {{ recording ? label('⏹ 说完了', '⏹ Done') : speakBusy ? label('正在听…', 'Listening…') : label('🎙️ 按住说 / 点一下开始', '🎙️ Tap to speak') }}
        </button>
        <p v-if="!micSupported" class="hint">{{ label('这个浏览器不能录音，可以请家长陪你口头跟读。', 'Recording is unavailable here. Ask a grown-up to practice with you.') }}</p>
        <p v-if="speakError" class="error" role="alert">{{ speakError }}</p>
        <div v-if="speakPrompt.attempt?.score" class="speak-result" aria-live="polite">
          <p class="stars">{{ stars(speakPrompt.attempt.score) }}</p>
          <p>{{ speakPrompt.attempt.feedback }}</p>
          <button class="secondary" :disabled="speakBusy || recording" @click="startRecording">{{ label('再录一次', 'Try again') }}</button>
        </div>
        <p class="hint">{{ label('录音会留给家长听几天，到期自动删除，不发金币。', 'Parents can hear this for a few days, then it deletes. No coins.') }}</p>
      </div>
      <p v-else-if="speakPrompt && !speakPrompt.available" class="hint">{{ label('今天还没有答对的句子可跟读，明天继续。', 'No correct phrase to read yet today. Try again tomorrow.') }}</p>
      <p>{{ label('离开屏幕，选一句今天学过的英语，对家人说一说吧！', 'Take a screen break and say one of today’s phrases to your family!') }}</p>
      <p v-if="plan?.next_review">{{ label('下次复习', 'Next review') }}：{{ plan.next_review }}</p>
      <p v-if="plan && plan.due > 0">{{ label('其余复习留到明天，慢慢来。', 'Save the remaining reviews for tomorrow. Take your time.') }}</p>
    </div>
    <details class="rules"><summary>{{ label('学习与奖励规则', 'Learning and rewards') }}</summary><p>{{ label('课程卡每天首次答对获得奖励，默认 2 XP + 1 金币；每天最多 10 题，其中课程奖励最多 10 项。词汇小测试、探索挑战与跟读打分不发金币。实际奖励以家长设置为准。答错不扣分。', 'Course cards earn a reward once per day: normally 2 XP + 1 coin, up to 10 questions. Vocabulary checks, lab challenges, and speak-along scores do not award coins. Parent settings apply. Mistakes cost nothing.') }}</p><p>{{ label('答对后间隔 1、3、7、14 天复习；答错则缩短间隔，重新巩固。熟悉内容仍会继续复习。', 'Correct answers schedule reviews after 1, 3, 7, then 14 days. Mistakes restart the review sequence. Familiar cards continue to return.') }}</p></details>
  </section>
</template>

<style scoped>
.learning { margin: 24px 0 32px; padding: 24px; border: 1px solid #77b7d944; border-radius: 24px; background: linear-gradient(135deg, #23577933, #34523c22); }
.eyebrow { color: #8cdbc8; font-size: 12px; letter-spacing: 1px; display: inline-flex; align-items: center; gap: 8px; }
.ai-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ai-dot.on { background: #3dd68c; box-shadow: 0 0 0 3px #3dd68c33; }
.ai-dot.off { background: #8b93a1; box-shadow: 0 0 0 3px #8b93a133; }
h2 { margin: 10px 0; font-size: clamp(21px, 3vw, 28px); } p { line-height: 1.7; }
.intro p, .rules, .hint { opacity: .8; font-size: 14px; }
.stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 20px 0; }.stats span { background: #ffffff0a; padding: 10px 14px; border-radius: 12px; font-size: 14px; }.stats b { margin-left: 6px; }
.lesson { background: #071e3280; border: 1px solid #ffffff1a; border-radius: 20px; padding: 24px; text-align: center; }
.card-top { display: flex; gap: 10px; justify-content: space-between; align-items: center; font-size: 13px; text-align: left; }.badge { background: #8cdbc81c; color: #9fe4d3; border-radius: 20px; padding: 5px 12px; white-space: nowrap; }
.emoji { font-size: 52px; margin-top: 20px; } h3 { font-size: clamp(24px, 4vw, 36px); margin: 18px 0 8px; overflow-wrap: anywhere; }.meaning { font-size: 20px; }
.meta { margin: 0 0 10px; font-size: 14px; color: #9fe4d3; letter-spacing: .02em; opacity: .95; }
.coach { margin: 12px auto 0; max-width: 420px; padding: 10px 14px; border-radius: 12px; background: #8cdbc814; color: #c9f3e8; font-size: 15px; }
.speak-box { margin: 20px auto 0; max-width: 480px; padding: 16px; border-radius: 16px; border: 1px solid #8cdbc844; background: #8cdbc812; }
.speak-label { margin: 0; font-size: 14px; color: #9fe4d3; }
.speak-text { margin-top: 8px; }
.mic { display: block; margin: 14px auto 0; min-width: 220px; background: #235779; border-color: #8cdbc8; font-weight: 700; }
.mic.recording { background: #c45c5c; border-color: #ffb4b4; animation: pulse-mic 1s ease-in-out infinite; }
.speak-result { margin-top: 14px; }
.stars { font-size: 22px; letter-spacing: 2px; color: #f5d76e; margin: 0 0 6px; }
@keyframes pulse-mic { 50% { transform: scale(1.03); } }
button { font: inherit; cursor: pointer; border-radius: 12px; min-height: 46px; padding: 10px 18px; color: inherit; border: 1px solid #ffffff35; background: #ffffff0a; }button:disabled { opacity: .5; cursor: wait; }button:focus-visible { outline: 3px solid #8cdbc8; outline-offset: 3px; }
.primary { display: block; margin: 18px auto 0; background: #8cdbc8; color: #102c31; font-weight: 700; border: 0; }.secondary { font-size: 14px; }
.answers { display: grid; gap: 12px; max-width: 480px; margin: 24px auto 0; }.answers button:hover:not(:disabled) { border-color: #8cdbc8; background: #8cdbc81c; }
.rules { margin-top: 20px; }.rules summary { cursor: pointer; }.error { color: #ffb4b4; }
@media(max-width: 520px) { .learning { padding: 16px; }.lesson { padding: 18px 14px; }.card-top { flex-wrap: wrap; }.stats { gap: 6px; }.stats span { padding: 8px; } }
</style>
