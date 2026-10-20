/** Schulte grid: tap 1…n² in order. Sizes match a first-grader's attention practice. */
export const SCHULTE_SIZES = [3, 4, 5] as const
export type SchulteSize = (typeof SCHULTE_SIZES)[number]

/** Daily check-in: finish inside the limit to earn points once per size. */
export const SCHULTE_REWARDS: Record<SchulteSize, { limitMs: number; coins: number }> = {
  3: { limitMs: 9_000, coins: 1 },
  4: { limitMs: 16_000, coins: 2 },
  5: { limitMs: 25_000, coins: 3 },
}

export interface SchulteRank {
  child_id: number
  nickname: string
  best_ms: number
  coins: number
}

export interface SchulteSizeBoard {
  size: SchulteSize
  limit_ms: number
  coins: number
  best_ms: number | null
  awarded: boolean
  ranks: SchulteRank[]
}

export interface SchulteBoard {
  date: string
  sizes: SchulteSizeBoard[]
}

export interface SchulteFinish {
  elapsed_ms: number
  improved: boolean
  awarded_coins: number
  board: SchulteBoard
}

export const SCHULTE_BEST_KEY = 'gp.schulte.best.v1'

export function isSchulteSize(size: number): size is SchulteSize {
  return size === 3 || size === 4 || size === 5
}

export function schulteNumbers(size: SchulteSize): number[] {
  return Array.from({ length: size * size }, (_, index) => index + 1)
}

/** Fisher-Yates. `random` returns a value in [0, 1). */
export function shuffleNumbers(size: SchulteSize, random: () => number = Math.random): number[] {
  const cells = schulteNumbers(size)
  for (let i = cells.length - 1; i > 0; i -= 1) {
    const j = Math.floor(random() * (i + 1))
    const current = cells[i]
    const other = cells[j]
    if (current === undefined || other === undefined) continue
    cells[i] = other
    cells[j] = current
  }
  return cells
}

/** Clock text a child can read: `18.4` or `1:05.1`. */
export function formatSchulteTime(ms: number): string {
  const tenths = Number.isFinite(ms) ? Math.max(0, Math.round(ms / 100)) : 0
  const totalSeconds = Math.floor(tenths / 10)
  const fraction = tenths % 10
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  if (minutes > 0) return `${minutes}:${String(seconds).padStart(2, '0')}.${fraction}`
  return `${seconds}.${fraction}`
}

function bestKey(childId: number | null, size: SchulteSize): string {
  return `${childId ?? 'guest'}:${size}`
}

function readMap(storage: Storage): Record<string, unknown> {
  try {
    const raw = storage.getItem(SCHULTE_BEST_KEY)
    if (!raw) return {}
    const parsed: unknown = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
    return parsed as Record<string, unknown>
  } catch {
    return {}
  }
}

export function readBest(childId: number | null, size: SchulteSize, storage: Storage = localStorage): number | null {
  const value = readMap(storage)[bestKey(childId, size)]
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null
}

/** Keeps the fastest finished time for this child and grid size. */
export function rememberBest(
  childId: number | null,
  size: SchulteSize,
  ms: number,
  storage: Storage = localStorage,
): { best: number; improved: boolean } {
  const previous = readBest(childId, size, storage)
  if (!Number.isFinite(ms) || ms < 0) {
    return { best: previous ?? 0, improved: false }
  }
  if (previous !== null && ms >= previous) return { best: previous, improved: false }
  const map = readMap(storage)
  map[bestKey(childId, size)] = ms
  storage.setItem(SCHULTE_BEST_KEY, JSON.stringify(map))
  return { best: ms, improved: true }
}
