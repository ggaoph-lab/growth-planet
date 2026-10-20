<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/shared/api/client'
import { playDrop, playMiss, playWin } from '@/shared/sfx'
import {
  GAME_LEVEL_REWARDS,
  type GameLevelBoard,
  type GameLevelClaim,
} from '../gameRewards'
import {
  MIRROR_COLORS,
  MIRROR_LEVELS,
  createMirrorPuzzle,
  expectedColor,
  matchedCount,
  type MirrorLevelId,
  type MirrorPuzzle,
} from '../mirror'

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

const levelId = ref<MirrorLevelId>('easy')
const puzzle = ref<MirrorPuzzle>(createMirrorPuzzle('easy'))
const placed = ref<Array<number | null>>([])
const selected = ref(0)
const missIndex = ref<number | null>(null)
const phase = ref<'play' | 'done'>('play')
const board = ref<GameLevelBoard | null>(null)
const awardedCoins = ref(0)

let missTimer: ReturnType<typeof setTimeout> | undefined

const remaining = computed(() => puzzle.value.left.length - matchedCount(puzzle.value, placed.value))
const levelAwarded = computed(() => board.value?.levels.find((row) => row.id === levelId.value)?.awarded ?? false)

function levelCoins(id: MirrorLevelId): number {
  return board.value?.levels.find((row) => row.id === id)?.coins ?? GAME_LEVEL_REWARDS[id]
}

function colorHex(index: number): string {
  return MIRROR_COLORS[index]?.hex ?? MIRROR_COLORS[0].hex
}

function colorLabel(index: number): string {
  const id = MIRROR_COLORS[index]?.id
  if (id === 'blue') return t('kid.games.colors.blue')
  if (id === 'green') return t('kid.games.colors.green')
  if (id === 'pink') return t('kid.games.colors.pink')
  return t('kid.games.colors.orange')
}

function indexAt(row: number, col: number): number {
  return (row - 1) * puzzle.value.cols + (col - 1)
}

function leftColor(row: number, col: number): number {
  return puzzle.value.left[indexAt(row, col)] ?? 0
}

async function loadBoard(): Promise<void> {
  try {
    board.value = await api.get<GameLevelBoard>('/kid/games/mirror')
  } catch {
    board.value = null
  }
}

async function claimReward(): Promise<void> {
  awardedCoins.value = 0
  try {
    const result = await api.postIdempotent<GameLevelClaim>('/kid/games/mirror', { level: levelId.value })
    board.value = result.board
    awardedCoins.value = result.awarded_coins
  } catch {
    // The collage still counts on this device. Points need the server.
  }
}

function begin(level: MirrorLevelId = levelId.value): void {
  levelId.value = level
  const next = createMirrorPuzzle(level)
  puzzle.value = next
  placed.value = next.left.map(() => null)
  selected.value = 0
  missIndex.value = null
  awardedCoins.value = 0
  phase.value = 'play'
}

function pickLevel(id: MirrorLevelId): void {
  if (id === levelId.value && phase.value === 'play') return
  begin(id)
}

