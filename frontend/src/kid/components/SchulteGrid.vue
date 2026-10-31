<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/shared/api/client'
import { playDrop, playMiss, playWin } from '@/shared/sfx'
import {
  formatSchulteTime,
  readBest,
  rememberBest,
  SCHULTE_REWARDS,
  shuffleNumbers,
  type SchulteBoard,
  type SchulteFinish,
  type SchulteSize,
} from '../schulte'

const props = withDefaults(defineProps<{
  animate?: boolean
  sounds?: boolean
  childId?: number | null
}>(), {
  animate: true,
  sounds: false,
  childId: null,
})

const { t } = useI18n()

const sizes: { size: SchulteSize; label: string }[] = [
  { size: 3, label: 'kid.games.easy' },
  { size: 4, label: 'kid.games.practice' },
  { size: 5, label: 'kid.games.challenge' },
]

const size = ref<SchulteSize>(3)
const phase = ref<'ready' | 'play' | 'done'>('ready')
const starting = ref(false)
const playId = ref<string | null>(null)
const error = ref('')
const round = ref(0)
const cells = ref<number[]>([])
const next = ref(1)
const found = ref<Set<number>>(new Set())
const missIndex = ref<number | null>(null)
const hitIndex = ref<number | null>(null)
const startedAt = ref(0)
const elapsed = ref(0)
const finalMs = ref(0)
const bestMs = ref<number | null>(null)
const improved = ref(false)
const awardedCoins = ref(0)
const board = ref<SchulteBoard | null>(null)

let timer: ReturnType<typeof setInterval> | undefined
let refreshTimer: ReturnType<typeof setInterval> | undefined
let missTimer: ReturnType<typeof setTimeout> | undefined
let hitTimer: ReturnType<typeof setTimeout> | undefined

const total = computed(() => size.value * size.value)
const foundCount = computed(() => found.value.size)
const sizeBoard = computed(() => board.value?.sizes.find((row) => row.size === size.value) ?? null)
const goal = computed(() => {
  const row = sizeBoard.value
  if (row) return { limitMs: row.limit_ms, coins: row.coins }
  return SCHULTE_REWARDS[size.value]
})
const goalSeconds = computed(() => Math.round(goal.value.limitMs / 1000))
const ranks = computed(() => sizeBoard.value?.ranks ?? [])
const sizeAwarded = computed(() => sizeBoard.value?.awarded ?? false)

function refreshBest(): void {
  if (board.value) {
    bestMs.value = sizeBoard.value?.best_ms ?? null
    return
  }
  bestMs.value = readBest(props.childId, size.value)
}

async function loadBoard(): Promise<void> {
  try {
    board.value = await api.get<SchulteBoard>('/kid/games/schulte')
  } catch {
    board.value = null
  }
}

watch(() => [props.childId, size.value, board.value] as const, refreshBest, { immediate: true })

onMounted(() => {
  void loadBoard()
  refreshTimer = setInterval(() => { if (phase.value !== 'play') void loadBoard() }, 60_000)
})

function stopTimer(): void {
  if (timer !== undefined) {
    clearInterval(timer)
    timer = undefined
  }
}

function pick(nextSize: SchulteSize): void {
  if (phase.value === 'play' || size.value === nextSize) return
  size.value = nextSize
  if (phase.value === 'done') phase.value = 'ready'
}

async function begin(): Promise<void> {
  if (phase.value === 'play' || starting.value || !board.value || (sizeBoard.value?.remaining ?? 0) === 0) return
  starting.value = true
  error.value = ''
  try {
    const result = await api.postIdempotent<{ play_id: string }>('/kid/games/schulte/start', { level: String(size.value) })
    playId.value = result.play_id
    await loadBoard()
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('kid.games.tryLater')
    await loadBoard()
    starting.value = false
    return
  }
  starting.value = false
  stopTimer()
  round.value += 1
  cells.value = shuffleNumbers(size.value)
  next.value = 1
  found.value = new Set()
  missIndex.value = null
  improved.value = false
  awardedCoins.value = 0
  finalMs.value = 0
  startedAt.value = Date.now()
  elapsed.value = 0
  phase.value = 'play'
  timer = setInterval(() => {
    elapsed.value = Date.now() - startedAt.value
  }, 100)
}

async function finish(): Promise<void> {
  stopTimer()
  const ms = Date.now() - startedAt.value
  finalMs.value = ms
  elapsed.value = ms
  const local = rememberBest(props.childId, size.value, ms)
  bestMs.value = local.best
  improved.value = local.improved
  awardedCoins.value = 0
  phase.value = 'done'
  if (props.sounds) playWin()
  try {
    const result = await api.postIdempotent<SchulteFinish>('/kid/games/schulte', {
      size: size.value,
      elapsed_ms: ms,
      play_id: playId.value,
    })
    board.value = result.board
    improved.value = result.improved
    awardedCoins.value = result.awarded_coins
    const mine = result.board.sizes.find((row) => row.size === size.value)
    if (mine?.best_ms != null) bestMs.value = mine.best_ms
  } catch {
    // The round still counts on this device. Points need the server.
  }
}

