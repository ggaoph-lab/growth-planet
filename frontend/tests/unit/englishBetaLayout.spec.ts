import { beforeEach, expect, it } from 'vitest'
import {
  DEFAULT_LAYOUT,
  LAYOUT_STORAGE_KEY,
  clearLayout,
  clampCols,
  clampHeight,
  clampScale,
  defaultLayout,
  loadLayout,
  saveLayout,
  visibleCards,
} from '@/kid/englishBeta/layout'

beforeEach(() => {
  localStorage.clear()
})

it('clamps cols, height and scale', () => {
  expect(clampScale(0.2)).toBe(0.7)
  expect(clampScale(2)).toBe(1.5)
  expect(clampCols(1)).toBe(2)
  expect(clampCols(20)).toBe(12)
  expect(clampHeight(40)).toBe(60)
  expect(clampHeight(900)).toBe(560)
})

it('saves and loads layout from localStorage', () => {
  const layout = defaultLayout()
  layout.order = ['talk', 'intro', 'plan', 'scenes', 'listen', 'missions', 'words']
  layout.scale.intro = 1.2
  layout.cols.listen = 4
  layout.heights.missions = 240
  saveLayout(layout)
  expect(localStorage.getItem(LAYOUT_STORAGE_KEY)).toBeTruthy()
  const loaded = loadLayout()
  expect(loaded.order[0]).toBe('talk')
  expect(loaded.scale.intro).toBe(1.2)
  expect(loaded.cols.listen).toBe(4)
  expect(loaded.heights.missions).toBe(240)
})

it('falls back to defaults and clear works', () => {
  localStorage.setItem(LAYOUT_STORAGE_KEY, '{bad')
  expect(loadLayout().order).toEqual(DEFAULT_LAYOUT.order)
  saveLayout(defaultLayout())
  clearLayout()
  expect(localStorage.getItem(LAYOUT_STORAGE_KEY)).toBeNull()
})

it('filters visible cards by session state', () => {
  const order = DEFAULT_LAYOUT.order
  expect(visibleCards(order, false, true)).toEqual(['intro', 'scenes', 'plan', 'words'])
  expect(visibleCards(order, true, true)).toEqual(['intro', 'listen', 'missions', 'talk', 'words'])
  expect(visibleCards(order, false, false)).toEqual(['intro', 'scenes', 'words'])
})
