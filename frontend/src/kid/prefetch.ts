import { warmGet } from '@/shared/api/warmGet'

/** Route name → async page chunk (mirrors router lazy imports). */
const routeChunks: Record<string, () => Promise<unknown>> = {
  'kid-tasks': () => import('@/kid/pages/KidTasksPage.vue'),
  'kid-habits': () => import('@/kid/pages/KidHabitsPage.vue'),
  'kid-wallet': () => import('@/kid/pages/KidWalletPage.vue'),
  'kid-shop': () => import('@/kid/pages/KidShopPage.vue'),
  'kid-bag': () => import('@/kid/pages/KidBagPage.vue'),
  'kid-schedule': () => import('@/kid/pages/KidSchedulePage.vue'),
  'kid-explore': () => import('@/kid/pages/KidExplorePage.vue'),
  'kid-food': () => import('@/kid/pages/KidFoodPage.vue'),
  'kid-movies': () => import('@/kid/pages/KidMoviesPage.vue'),
  'kid-english': () => import('@/kid/pages/KidEnglishPage.vue'),
  'kid-english-beta': () => import('@/kid/pages/KidEnglishBetaPage.vue'),
  'kid-ielts': () => import('@/kid/pages/KidIeltsPage.vue'),
  'kid-encyclopedia': () => import('@/kid/pages/KidEncyclopediaPage.vue'),
  'kid-games': () => import('@/kid/pages/KidGamesPage.vue'),
  'kid-schulte': () => import('@/kid/pages/KidSchultePage.vue'),
  'kid-mirror': () => import('@/kid/pages/KidMirrorPage.vue'),
  'kid-sudoku': () => import('@/kid/pages/KidSudokuPage.vue'),
  'kid-collection': () => import('@/kid/pages/KidCollectionPage.vue'),
}

/** Primary GETs a page needs before it can paint content. */
const routeData: Record<string, () => void> = {
  'kid-tasks': () => {
    void warmGet('/kid/tasks')
  },
  'kid-habits': () => {
    void warmGet('/kid/habits')
  },
  'kid-wallet': () => {
    void warmGet('/kid/wallet')
  },
  'kid-shop': () => {
    void warmGet('/kid/shop')
    void warmGet('/kid/wishes')
    void warmGet('/kid/wallet')
  },
  'kid-bag': () => {
    void warmGet('/kid/bag')
  },
  'kid-schedule': () => {
    void warmGet('/kid/schedule')
    void warmGet('/kid/schedule/week')
  },
  'kid-explore': () => {
    void warmGet('/kid/explorations')
  },
  'kid-food': () => {
    void warmGet('/kid/foods')
  },
  'kid-movies': () => {
    void warmGet('/kid/movies')
    void warmGet('/kid/screen-time')
  },
  'kid-english': () => {
    void warmGet('/kid/english/learning')
  },
  'kid-encyclopedia': () => {
    void warmGet('/kid/encyclopedia')
  },
  'kid-collection': () => {
    void warmGet('/kid/collection')
  },
}

const warmedChunks = new Set<string>()

/** Prefetch the JS chunk (and optionally the first API payload) for a kid route. */
export function prefetchKidRoute(name: string, options?: { data?: boolean }): void {
  const load = routeChunks[name]
  if (load && !warmedChunks.has(name)) {
    warmedChunks.add(name)
    void load()
  }
  if (options?.data !== false) routeData[name]?.()
}

/**
 * After the planet grid is ready, pull shared JS + stagger API warm-ups so a tap rarely waits.
 * English JS starts with the light planets (shell is small); its API is warmed mid-queue.
 */
export function prefetchKidRouteChunks(names: string[]): void {
  const unique = [...new Set(names)]
  const hasEnglish = unique.includes('kid-english')
  const light = unique.filter((name) => name !== 'kid-english')

  for (const name of light) {
    prefetchKidRoute(name, { data: false })
  }
  if (hasEnglish) {
    // Route shell + async EnglishLearning chunk; start both early.
    prefetchKidRoute('kid-english', { data: false })
    void import('@/kid/components/EnglishLearning.vue')
  }

  const dataOrder = [
    ...light.slice(0, 3),
    ...(hasEnglish ? ['kid-english'] : []),
    ...light.slice(3),
  ]
  let delay = 0
  for (const name of dataOrder) {
    window.setTimeout(() => prefetchKidRoute(name, { data: true }), delay)
    delay += 100
  }
}
