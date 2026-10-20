import { describe, expect, it } from 'vitest'
import { createMirrorPuzzle, expectedColor, matchedCount, type MirrorPuzzle } from '@/kid/mirror'

describe('mirror puzzle', () => {
  it('builds a left half inside the level color count', () => {
    let n = 0
    const puzzle = createMirrorPuzzle('easy', () => {
      n += 1
      return (n % 5) / 5
    })
    expect(puzzle).toMatchObject({ rows: 3, cols: 2, colors: 2 })
    expect(puzzle.left).toHaveLength(6)
    expect(puzzle.left.every((color) => color === 0 || color === 1)).toBe(true)
  })

  it('keeps a two-color pattern when every roll lands on one color', () => {
    const puzzle = createMirrorPuzzle('practice', () => 0)
    expect(puzzle.colors).toBe(3)
    expect(puzzle.left).toHaveLength(8)
    expect(new Set(puzzle.left).size).toBeGreaterThan(1)
  })

  it('mirrors a cell across the vertical line', () => {
    const puzzle: MirrorPuzzle = { rows: 2, cols: 3, colors: 3, left: [0, 1, 2, 2, 1, 0] }
    expect(expectedColor(puzzle, 0)).toBe(2)
    expect(expectedColor(puzzle, 1)).toBe(1)
    expect(expectedColor(puzzle, 2)).toBe(0)
    expect(expectedColor(puzzle, 3)).toBe(0)
    expect(expectedColor(puzzle, 4)).toBe(1)
    expect(expectedColor(puzzle, 5)).toBe(2)
    expect(matchedCount(puzzle, [2, 1, null, null, null, null])).toBe(2)
    expect(matchedCount(puzzle, [2, 1, 0, 0, 1, 2])).toBe(6)
    expect(matchedCount(puzzle, [0, 0, 1, 1, 0, 0])).toBe(0)
  })
})
