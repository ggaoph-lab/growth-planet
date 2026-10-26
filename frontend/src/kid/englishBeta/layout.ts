export type CardId = 'intro' | 'plan' | 'scenes' | 'listen' | 'missions' | 'talk' | 'words'

/** 12-column board: cols = width in columns, height = min height in px, scale = content zoom. */
export type LayoutState = {
  order: CardId[]
  cols: Record<CardId, number>
  heights: Record<CardId, number>
  scale: Record<CardId, number>
}

export const LAYOUT_STORAGE_KEY = 'english-beta-layout-v5'
export const GRID_COLS = 12
export const SCALE_MIN = 0.7
export const SCALE_MAX = 1.5
export const COL_MIN = 2
export const COL_MAX = 12
export const HEIGHT_MIN = 60
export const HEIGHT_MAX = 560

export const ALL_CARDS: CardId[] = ['intro', 'plan', 'scenes', 'listen', 'missions', 'talk', 'words']

export const DEFAULT_LAYOUT: LayoutState = {
  order: ['intro', 'scenes', 'plan', 'listen', 'missions', 'talk', 'words'],
  cols: {
    intro: 12,
    plan: 4,
    scenes: 8,
    listen: 8,
    missions: 4,
    talk: 12,
    words: 12,
  },
  heights: {
    intro: 136,
    plan: 260,
    scenes: 260,
    listen: 420,
    missions: 420,
    talk: 340,
    words: 70,
  },
  scale: {
    intro: 1,
    plan: 1,
    scenes: 1,
    listen: 1,
    missions: 1,
    talk: 1,
    words: 1,
  },
}

function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n))
}

export function clampScale(n: number): number {
  return Math.round(clamp(n, SCALE_MIN, SCALE_MAX) * 100) / 100
}

export function clampCols(n: number): number {
  return Math.round(clamp(n, COL_MIN, COL_MAX))
}

export function clampHeight(n: number): number {
  return Math.round(clamp(n, HEIGHT_MIN, HEIGHT_MAX))
}

export function defaultLayout(): LayoutState {
  return {
    order: [...DEFAULT_LAYOUT.order],
    cols: { ...DEFAULT_LAYOUT.cols },
    heights: { ...DEFAULT_LAYOUT.heights },
    scale: { ...DEFAULT_LAYOUT.scale },
  }
}

export function loadLayout(storage: Storage = localStorage): LayoutState {
  try {
    const raw = storage.getItem(LAYOUT_STORAGE_KEY)
    if (!raw) return defaultLayout()
    const parsed = JSON.parse(raw) as Partial<LayoutState>
    const base = defaultLayout()
    const order = Array.isArray(parsed.order)
      ? parsed.order.filter((id): id is CardId => ALL_CARDS.includes(id as CardId))
      : base.order
    for (const id of ALL_CARDS) {
      if (!order.includes(id)) order.push(id)
    }
    const cols = { ...base.cols }
    const heights = { ...base.heights }
    const scale = { ...base.scale }
    for (const id of ALL_CARDS) {
      if (typeof parsed.cols?.[id] === 'number') cols[id] = clampCols(parsed.cols[id])
      if (typeof parsed.heights?.[id] === 'number') heights[id] = clampHeight(parsed.heights[id])
      if (typeof parsed.scale?.[id] === 'number') scale[id] = clampScale(parsed.scale[id])
    }
    return { order, cols, heights, scale }
  } catch {
    return defaultLayout()
  }
}

export function saveLayout(layout: LayoutState, storage: Storage = localStorage): void {
  storage.setItem(LAYOUT_STORAGE_KEY, JSON.stringify(layout))
}

export function clearLayout(storage: Storage = localStorage): void {
  storage.removeItem(LAYOUT_STORAGE_KEY)
}

export function visibleCards(order: CardId[], hasSession: boolean, hasWeeklyPlan: boolean): CardId[] {
  return order.filter((id) => {
    if (id === 'listen' || id === 'missions' || id === 'talk') return hasSession
    if (id === 'scenes') return !hasSession
    if (id === 'plan') return !hasSession && hasWeeklyPlan
    return true
  })
}
