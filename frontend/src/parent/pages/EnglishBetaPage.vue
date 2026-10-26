<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { api } from '@/shared/api/client'

type Settings = {
  minutes_per_session: number
  days_per_week: number
  provider: string
  chat_model: string
  allow_ai: boolean
  enable_scoring: boolean
  max_point_reward: number
  max_coin_reward: number
  token_mode: 'frugal' | 'full'
  allow_voice_input: boolean
  speech_rate_percent: number
  voice_provider: string
  asr_model: string
  voice_available?: boolean
  tts_provider?: string
  tts_voice_en?: string
  models?: { chat: string; asr: string; tts: string }
}
type Session = { id: number; child_id: number; scene: string; started_at: string; ended_at: string | null; score: number | null; score_status: string | null; score_feedback: string | null; point_reward: number; coin_reward: number }
type Word = { child_id: number; word: string; understood: boolean; spoken: boolean; used_in_sentence: boolean }
type Task = { child_id: number; code: string; completed: boolean }
type Day = { date: string; sessions: number; completed: number; minutes: number }
type SceneSummary = { scene: string; sessions: number }
type TransferWord = { child_id: number; word: string; scene_count: number }
type ChildPlan = { id: number; nickname: string; plan: { week_start: string; practiced_days: number; target_days: number; remaining_days: number; target_minutes: number; sessions: number; completed_sessions: number; suggested_scene: string; suggested_word: string; mastery: { understood: number; spoken: number; used_in_sentence: number; total: number } } }

const settings = reactive<Settings>({
  minutes_per_session: 20,
  days_per_week: 5,
  provider: 'qwen',
  chat_model: 'qwen-flash',
  allow_ai: false,
  enable_scoring: false,
  max_point_reward: 0,
  max_coin_reward: 0,
  token_mode: 'frugal',
  allow_voice_input: false,
  speech_rate_percent: 85,
  voice_provider: 'qwen',
  asr_model: 'qwen3-asr-flash',
  voice_available: false,
  tts_provider: 'auto',
  tts_voice_en: 'Jennifer',
  models: { chat: 'qwen-flash', asr: 'qwen3-asr-flash', tts: 'qwen3-tts-flash-realtime' },
})
const sessions = ref<Session[]>([])
const words = ref<Word[]>([])
const tasks = ref<Task[]>([])
const days = ref<Day[]>([])
const sceneSummary = ref<SceneSummary[]>([])
const transferWords = ref<TransferWord[]>([])
const children = ref<ChildPlan[]>([])
const error = ref('')
const saving = ref(false)

async function load() {
  try {
    const data = await api.get<{ settings: Settings; sessions: Session[]; vocabulary: Word[]; tasks: Task[]; last_7_days: Day[]; scene_summary: SceneSummary[]; transfer_words: TransferWord[]; children: ChildPlan[] }>('/parent/english-beta')
    Object.assign(settings, data.settings)
    sessions.value = data.sessions
    words.value = data.vocabulary
    tasks.value = data.tasks
    days.value = data.last_7_days
    sceneSummary.value = data.scene_summary
    transferWords.value = data.transfer_words
    children.value = data.children
  } catch (e) { error.value = String(e) }
}
onMounted(load)

