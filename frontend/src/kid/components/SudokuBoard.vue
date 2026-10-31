<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/shared/api/client'
import { playDrop, playMiss, playWin } from '@/shared/sfx'
import {
  GAME_LEVEL_REWARDS,
  type GameLevelBoard,
  type GameLevelClaim,
  type GamePlayStart,
} from '../gameRewards'
import {
  SUDOKU_LEVELS,
  createSudokuPuzzle,
  isComplete,
  remainingCount,
  type SudokuLevelId,
  type SudokuPuzzle,
} from '../sudoku'

const props = withDefaults(defineProps<{
  animate?: boolean
  sounds?: boolean
}>(), {
  animate: true,
  sounds: false,
})

const { t } = useI18n()

const levelLabels = {
  easy: 'kid.games.easy',
  practice: 'kid.games.practice',
  challenge: 'kid.games.challenge',
} as const

const levelId = ref<SudokuLevelId>('easy')
const puzzle = ref<SudokuPuzzle>(createSudokuPuzzle('easy'))
const placed = ref<Array<number | null>>([])
const selected = ref(1)
const missIndex = ref<number | null>(null)
const hitIndex = ref<number | null>(null)
const phase = ref<'ready' | 'play' | 'done'>('ready')
const starting = ref(false)
const playId = ref<string | null>(null)
const error = ref('')
const board = ref<GameLevelBoard | null>(null)
const awardedCoins = ref(0)

let refreshTimer: ReturnType<typeof setInterval> | undefined
let missTimer: ReturnType<typeof setTimeout> | undefined
let hitTimer: ReturnType<typeof setTimeout> | undefined

const remaining = computed(() => remainingCount(puzzle.value, placed.value))
const digits = computed(() => Array.from({ length: puzzle.value.size }, (_, i) => i + 1))
const levelAwarded = computed(() => board.value?.levels.find((row) => row.id === levelId.value)?.awarded ?? false)

function levelCoins(id: SudokuLevelId): number {
  return board.value?.levels.find((row) => row.id === id)?.coins ?? GAME_LEVEL_REWARDS[id]
}

function valueAt(index: number): number | null {
  const clue = puzzle.value.start[index] ?? 0
  if (clue !== 0) return clue
  return placed.value[index] ?? null
}

function isGiven(index: number): boolean {
  return (puzzle.value.start[index] ?? 0) !== 0
}

function thickRight(col: number): boolean {
  return (col + 1) % puzzle.value.boxCols === 0 && col + 1 < puzzle.value.size
}

function thickBottom(row: number): boolean {
  return (row + 1) % puzzle.value.boxRows === 0 && row + 1 < puzzle.value.size
}

async function loadBoard(): Promise<void> {
  try {
    board.value = await api.get<GameLevelBoard>('/kid/games/sudoku')
  } catch {
    board.value = null
  }
}

async function claimReward(): Promise<void> {
  awardedCoins.value = 0
  try {
    const result = await api.postIdempotent<GameLevelClaim>('/kid/games/sudoku', { level: levelId.value, play_id: playId.value })
    board.value = result.board
    awardedCoins.value = result.awarded_coins
  } catch {
    // The round still counts on this device. Points need the server.
  }
}

async function begin(level: SudokuLevelId = levelId.value): Promise<void> {
  if (starting.value || phase.value === 'play') return
  starting.value = true
  error.value = ''
  try {
    const result = await api.postIdempotent<GamePlayStart>('/kid/games/sudoku/start', { level })
    playId.value = result.play_id
    await loadBoard()
    levelId.value = level
    const next = createSudokuPuzzle(level)
    puzzle.value = next
    placed.value = next.start.map(() => null)
    selected.value = 1
    missIndex.value = null
    hitIndex.value = null
    awardedCoins.value = 0
    phase.value = 'play'
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('kid.games.tryLater')
    await loadBoard()
  } finally {
    starting.value = false
  }
}

function pickLevel(id: SudokuLevelId): void {
  if (phase.value === 'play' || starting.value) return
  levelId.value = id
  error.value = ''
  phase.value = 'ready'
}

function tap(index: number): void {
  if (phase.value === 'done' || isGiven(index) || placed.value[index] != null) return
  if (selected.value !== puzzle.value.solution[index]) {
    missIndex.value = index
    if (props.sounds) playMiss()
    if (missTimer !== undefined) clearTimeout(missTimer)
    missTimer = setTimeout(() => {
      if (missIndex.value === index) missIndex.value = null
    }, 280)
    return
  }
  const next = placed.value.slice()
  next[index] = selected.value
  placed.value = next
  missIndex.value = null
  hitIndex.value = index
  if (hitTimer !== undefined) clearTimeout(hitTimer)
  hitTimer = setTimeout(() => {
    if (hitIndex.value === index) hitIndex.value = null
  }, 280)
  if (props.sounds) playDrop()
  if (isComplete(puzzle.value, next)) {
    phase.value = 'done'
    if (props.sounds) playWin()
    void claimReward()
  }
}

