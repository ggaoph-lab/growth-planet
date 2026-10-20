/** Mirror puzzle: copy the left collage onto the right, flipped across a vertical line. */

export const MIRROR_LEVELS = [
  { id: 'easy', rows: 3, cols: 2, colors: 2 },
  { id: 'practice', rows: 4, cols: 2, colors: 3 },
  { id: 'challenge', rows: 4, cols: 2, colors: 4 },
] as const

export type MirrorLevelId = (typeof MIRROR_LEVELS)[number]['id']

export const MIRROR_COLORS = [
  { id: 'orange', hex: '#ff8a3d' },
  { id: 'blue', hex: '#5b8def' },
  { id: 'green', hex: '#3dcc8a' },
  { id: 'pink', hex: '#e86bb0' },
] as const

export interface MirrorPuzzle {
  rows: number
  cols: number
  colors: number
  /** Row-major color indexes for the left half. The right half is its vertical mirror. */
  left: number[]
}

export function createMirrorPuzzle(level: MirrorLevelId, random: () => number = Math.random): MirrorPuzzle {
  const spec = MIRROR_LEVELS.find((item) => item.id === level) ?? MIRROR_LEVELS[0]
  const total = spec.rows * spec.cols
  const left = Array.from({ length: total }, () => Math.min(spec.colors - 1, Math.floor(random() * spec.colors)))
  const first = left[0] ?? 0
  if (spec.colors > 1 && left.every((color) => color === first)) {
    left[total - 1] = (first + 1) % spec.colors
  }
  return { rows: spec.rows, cols: spec.cols, colors: spec.colors, left }
}

/** Color the right-hand cell at `index` must show so it mirrors the left half. */
export function expectedColor(puzzle: MirrorPuzzle, index: number): number {
  const row = Math.floor(index / puzzle.cols)
  const col = index % puzzle.cols
  const mirrorCol = puzzle.cols - 1 - col
  return puzzle.left[row * puzzle.cols + mirrorCol] ?? 0
}

export function matchedCount(puzzle: MirrorPuzzle, placed: Array<number | null>): number {
  return puzzle.left.reduce((count, _, index) => {
    return count + (placed[index] === expectedColor(puzzle, index) ? 1 : 0)
  }, 0)
}