function tap(index: number): void {
  if (phase.value !== 'play') return
  const value = cells.value[index]
  if (value === undefined || found.value.has(value)) return
  if (value !== next.value) {
    missIndex.value = index
    if (props.sounds) playMiss()
    if (missTimer !== undefined) clearTimeout(missTimer)
    missTimer = setTimeout(() => {
      if (missIndex.value === index) missIndex.value = null
    }, 320)
    return
  }
  if (props.sounds) playDrop()
  const nextFound = new Set(found.value)
  nextFound.add(value)
  found.value = nextFound
  missIndex.value = null
  hitIndex.value = index
  if (hitTimer !== undefined) clearTimeout(hitTimer)
  hitTimer = setTimeout(() => {
    if (hitIndex.value === index) hitIndex.value = null
  }, 280)
  if (value === total.value) {
    void finish()
    return
  }
  next.value = value + 1
}

onUnmounted(() => {
  if (refreshTimer !== undefined) clearInterval(refreshTimer)
  stopTimer()
  if (missTimer !== undefined) clearTimeout(missTimer)
  if (hitTimer !== undefined) clearTimeout(hitTimer)
})
</script>

<template>
  <section class="schulte">
    <div class="play">
      <div class="sizes" role="group" :aria-label="t('kid.games.pickSize')">
        <button
          v-for="option in sizes"
          :key="option.size"
          type="button"
          class="size"
          :class="{ on: size === option.size }"
          :disabled="phase === 'play'"
          :aria-pressed="size === option.size"
          @click="pick(option.size)"
        >
          <span class="size-n">{{ option.size }}×{{ option.size }}</span>
          <span class="size-label">{{ t(option.label) }}</span>
          <span class="size-label">{{ t('kid.games.remaining', { n: board?.sizes.find((row) => row.size === option.size)?.remaining ?? 0 }) }}</span>
        </button>
      </div>

      <div class="status">
        <div aria-live="polite">
          <template v-if="phase === 'play'">
            <p class="next-label">{{ t('kid.games.next') }}</p>
            <p class="next-num">{{ next }}</p>
          </template>
          <template v-else-if="phase === 'done'">
            <p class="done">{{ t('kid.games.done') }}</p>
            <p class="next-num time">{{ formatSchulteTime(finalMs) }}</p>
            <p v-if="awardedCoins > 0" class="record">{{ t('kid.games.awarded', { coins: awardedCoins }) }}</p>
            <p v-else-if="sizeAwarded" class="meta">{{ t('kid.games.already') }}</p>
            <p v-else-if="finalMs > goal.limitMs" class="meta">{{ t('kid.games.faster', { seconds: goalSeconds }) }}</p>
            <p v-if="improved" class="record">{{ t('kid.games.record') }}</p>
          <p v-if="bestMs != null" class="meta">{{ t(board ? 'kid.games.todayBest' : 'kid.games.best', { time: formatSchulteTime(bestMs) }) }}</p>
        </template>
        <template v-else>
          <p class="how">{{ t('kid.games.how', { last: total }) }}</p>
          <p v-if="bestMs != null" class="meta">{{ t(board ? 'kid.games.todayBest' : 'kid.games.best', { time: formatSchulteTime(bestMs) }) }}</p>
        </template>
        </div>
        <p v-if="phase === 'play'" class="meta">
          <span>{{ foundCount }} / {{ total }}</span>
          <span>{{ t('kid.games.elapsed', { time: formatSchulteTime(elapsed) }) }}</span>
        </p>
      </div>

      <div
        v-if="phase !== 'ready'"
        class="grid"
        :style="{ gridTemplateColumns: `repeat(${size}, minmax(0, 1fr))` }"
        role="group"
        :aria-label="t('kid.games.schulte')"
      >
        <button
          v-for="(n, index) in cells"
          :key="`${round}-${index}`"
          type="button"
          class="cell"
          :class="{ found: found.has(n), hit: hitIndex === index, miss: missIndex === index && !found.has(n), animate }"
          :disabled="phase !== 'play' || found.has(n)"
          :aria-label="found.has(n) ? t('kid.games.found', { n }) : t('kid.games.cell', { n })"
          @click="tap(index)"
        >
          {{ n }}
        </button>
      </div>

      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="phase !== 'play' && (sizeBoard?.remaining ?? 0) === 0">{{ t('kid.games.tomorrow') }}</p>
      <button v-if="phase !== 'play'" type="button" class="go" :disabled="starting || !board || (sizeBoard?.remaining ?? 0) === 0" @click="begin">
        {{ phase === 'done' ? t('kid.games.again') : t('kid.games.start') }}
      </button>
    </div>

    <aside class="board" :aria-label="t('kid.games.board')">
      <h2>{{ t('kid.games.board') }}</h2>
      <p class="goal">{{ t('kid.games.goal', { seconds: goalSeconds, coins: goal.coins }) }}</p>
      <ol v-if="ranks.length">
        <li v-for="(row, index) in ranks" :key="row.child_id" :class="{ me: row.child_id === childId }">
          <span class="place">{{ index + 1 }}</span>
          <span class="who">{{ row.nickname }}</span>
          <span class="time">{{ formatSchulteTime(row.best_ms) }}</span>
          <span v-if="row.coins > 0" class="coins">+{{ row.coins }}</span>
        </li>
      </ol>
      <p v-else class="empty">{{ t('kid.games.boardEmpty') }}</p>
    </aside>
  </section>
