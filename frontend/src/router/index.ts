import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { i18n } from '@/i18n'
import { useKidHomeStore } from '@/stores/kidHome'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Which session may see this page. Purely a UX redirect; the API enforces real permissions. */
    role?: 'parent' | 'child' | 'guest'
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'root', component: { render: () => null } },
  { path: '/login', name: 'login', component: () => import('@/parent/pages/LoginPage.vue'), meta: { role: 'guest', title: 'auth.parentLogin' } },
  { path: '/register', name: 'register', component: () => import('@/parent/pages/RegisterPage.vue'), meta: { role: 'guest', title: 'auth.registerTitle' } },
  { path: '/kid/select', name: 'kid-select', component: () => import('@/kid/pages/KidSelectPage.vue'), meta: { role: 'guest', title: 'auth.whoAreYou' } },
  {
    path: '/kid',
    component: () => import('@/kid/KidLayout.vue'),
    meta: { role: 'child' },
    children: [
      { path: '', name: 'kid-home', component: () => import('@/kid/pages/KidHomePage.vue'), meta: { role: 'child', title: 'kid.homeTitle' } },
      { path: 'tasks', name: 'kid-tasks', component: () => import('@/kid/pages/KidTasksPage.vue'), meta: { role: 'child', title: 'kid.tasks' } },
      { path: 'habits', name: 'kid-habits', component: () => import('@/kid/pages/KidHabitsPage.vue'), meta: { role: 'child', title: 'kid.habits' } },
      { path: 'wallet', name: 'kid-wallet', component: () => import('@/kid/pages/KidWalletPage.vue'), meta: { role: 'child', title: 'kid.wallet' } },
      { path: 'shop', name: 'kid-shop', component: () => import('@/kid/pages/KidShopPage.vue'), meta: { role: 'child', title: 'kid.shop' } },
      { path: 'bag', name: 'kid-bag', component: () => import('@/kid/pages/KidBagPage.vue'), meta: { role: 'child', title: 'kid.bag' } },
      { path: 'schedule', name: 'kid-schedule', component: () => import('@/kid/pages/KidSchedulePage.vue'), meta: { role: 'child', title: 'schedule.kidTitle' } },
      { path: 'explore', name: 'kid-explore', component: () => import('@/kid/pages/KidExplorePage.vue'), meta: { role: 'child', title: 'kid.explore' } },
      { path: 'food', name: 'kid-food', component: () => import('@/kid/pages/KidFoodPage.vue'), meta: { role: 'child', title: 'kid.food' } },
      { path: 'movies', name: 'kid-movies', component: () => import('@/kid/pages/KidMoviesPage.vue'), meta: { role: 'child', title: 'kid.movies' } },
      { path: 'english', name: 'kid-english', component: () => import('@/kid/pages/KidEnglishPage.vue'), meta: { role: 'child', title: 'kid.english' } },
      { path: 'encyclopedia', name: 'kid-encyclopedia', component: () => import('@/kid/pages/KidEncyclopediaPage.vue'), meta: { role: 'child', title: 'kid.encyclopedia.title' } },
      { path: 'games', name: 'kid-games', component: () => import('@/kid/pages/KidGamesPage.vue'), meta: { role: 'child', title: 'kid.gamesTitle' } },
      { path: 'games/schulte', name: 'kid-schulte', component: () => import('@/kid/pages/KidSchultePage.vue'), meta: { role: 'child', title: 'kid.schulteTitle' } },
      { path: 'games/mirror', name: 'kid-mirror', component: () => import('@/kid/pages/KidMirrorPage.vue'), meta: { role: 'child', title: 'kid.mirrorTitle' } },
      { path: 'games/sudoku', name: 'kid-sudoku', component: () => import('@/kid/pages/KidSudokuPage.vue'), meta: { role: 'child', title: 'kid.sudokuTitle' } },
      { path: 'collection', name: 'kid-collection', component: () => import('@/kid/pages/KidCollectionPage.vue'), meta: { role: 'child', title: 'kid.collection' } },
    ],
  },
  {
    path: '/parent',
    component: () => import('@/parent/ParentLayout.vue'),
    meta: { role: 'parent' },
    children: [
      { path: '', name: 'parent-dashboard', component: () => import('@/parent/pages/DashboardPage.vue'), meta: { role: 'parent', title: 'parent.nav.dashboard' } },
      { path: 'approvals', name: 'parent-approvals', component: () => import('@/parent/pages/ApprovalsPage.vue'), meta: { role: 'parent', title: 'parent.nav.approvals' } },
      { path: 'tasks', name: 'parent-tasks', component: () => import('@/parent/pages/TasksPage.vue'), meta: { role: 'parent', title: 'parent.nav.tasks' } },
      { path: 'habits', name: 'parent-habits', component: () => import('@/parent/pages/HabitsPage.vue'), meta: { role: 'parent', title: 'parent.nav.habits' } },
      { path: 'shop', name: 'parent-shop', component: () => import('@/parent/pages/ShopPage.vue'), meta: { role: 'parent', title: 'parent.nav.shop' } },
      { path: 'life', name: 'parent-life', component: () => import('@/parent/pages/LifePage.vue'), meta: { role: 'parent', title: 'parent.nav.life' } },
      { path: 'schedule', name: 'parent-schedule', component: () => import('@/parent/pages/SchedulePage.vue'), meta: { role: 'parent', title: 'parent.nav.schedule' } },
      { path: 'media', name: 'parent-media', component: () => import('@/parent/pages/MediaPage.vue'), meta: { role: 'parent', title: 'parent.nav.media' } },
      { path: 'reports', name: 'parent-reports', component: () => import('@/parent/pages/ReportsPage.vue'), meta: { role: 'parent', title: 'parent.nav.reports' } },
      { path: 'children', name: 'parent-children', component: () => import('@/parent/pages/ChildrenPage.vue'), meta: { role: 'parent', title: 'parent.nav.children' } },
      { path: 'devices', name: 'parent-devices', component: () => import('@/parent/pages/DevicesPage.vue'), meta: { role: 'parent', title: 'parent.nav.devices' } },
      { path: 'audit-logs', name: 'parent-audit', component: () => import('@/parent/pages/AuditLogPage.vue'), meta: { role: 'parent', title: 'parent.nav.audit' } },
      { path: 'family', name: 'parent-family', component: () => import('@/parent/pages/FamilySettingsPage.vue'), meta: { role: 'parent', title: 'parent.nav.family' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const session = useSessionStore()
  await session.load()

  const home = async () => {
    if (session.isParent) return { name: 'parent-dashboard' }
    if (session.isChild) return { name: 'kid-home' }
    try {
      return (await session.checkDevice()) ? { name: 'kid-select' } : { name: 'login' }
    } catch {
      return { name: 'login' }
    }
  }

  if (to.name === 'root') return home()

  const required = to.meta.role
  if (required === 'parent' && !session.isParent) return session.isChild ? { name: 'kid-home' } : { name: 'login' }
  if (required === 'child' && !session.isChild) return session.isParent ? { name: 'parent-dashboard' } : { name: 'kid-select' }
  if (required === 'guest' && session.isChild) return { name: 'kid-home' }

  // Planet availability: only use already-cached home data so taps never wait on the network.
  // Page APIs still enforce access when the cache is cold or stale.
  if (required === 'child' && to.name !== 'kid-home') {
    const modules: Record<string, string> = {
      tasks: 'task_planet', habits: 'task_planet', wallet: 'wallet', shop: 'shop',
      bag: 'bag_planet', schedule: 'schedule_planet', explore: 'explore_planet',
      food: 'food_diary', movies: 'movie_planet', english: 'english_planet',
      encyclopedia: 'encyclopedia_planet', games: 'game_planet', collection: 'collection',
    }
    const key = modules[to.path.split('/')[2] ?? '']
    const planets = useKidHomeStore().home?.planets
    if (key && planets && !planets.some((planet) => planet.key === key)) return { name: 'kid-home' }
  }
  return true
})

router.afterEach((to) => {
  const appName = i18n.global.t('app.name')
  const titleKey = to.meta.title
  document.title = titleKey ? `${i18n.global.t(titleKey)} · ${appName}` : String(appName)
})