function tap(index: number): void {
  if (phase.value === 'done' || placed.value[index] != null) return
  if (selected.value !== expectedColor(puzzle.value, index)) {
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
  if (props.sounds) playDrop()
  if (matchedCount(puzzle.value, next) === puzzle.value.left.length) {
    phase.value = 'done'
    if (props.sounds) playWin()
    void claimReward()
  }
}

begin()
onMounted(() => {
  void loadBoard()
})

onUnmounted(() => {
  if (missTimer !== undefined) clearTimeout(missTimer)
})
</script>

<template>
  <section class="mirror" :style="{ '--rows': puzzle.rows, '--cols': puzzle.cols }">
    <div class="levels" role="group" :aria-label="t('kid.games.pickSize')">
      <button
        v-for="level in MIRROR_LEVELS"
        :key="level.id"
        type="button"
        class="level"
        :class="{ on: levelId === level.id }"
        :aria-pressed="levelId === level.id"
        @click="pickLevel(level.id)"
      >
        <span class="level-label">{{ t(levelLabels[level.id]) }}</span>
        <span class="level-coins">{{ t('kid.games.levelReward', { coins: levelCoins(level.id) }) }}</span>
      </button>
    </div>

    <p class="status" aria-live="polite">
      <template v-if="phase === 'done'">
        <span>{{ t('kid.games.mirrorDone') }}</span>
        <span v-if="awardedCoins > 0"> · {{ t('kid.games.awarded', { coins: awardedCoins }) }}</span>
        <span v-else-if="levelAwarded"> · {{ t('kid.games.already') }}</span>
      </template>
      <template v-else>
        {{ t('kid.games.mirrorHow') }} · {{ t('kid.games.mirrorLeft', { n: remaining }) }}
      </template>
    </p>

    <div class="stage">
      <div class="board" role="group" :aria-label="t('kid.games.mirror')">
        <template v-for="row in puzzle.rows" :key="`row-${row}`">
          <div
            v-for="col in puzzle.cols"
            :key="`l-${row}-${col}`"
            class="tile source"
            role="img"
            :style="{ backgroundColor: colorHex(leftColor(row, col)) }"
            :aria-label="t('kid.games.mirrorSample', { color: colorLabel(leftColor(row, col)) })"
          />
          <div class="axis" aria-hidden="true" />
          <button
            v-for="col in puzzle.cols"
            :key="`r-${row}-${col}`"
            type="button"
            class="tile slot"
            :class="{
              filled: placed[indexAt(row, col)] != null,
              miss: missIndex === indexAt(row, col),
              animate,
            }"
            :style="placed[indexAt(row, col)] != null ? { backgroundColor: colorHex(placed[indexAt(row, col)] ?? 0) } : undefined"
            :aria-disabled="placed[indexAt(row, col)] != null"
            :aria-label="placed[indexAt(row, col)] != null
              ? t('kid.games.mirrorPlaced', { color: colorLabel(placed[indexAt(row, col)] ?? 0) })
              : t('kid.games.mirrorSlot')"
            @click="tap(indexAt(row, col))"
          />
        </template>
      </div>

      <div class="side">
        <div class="palette" role="group" :aria-label="t('kid.games.mirrorPick')">
          <div v-for="index in puzzle.colors" :key="index" class="choice">
            <button
              type="button"
              class="swatch"
              :class="{ on: selected === index - 1 }"
              :style="{ backgroundColor: colorHex(index - 1) }"
              :aria-pressed="selected === index - 1"
              :aria-label="colorLabel(index - 1)"
              @click="selected = index - 1"
            />
            <span class="choice-name">{{ colorLabel(index - 1) }}</span>
          </div>
        </div>
        <button v-if="phase === 'done'" type="button" class="again" @click="begin()">
          {{ t('kid.games.mirrorAgain') }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.mirror {
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
.level-label {
  font-size: 16px;
  font-weight: 800;
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
  gap: 8px;
  width: 100%;
  max-width: 520px;
  grid-template-columns: repeat(var(--cols), minmax(0, 1fr)) 16px repeat(var(--cols), minmax(0, 1fr));
  align-items: center;
}
.tile {
  aspect-ratio: 1;
  min-width: 0;
  min-height: 56px;
  border-radius: 18px;
  touch-action: manipulation;
}
.tile.source,
.tile.slot.filled {
  background-image: linear-gradient(160deg, rgba(255, 255, 255, 0.38), transparent 46%);
  box-shadow: inset 0 -5px 0 rgba(0, 0, 0, 0.12);
}
.tile.slot {
  border: none;
  padding: 0;
  background-color: rgba(255, 255, 255, 0.08);
  box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.35);
  cursor: pointer;
}
.tile.slot.filled {
  box-shadow: inset 0 -5px 0 rgba(0, 0, 0, 0.12), 0 0 0 3px rgba(255, 224, 138, 0.95);
  cursor: default;
}
.tile.slot.filled.animate {
  animation: pop 0.22s ease;
}
.tile.miss {
  background-color: #ffb4b4;
}
.tile.miss.animate {
  animation: shake 0.28s ease;
}
.axis {
  position: relative;
  align-self: stretch;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}
.axis::before {
  content: '';
  position: absolute;
  top: -4px;
  bottom: -4px;
  border-left: 2px dashed rgba(255, 224, 138, 0.9);
}
.side {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: 100%;
}
.palette {
  display: flex;
  justify-content: center;
  gap: 12px;
}
.choice {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.swatch {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  border: 4px solid transparent;
  box-shadow: inset 0 -4px 0 rgba(0, 0, 0, 0.15);
  cursor: pointer;
  touch-action: manipulation;
}
.swatch.on {
  border-color: #fff;
  box-shadow: 0 0 0 4px var(--gp-sun), inset 0 -4px 0 rgba(0, 0, 0, 0.15);
  transform: scale(1.06);
}
.choice-name {
  font-size: 13px;
  font-weight: 700;
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
.swatch:focus-visible,
.tile.slot:focus-visible,
.again:focus-visible {
  outline: 3px solid var(--gp-sun);
  outline-offset: 2px;
}
@media (min-width: 768px) and (min-height: 600px) {
  .level {
    min-height: 64px;
    font-size: 18px;
  }
  .status {
    font-size: 20px;
  }
  .board {
    max-width: 640px;
    gap: 12px;
  }
  .tile {
    min-height: 84px;
    border-radius: 24px;
  }
  .swatch {
    width: 80px;
    height: 80px;
  }
  .choice-name {
    font-size: 15px;
  }
}
@media (min-width: 768px) and (min-height: 600px) and (orientation: landscape) {
  .stage {
    width: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 200px;
    align-items: center;
    justify-items: center;
  }
  .board {
    max-width: 480px;
  }
  .palette {
    flex-direction: column;
  }
  .side {
    width: auto;
  }
}
@media (orientation: landscape) and (max-height: 520px) {
  .mirror {
    display: grid;
    width: max-content;
    max-width: 100%;
    margin: 0 auto;
    grid-template-columns: auto auto auto;
    grid-template-areas:
      'status status status'
      'levels stage palette';
    column-gap: 12px;
    row-gap: 4px;
    align-items: center;
  }
  .status {
    grid-area: status;
    font-size: 14px;
  }
  .levels {
    grid-area: levels;
    flex-direction: column;
    width: max-content;
  }
  .level {
    flex: none;
    width: 76px;
    min-height: 56px;
  }
  .stage {
    display: contents;
  }
  .board {
    --cell: min(56px, calc((100dvh - 150px) / var(--rows)));
    grid-area: stage;
    justify-self: center;
    width: max-content;
    max-width: 100%;
    gap: 6px;
    grid-template-columns: repeat(var(--cols), var(--cell)) 12px repeat(var(--cols), var(--cell));
  }
  .tile {
    width: var(--cell);
    height: var(--cell);
    min-height: 0;
    aspect-ratio: auto;
    border-radius: 14px;
  }
  .side {
    grid-area: palette;
    width: auto;
  }
  .palette {
    flex-direction: column;
    gap: 8px;
  }
  .choice-name {
    display: none;
  }
  .swatch {
    width: 56px;
    height: 56px;
  }
  .again {
    width: 76px;
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