</template>

<style scoped>
.schulte {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 16px;
}
.play {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.sizes {
  display: flex;
  gap: 8px;
}
.size {
  flex: 1;
  min-height: 64px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  border: none;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  cursor: pointer;
  touch-action: manipulation;
}
.size.on {
  background: #fff;
  color: var(--gp-space-dark);
}
.size:disabled {
  cursor: default;
  opacity: 0.55;
}
.size-n {
  font-size: 18px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}
.size-label {
  font-size: 13px;
  opacity: 0.75;
}
.status {
  min-height: 128px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  margin: 8px 0 12px;
}
.next-label,
.how,
.done,
.meta,
.record {
  margin: 0;
}
.next-label,
.done {
  font-size: 18px;
  font-weight: 700;
}
.how {
  font-size: 18px;
  line-height: 1.4;
  max-width: 18em;
}
.next-num {
  margin: 4px 0 0;
  font-size: 64px;
  font-weight: 800;
  line-height: 1;
  color: var(--gp-sun);
  font-variant-numeric: tabular-nums;
}
.record {
  margin-top: 6px;
  font-weight: 800;
  color: var(--gp-sun);
}
.meta {
  margin-top: 8px;
  display: flex;
  gap: 14px;
  justify-content: center;
  opacity: 0.85;
  font-variant-numeric: tabular-nums;
}
.grid {
  display: grid;
  gap: 8px;
  width: 100%;
}
.cell {
  position: relative;
  min-height: 56px;
  aspect-ratio: 1;
  border: none;
  border-radius: 16px;
  background: #fff;
  color: var(--gp-space-dark);
  font-size: clamp(20px, 5.5vw, 36px);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  touch-action: manipulation;
  user-select: none;
}
.cell:active:not(:disabled) {
  background: #fff4c8;
}
.cell:disabled {
  cursor: default;
}
.cell.found {
  background: #ffe08a;
  color: rgba(27, 31, 75, 0.45);
}
.cell.hit {
  background: #fff;
  color: #1b1f4b;
  box-shadow: 0 0 0 4px #ffb547, 0 8px 18px rgba(255, 181, 71, 0.45);
  z-index: 1;
}
.cell.hit.animate {
  animation: pop 0.22s ease;
}
.cell.miss {
  background: #ffb4b4;
}
.cell.miss.animate {
  animation: shake 0.28s ease;
}
.cell:focus-visible,
.size:focus-visible,
.go:focus-visible {
  outline: 3px solid var(--gp-sun);
  outline-offset: 2px;
}
.go {
  width: 100%;
  margin-top: 16px;
  min-height: 56px;
  border: none;
  border-radius: 16px;
  background: linear-gradient(135deg, #ffb547, #ff8a3d);
  color: #1b1f4b;
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;
  touch-action: manipulation;
}
.board {
  padding: 16px;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.1);
}
.board h2 {
  margin: 0;
  font-size: 18px;
}
.goal,
.empty {
  margin: 8px 0 0;
  opacity: 0.85;
}
.board ol {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.board li {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 8px 10px;
  border-radius: 12px;
}
.board li.me {
  background: rgba(255, 255, 255, 0.16);
}
.place {
  width: 1.4em;
  font-weight: 800;
}
.who {
  flex: 1;
  min-width: 0;
  font-weight: 700;
}
.time,
.coins {
  font-variant-numeric: tabular-nums;
  font-weight: 800;
}
.coins {
  color: var(--gp-sun);
}
@media (min-width: 768px) {
  .schulte {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 240px;
    gap: 20px;
    align-items: start;
  }
  .grid {
    gap: 12px;
  }
  .cell {
    min-height: 72px;
    border-radius: 22px;
    font-size: clamp(28px, 3.4vw, 44px);
  }
  .size {
    min-height: 76px;
  }
  .go {
    min-height: 64px;
    font-size: 22px;
  }
}
@keyframes pop {
  0% { transform: scale(0.94); }
  60% { transform: scale(1.06); }
  100% { transform: scale(1); }
}
@keyframes shake {
  0%,
  100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  75% { transform: translateX(4px); }
}
</style>
