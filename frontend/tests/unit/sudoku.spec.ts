import { describe, expect, it } from 'vitest'
import {
  createSolvedGrid,
  createSudokuPuzzle,
  emptyCount,
  isComplete,
  remainingCount,
} from '@/kid/sudoku'

function assertValidSudoku(grid: number[], size: number, boxRows: number, boxCols: number): void {
  expect(grid).toHaveLength(size * size)
  for (let i = 0; i < size; i += 1) {
    const row = grid.slice(i * size, i * size + size)
    const col = Array.from({ length: size }, (_, r) => grid[r * size + i])
    expect(new Set(row).size).toBe(size)
    expect(new Set(col).size).toBe(size)
  }
  for (let band = 0; band < size / boxRows; band += 1) {
    for (let stack = 0; stack < size / boxCols; stack += 1) {
      const box: number[] = []
      for (let r = 0; r < boxRows; r += 1) {
        for (let c = 0; c < boxCols; c += 1) {
          box.push(grid[(band * boxRows + r) * size + (stack * boxCols + c)] ?? 0)
        }
      }
      expect(new Set(box).size).toBe(size)
    }
  }
}

describe('sudoku', () => {
  it('builds valid 4×4 and 6×6 solutions', () => {
    let n = 0
    const random = () => {
      n += 1
      return (n % 17) / 17
    }
    assertValidSudoku(createSolvedGrid(4, 2, 2, random), 4, 2, 2)
    assertValidSudoku(createSolvedGrid(6, 2, 3, random), 6, 2, 3)
  })

  it('keeps the requested number of clues', () => {
    const puzzle = createSudokuPuzzle('easy', () => 0.42)
    expect(puzzle.size).toBe(4)
    expect(emptyCount(puzzle.start)).toBe(6)
    puzzle.start.forEach((value, index) => {
      if (value !== 0) expect(value).toBe(puzzle.solution[index])
    })
  })

  it('tracks remaining empty cells until the board is complete', () => {
    const puzzle = {
      size: 4,
      boxRows: 2,
      boxCols: 2,
      solution: [1, 2, 3, 4, 3, 4, 1, 2, 2, 1, 4, 3, 4, 3, 2, 1],
      start: [1, 0, 3, 0, 0, 4, 0, 2, 2, 0, 4, 0, 0, 3, 0, 1],
    }
    const placed: Array<number | null> = puzzle.start.map(() => null)
    expect(remainingCount(puzzle, placed)).toBe(8)
    placed[1] = 2
    placed[3] = 4
    expect(remainingCount(puzzle, placed)).toBe(6)
    expect(isComplete(puzzle, placed)).toBe(false)
    placed[4] = 3
    placed[6] = 1
    placed[9] = 1
    placed[11] = 3
    placed[12] = 4
    placed[14] = 2
    expect(isComplete(puzzle, placed)).toBe(true)
  })
})