onMounted(() => {
  void loadBoard()
  refreshTimer = setInterval(() => { if (phase.value !== 'play') void loadBoard() }, 60_000)
})

onUnmounted(() => {
  if (refreshTimer !== undefined) clearInterval(refreshTimer)
  if (missTimer !== undefined) clearTimeout(missTimer)
  if (hitTimer !== undefined) clearTimeout(hitTimer)
})
</script>

<template>
  <section
    class="sudoku"
    :style="{
      '--size': puzzle.size,
      '--box-rows': puzzle.boxRows,
      '--box-cols': puzzle.boxCols,
    }"
  >
    <div class="levels" role="group" :aria-label="t('kid.games.pickSize')">
      <button
        v-for="level in SUDOKU_LEVELS"
        :key="level.id"
        type="button"
        class="level"
        :class="{ on: levelId === level.id }"
        :aria-pressed="levelId === level.id"
        :disabled="phase === 'play' || starting"
        @click="pickLevel(level.id)"
      >
        <span class="level-n">{{ level.size }}×{{ level.size }}</span>
        <span class="level-label">{{ t(levelLabels[level.id]) }}</span>
        <span class="level-coins">{{ t('kid.games.levelReward', { coins: levelCoins(level.id) }) }}</span>
        <span class="level-coins">{{ t('kid.games.remaining', { n: board?.levels.find((row) => row.id === level.id)?.remaining ?? 0 }) }}</span>
      </button>
    </div>

    <p v-if="error" class="status" role="alert">{{ error }}</p>
    <p v-if="board && phase !== 'play' && (board?.levels.find((row) => row.id === levelId)?.remaining ?? 0) === 0" class="status">{{ t('kid.games.tomorrow') }}</p>
    <button v-if="phase !== 'play'" type="button" class="again" :disabled="starting || !board || (board.levels.find((row) => row.id === levelId)?.remaining ?? 0) === 0" @click="begin()">{{ t(phase === 'done' ? 'kid.games.sudokuAgain' : 'kid.games.start') }}</button>
    <p class="status" aria-live="polite">
      <template v-if="phase === 'done'">
        <span>{{ t('kid.games.sudokuDone') }}</span>
        <span v-if="awardedCoins > 0"> · {{ t('kid.games.awarded', { coins: awardedCoins }) }}</span>
        <span v-else-if="levelAwarded"> · {{ t('kid.games.already') }}</span>
      </template>
      <template v-else>
        {{ t('kid.games.sudokuHow') }} · {{ t('kid.games.sudokuLeft', { n: remaining }) }}
      </template>
    </p>

    <div v-if="phase === 'play' || phase === 'done'" class="stage">
      <div class="board" role="grid" :aria-label="t('kid.games.sudoku')">
        <button
          v-for="(_, index) in puzzle.solution"
          :key="`${levelId}-${index}`"
          type="button"
          class="cell"
          :class="{
            given: isGiven(index),
            filled: !isGiven(index) && placed[index] != null,
            miss: missIndex === index,
            hit: hitIndex === index,
            thickR: thickRight(index % puzzle.size),
            thickB: thickBottom(Math.floor(index / puzzle.size)),
            animate,
          }"
          :disabled="phase !== 'play' || isGiven(index) || placed[index] != null"
          :aria-label="valueAt(index) != null
            ? t('kid.games.sudokuCell', { n: valueAt(index) })
            : t('kid.games.sudokuEmpty')"
          @click="tap(index)"
        >
          {{ valueAt(index) ?? '' }}
        </button>
      </div>

      <div class="side">
        <div class="pad" role="group" :aria-label="t('kid.games.sudokuPick')">
          <button
            v-for="digit in digits"
            :key="digit"
            type="button"
            class="digit"
            :class="{ on: selected === digit }"
            :aria-pressed="selected === digit"
            :aria-label="t('kid.games.sudokuDigit', { n: digit })"
            @click="selected = digit"
          >
            {{ digit }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.sudoku {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.levels {
  display: flex;
  gap: 8px;
}
.level {
  flex: 1;
  min-height: 56px;
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
.level.on {
  background: #fff;
  color: var(--gp-space-dark);
}
.level-n {
  font-size: 16px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}
.level-label {
  font-size: 13px;
  opacity: 0.8;
}
.level-coins {
  font-size: 12px;
  font-weight: 700;
  opacity: 0.85;
  color: var(--gp-sun);
}
.level.on .level-coins {
  color: #c46b12;
}
.status {
  margin: 0;
  text-align: center;
  font-size: 17px;
  font-weight: 700;
  line-height: 1.4;
}
.stage {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}
.board {
  display: grid;
  grid-template-columns: repeat(var(--size), minmax(0, 1fr));
  gap: 4px;
  width: min(100%, 420px);
  padding: 8px;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.08);
}
.cell {
  aspect-ratio: 1;
  min-width: 0;
  min-height: 56px;
  border: none;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  font-size: clamp(20px, 7vw, 32px);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.28);
}
.cell.thickR {
  box-shadow:
    inset 0 0 0 2px rgba(255, 255, 255, 0.28),
    3px 0 0 0 rgba(255, 224, 138, 0.95);
}
.cell.thickB {
  box-shadow:
    inset 0 0 0 2px rgba(255, 255, 255, 0.28),
    0 3px 0 0 rgba(255, 224, 138, 0.95);
}
.cell.thickR.thickB {
  box-shadow:
    inset 0 0 0 2px rgba(255, 255, 255, 0.28),
    3px 0 0 0 rgba(255, 224, 138, 0.95),
    0 3px 0 0 rgba(255, 224, 138, 0.95);
}
.cell.given {
  background: rgba(255, 255, 255, 0.22);
  color: #ffe08a;
  cursor: default;
}
.cell.filled {
  background: #ffe08a;
  color: #1b1f4b;
  box-shadow: inset 0 -4px 0 rgba(0, 0, 0, 0.12);
  cursor: default;
}
.cell.hit {
  box-shadow: 0 0 0 4px #ffb547, 0 8px 18px rgba(255, 181, 71, 0.45);
  z-index: 1;
}
.cell.hit.animate {
  animation: pop 0.22s ease;
}
.cell.miss {
  background: #ffb4b4;
  color: #1b1f4b;
}
.cell.miss.animate {
  animation: shake 0.28s ease;
}
.side {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: 100%;
}
.pad {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  max-width: 420px;
}
.digit {
  width: 56px;
  height: 56px;
  border: none;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 24px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  touch-action: manipulation;
}
.digit.on {
  background: #fff;
  color: var(--gp-space-dark);
  box-shadow: 0 0 0 4px var(--gp-sun);
}
.again {
  width: min(100%, 320px);
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
.level:focus-visible,
.digit:focus-visible,
.cell:focus-visible,
.again:focus-visible {
  outline: 3px solid var(--gp-sun);
  outline-offset: 2px;
}
@media (min-width: 768px) and (min-height: 600px) {
  .level {
    min-height: 68px;
  }
  .level-n {
    font-size: 18px;
  }
  .status {
    font-size: 20px;
  }
  .board {
    width: min(100%, 520px);
    gap: 6px;
    padding: 12px;
  }
  .cell {
    min-height: 72px;
    border-radius: 16px;
    font-size: clamp(28px, 3.2vw, 40px);
  }
  .digit {
    width: 72px;
    height: 72px;
    font-size: 28px;
  }
  .stage {
    width: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 220px;
    align-items: center;
    justify-items: center;
  }
  .pad {
    flex-direction: column;
    max-width: none;
  }
  .side {
    width: auto;
  }
}
@media (orientation: landscape) and (max-height: 520px) {
  .sudoku {
    display: grid;
    width: max-content;
    max-width: 100%;
    margin: 0 auto;
    grid-template-columns: auto auto auto;
    grid-template-areas:
      'status status status'
      'levels board pad';
    column-gap: 10px;
    row-gap: 2px;
    align-items: center;
  }
  .status {
    grid-area: status;
    font-size: 13px;
  }
  .levels {
    grid-area: levels;
    flex-direction: column;
    width: max-content;
  }
  .level {
    flex: none;
    width: 72px;
    min-height: 48px;
  }
  .stage {
    display: contents;
  }
  .board {
    --cell: min(56px, calc((100dvh - 96px) / var(--size)));
    grid-area: board;
    width: max-content;
    gap: 3px;
    padding: 4px;
    grid-template-columns: repeat(var(--size), var(--cell));
  }
  .cell {
    width: var(--cell);
    height: var(--cell);
    min-height: 0;
    aspect-ratio: auto;
    border-radius: 10px;
    font-size: calc(var(--cell) * 0.48);
  }
  .side {
    grid-area: pad;
    width: auto;
  }
  .pad {
    display: grid;
    grid-template-columns: repeat(2, 52px);
    gap: 6px;
    max-width: none;
  }
  .digit {
    width: 52px;
    height: 52px;
    font-size: 20px;
  }
  .again {
    grid-column: 1 / -1;
    width: 100%;
    min-height: 44px;
    font-size: 15px;
    padding: 6px;
  }
}
@keyframes pop {
  0% { transform: scale(0.92); }
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
