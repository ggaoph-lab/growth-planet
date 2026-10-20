/** Kid Sudoku: 4×4 and 6×6 boards with large touch targets. */

export const SUDOKU_LEVELS = [
  { id: 'easy', size: 4, boxRows: 2, boxCols: 2, clues: 10 },
  { id: 'practice', size: 6, boxRows: 2, boxCols: 3, clues: 26 },
  { id: 'challenge', size: 6, boxRows: 2, boxCols: 3, clues: 18 },
] as const

export type SudokuLevelId = (typeof SUDOKU_LEVELS)[number]['id']

export interface SudokuPuzzle {
  size: number
  boxRows: number
  boxCols: number
  /** Complete solution, row-major, values 1…size. */
  solution: number[]
  /** Starter board: 0 for empty, otherwise a clue from the solution. */
  start: number[]
}

function shuffle<T>(items: T[], random: () => number): T[] {
  const next = items.slice()
  for (let i = next.length - 1; i > 0; i -= 1) {
    const j = Math.floor(random() * (i + 1))
    const a = next[i]
    const b = next[j]
    if (a === undefined || b === undefined) continue
    next[i] = b
    next[j] = a
  }
  return next
}

function baseDigit(size: number, boxRows: number, boxCols: number, row: number, col: number): number {
  return (boxCols * (row % boxRows) + Math.floor(row / boxRows) + col) % size
}

function remap(grid: number[], order: number[]): number[] {
  return order.flatMap((row) => {
    const start = row * order.length
    return grid.slice(start, start + order.length)
  })
}

function remapCols(grid: number[], size: number, order: number[]): number[] {
  const next: number[] = []
  for (let row = 0; row < size; row += 1) {
    for (const col of order) {
      next.push(grid[row * size + col] ?? 0)
    }
  }
  return next
}

/** Build a full valid Sudoku grid, then shuffle bands, stacks, and digits. */
export function createSolvedGrid(
  size: number,
  boxRows: number,
  boxCols: number,
  random: () => number = Math.random,
): number[] {
  let grid = Array.from({ length: size * size }, (_, index) => {
    const row = Math.floor(index / size)
    const col = index % size
    return baseDigit(size, boxRows, boxCols, row, col) + 1
  })

  const digits = shuffle(Array.from({ length: size }, (_, i) => i + 1), random)
  grid = grid.map((value) => digits[value - 1] ?? value)

  const bands = Math.floor(size / boxRows)
  const bandOrder = shuffle(Array.from({ length: bands }, (_, i) => i), random)
  const rowOrder = bandOrder.flatMap((band) => {
    const rows = Array.from({ length: boxRows }, (_, i) => band * boxRows + i)
    return shuffle(rows, random)
  })
  grid = remap(grid, rowOrder)

  const stacks = Math.floor(size / boxCols)
  const stackOrder = shuffle(Array.from({ length: stacks }, (_, i) => i), random)
  const colOrder = stackOrder.flatMap((stack) => {
    const cols = Array.from({ length: boxCols }, (_, i) => stack * boxCols + i)
    return shuffle(cols, random)
  })
  return remapCols(grid, size, colOrder)
}

export function createSudokuPuzzle(level: SudokuLevelId, random: () => number = Math.random): SudokuPuzzle {
  const spec = SUDOKU_LEVELS.find((item) => item.id === level) ?? SUDOKU_LEVELS[0]
  const solution = createSolvedGrid(spec.size, spec.boxRows, spec.boxCols, random)
  const total = solution.length
  const clueCount = Math.min(spec.clues, total - 1)
  const keep = new Set(shuffle(Array.from({ length: total }, (_, i) => i), random).slice(0, clueCount))
  const start = solution.map((value, index) => (keep.has(index) ? value : 0))
  return { size: spec.size, boxRows: spec.boxRows, boxCols: spec.boxCols, solution, start }
}

export function emptyCount(start: number[]): number {
  return start.reduce((count, value) => count + (value === 0 ? 1 : 0), 0)
}

export function filledCount(start: number[], placed: Array<number | null>): number {
  return start.reduce((count, value, index) => {
    if (value !== 0) return count
    return count + (placed[index] != null ? 1 : 0)
  }, 0)
}

export function remainingCount(puzzle: SudokuPuzzle, placed: Array<number | null>): number {
  return emptyCount(puzzle.start) - filledCount(puzzle.start, placed)
}

export function isComplete(puzzle: SudokuPuzzle, placed: Array<number | null>): boolean {
  return puzzle.start.every((value, index) => {
    if (value !== 0) return true
    return placed[index] === puzzle.solution[index]
  })
}