async function save() {
  saving.value = true
  error.value = ''
  try {
    Object.assign(settings, await api.put('/parent/english-beta/settings', {
      minutes_per_session: settings.minutes_per_session,
      days_per_week: settings.days_per_week,
      provider: settings.provider,
      chat_model: settings.chat_model,
      allow_ai: settings.allow_ai,
      enable_scoring: settings.enable_scoring,
      max_point_reward: settings.max_point_reward,
      max_coin_reward: settings.max_coin_reward,
      token_mode: settings.token_mode,
      allow_voice_input: settings.allow_voice_input,
      speech_rate_percent: settings.speech_rate_percent,
      voice_provider: settings.voice_provider,
      asr_model: settings.asr_model,
    }))
    ElMessage.success('已保存 / Saved')
  } catch (e) { error.value = String(e) }
  finally { saving.value = false }
}
</script>
<template>
  <main class="beta">
    <h1>英语星球 Beta</h1>
    <p>独立试运行 · English Planet Beta</p>
    <p v-if="error" role="alert">{{ error }}</p>

    <section>
      <h2>练习设置 / Practice settings</h2>
      <label>每次分钟 / Minutes per session <input v-model.number="settings.minutes_per_session" type="number" min="5" max="60"></label>
      <label>每周天数 / Days per week <input v-model.number="settings.days_per_week" type="number" min="1" max="7"></label>
    </section>

    <section>
      <h2>AI 评分与奖励 / AI score and rewards</h2>
      <label><input v-model="settings.enable_scoring" type="checkbox">结束练习时用 Qwen 评分</label>
      <label>满分积分 <input v-model.number="settings.max_point_reward" type="number" min="0" max="100"></label>
      <label>满分金币 <input v-model.number="settings.max_coin_reward" type="number" min="0" max="100"></label>
      <p>实际奖励 = 满分奖励 × 得分 ÷ 100，四舍五入到整数。例如满分各 10、得分 90，则各得 9。评分需要已配置 Qwen 密钥；无法评分时不发奖。</p>
    </section>

    <section>
      <h2>要在百炼开通的模型 / Models to enable</h2>
      <p>打开 <a href="https://bailian.console.aliyun.com/" target="_blank" rel="noopener">阿里云百炼控制台</a> → 模型广场，搜索并开通以下三个（北京地域与现有 API Key 一致）：</p>
      <ol>
        <li><code>{{ settings.models?.chat || settings.chat_model || 'qwen-flash' }}</code> — 伙伴文字对话（推荐；勿再搜已下线的 qwen-turbo）</li>
        <li><code>{{ settings.models?.asr || settings.asr_model || 'qwen3-asr-flash' }}</code> — 孩子录音转写 / 发音识别（仅点「说话」时调用）</li>
        <li><code>{{ settings.models?.tts || 'qwen3-tts-flash-realtime' }}</code> — 伙伴英语朗读（Jennifer；有缓存则不重复合成）</li>
      </ol>
      <p>对话用 <code>qwen-flash</code>。朗读用 <code>qwen3-tts-flash-realtime</code> + Jennifer（与离线 <code>qwen3-tts-flash</code> 额度不互通）。同一 API Key 即可。</p>
    </section>

    <section>
      <h2>省 Token 模式 / Token mode</h2>
      <label>用量模式
        <select v-model="settings.token_mode">
          <option value="frugal">省用量 frugal（推荐）</option>
          <option value="full">完整 AI full</option>
        </select>
      </label>
      <ul>
        <li><strong>frugal</strong>：听辨/点餐/结账等剧本回合不调对话模型；开放闲聊才用 {{ settings.chat_model }}，且只带最近 2 轮、max_tokens≈48。TTS 只预热下一题与问候。</li>
        <li><strong>full</strong>：每次伙伴回复都可走 AI，历史 6 轮、max_tokens≈80。</li>
      </ul>
    </section>

    <section>
      <h2>伙伴对话模型 / Chat partner model</h2>
      <p>控制文字伙伴回复所用的服务商与模型，与语音转写相互独立。</p>
      <label><input v-model="settings.allow_ai" type="checkbox">允许调用 AI 对话 / Allow AI chat</label>
      <label>对话服务商 / Chat provider
        <select v-model="settings.provider">
          <option value="qwen">Qwen</option>
          <option value="doubao">豆包（模拟模式）</option>
          <option value="mock">模拟模式 / Mock</option>
        </select>
      </label>
      <label>对话模型 / Chat model
        <input v-model="settings.chat_model" type="text" maxlength="128" placeholder="qwen-flash" list="beta-chat-models">
        <datalist id="beta-chat-models">
          <option value="qwen-flash"></option>
          <option value="qwen3.8-flash"></option>
          <option value="qwen3.7-flash"></option>
          <option value="qwen-plus"></option>
        </datalist>
      </label>
      <p>推荐 <code>qwen-flash</code>（省、快）。需要更新一代可选 <code>qwen3.8-flash</code>。没有密钥或关闭 AI 时用模拟伙伴。</p>
    </section>

    <section>
      <h2>伙伴英文语速 / Partner speaking speed</h2>
      <label>伙伴英文语速 / Partner speaking speed
        <select v-model.number="settings.speech_rate_percent">
          <option :value="70">很慢 · 70%</option>
          <option :value="80">较慢 · 80%</option>
          <option :value="85">当前速度 · 85%</option>
          <option :value="90">稍快 · 90%</option>
          <option :value="100">正常 · 100%</option>
          <option :value="110">较快 · 110%</option>
        </select>
      </label>
      <p>只影响英语星球 Beta 的英文朗读，中文释义保持原来的语速。保存后孩子重新进入页面生效。</p>
    </section>

    <section>
      <h2>语音转写 / 发音识别模型 / Speech recognition model</h2>
      <p>控制孩子录音转写所用的服务商与模型，不依赖上方对话模型开关。</p>
      <label><input v-model="settings.allow_voice_input" type="checkbox">允许语音伙伴 / Allow voice partner</label>
      <label>语音服务商 / Voice provider
        <select v-model="settings.voice_provider">
          <option value="qwen">Qwen ASR</option>
          <option value="doubao">豆包（预留）</option>
          <option value="mock">关闭云端转写 / Off</option>
        </select>
      </label>
      <label>转写模型 / ASR model
        <input v-model="settings.asr_model" type="text" maxlength="128" placeholder="qwen3-asr-flash" list="beta-asr-models">
        <datalist id="beta-asr-models">
          <option value="qwen3-asr-flash"></option>
          <option value="qwen3-asr-flash-filetrans"></option>
        </datalist>
      </label>
      <p>
        录音经 Growth Planet 服务器发送到所选转写模型，不保存录音；孩子确认后发送的文字会写入会话。
        当前语音可用：{{ settings.voice_available ? '是 / Yes' : '否（需开启语音、选择 Qwen，并配置家长 AI 中的 Qwen 密钥）' }}
      </p>
    </section>

    <section>
      <h2>伙伴发音朗读 / Partner read-aloud</h2>
      <p>
        儿童页播放伙伴英语回复时，沿用家庭「朗读语音」设置（当前引擎：{{ settings.tts_provider || 'auto' }}，英语音色：{{ settings.tts_voice_en || 'Jennifer' }}）。
        请到家长 AI / 朗读设置中单独调整，不与上方对话或转写模型混用。
      </p>
    </section>

    <section>
      <button :disabled="saving" @click="save">保存 / Save</button>
    </section>

    <section>
      <h2>近七天 / Last 7 days</h2>
      <p v-if="!days.length">暂无练习记录 / No practice yet</p>
      <p v-for="day in days" :key="day.date">{{ day.date }} · {{ day.completed }}/{{ day.sessions }} 次完成 · 约 {{ day.minutes }} 分钟</p>
    </section>
    <section>
      <h2>本周练习计划 / Weekly practice plan</h2>
      <p>按家庭时区从周一计算；孩子完成听辨或发送表达后计为练习一天。目标可在上方调整。</p>
      <p v-if="!children.length">暂无儿童资料 / No child profiles</p>
      <div v-for="child in children" :key="child.id" class="plan">
        <h3>{{ child.nickname }}</h3>
        <p>已练习 {{ child.plan.practiced_days }}/{{ child.plan.target_days }} 天 · 还需 {{ child.plan.remaining_days }} 天 · 建议每次 {{ child.plan.target_minutes }} 分钟</p>
        <p>本周会话 {{ child.plan.sessions }} 次，结束 {{ child.plan.completed_sessions }} 次。词汇 {{ child.plan.mastery.total }} 个：听懂 {{ child.plan.mastery.understood }}、主动说出 {{ child.plan.mastery.spoken }}、句中使用 {{ child.plan.mastery.used_in_sentence }}。</p>
        <p>下次建议：{{ child.plan.suggested_scene === 'restaurant' ? '餐厅星球' : '超市星球' }}，练习 {{ child.plan.suggested_word }}。</p>
      </div>
    </section>
    <section>
      <h2>场景与迁移 / Scenes and transfer</h2>
      <p v-for="scene in sceneSummary" :key="scene.scene">{{ scene.scene === 'restaurant' ? '餐厅星球' : '超市星球' }} · {{ scene.sessions }} 次会话</p>
      <p v-if="!transferWords.length">尚无跨场景练习词。孩子在两个场景实际使用或听辨同一个词后，会显示在这里。</p>
      <p v-for="item in transferWords" :key="`${item.child_id}-${item.word}`">孩子 #{{ item.child_id }} · {{ item.word }} · {{ item.scene_count }} 个场景</p>
    </section>
    <section>
      <h2>基础报告 / Basic report</h2>
      <p>最近会话 {{ sessions.length }} · 任务 {{ tasks.length }} · 词汇 {{ words.length }}</p>
      <p v-for="s in sessions" :key="s.id">孩子 #{{ s.child_id }} · {{ s.scene === 'restaurant' ? '餐厅星球' : '超市星球' }} · {{ s.started_at }} · {{ s.ended_at ? '已完成' : '进行中' }}<template v-if="s.score !== null"> · AI {{ s.score }} 分 · +{{ s.point_reward }} 积分 · +{{ s.coin_reward }} 金币 · {{ s.score_feedback }}</template><template v-else-if="s.score_status === 'unavailable'"> · 评分暂不可用，未发奖</template></p>
      <h3>词汇掌握</h3>
      <p v-for="w in words" :key="`${w.child_id}-${w.word}`">#{{ w.child_id }} {{ w.word }} · 听懂 {{ w.understood ? '✓' : '○' }} · 主动说出 {{ w.spoken ? '✓' : '○' }} · 句中使用 {{ w.used_in_sentence ? '✓' : '○' }}</p>
    </section>
  </main>
</template>
<style scoped>
.beta { max-width: 820px; margin: auto; padding: 24px }
section { background: white; border-radius: 12px; padding: 20px; margin: 18px 0 }
.plan { padding: 12px; margin: 12px 0; border-radius: 10px; background: #f4f7fa }
.plan h3 { margin: 0 0 8px }
label { display: block; margin: 12px 0 }
input, select { margin-left: 10px; padding: 6px }
button { padding: 8px 18px }
</style>
