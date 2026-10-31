/** Daily once-per-level coins shared by Mirror and Sudoku (Schulte uses sizes instead). */

export const GAME_LEVEL_REWARDS = {
  easy: 1,
  practice: 2,
  challenge: 3,
} as const

export type GameLevelId = keyof typeof GAME_LEVEL_REWARDS

export type GameRewardGame = 'mirror' | 'sudoku'

export interface GameLevelBoardRow {
  id: GameLevelId
  coins: number
  awarded: boolean
  remaining: number
}

export interface GameLevelBoard {
  date: string
  levels: GameLevelBoardRow[]
}

export interface GamePlayStart {
  play_id: string
  remaining: number
  date: string
}

export interface GameLevelClaim {
  awarded_coins: number
  board: GameLevelBoard
}
