<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import en from 'element-plus/es/locale/lang/en'
import LanguageSwitch from '@/shared/LanguageSwitch.vue'
import { useSessionStore } from '@/stores/session'
import { useIsPhone } from '@/shared/useMediaQuery'
import SudoDialog from './components/SudoDialog.vue'

const { t, locale } = useI18n()
const epLocale = computed(() => (locale.value === 'en' ? en : zhCn))
const pageTitle = computed(() => (route.meta.title ? t(route.meta.title) : ''))
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const isPhone = useIsPhone()
const moreOpen = ref(false)

type NavItem = { name: string; label: string; icon: string }

const primaryNav = computed<NavItem[]>(() => [
  { name: 'parent-dashboard', label: t('parent.nav.dashboard'), icon: '🏠' },
  { name: 'parent-approvals', label: t('parent.nav.approvals'), icon: '✅' },
  { name: 'parent-tasks', label: t('parent.nav.tasks'), icon: '📋' },
  { name: 'parent-habits', label: t('parent.nav.habits'), icon: '🌱' },
  { name: 'parent-shop', label: t('parent.nav.shop'), icon: '🎁' },
  { name: 'parent-life', label: t('parent.nav.life'), icon: '🎒' },
  { name: 'parent-schedule', label: t('parent.nav.schedule'), icon: '📅' },
  { name: 'parent-media', label: t('parent.nav.media'), icon: '🎬' },
  { name: 'parent-children', label: t('parent.nav.children'), icon: '🧒' },
])

const moreNav = computed<NavItem[]>(() => [
  { name: 'parent-reports', label: t('parent.nav.reports'), icon: '📊' },
  { name: 'parent-english-beta', label: '英语星球 Beta', icon: '🔤' },
  { name: 'parent-poetry', label: '诗词星球', icon: '📜' },
  { name: 'parent-devices', label: t('parent.nav.devices'), icon: '📱' },

  { name: 'parent-audit', label: t('parent.nav.audit'), icon: '📜' },
  { name: 'parent-family', label: t('parent.nav.family'), icon: '⚙️' },
])

const navGroups = computed(() => [
  { key: 'daily', label: t('parent.nav.groupDaily'), items: primaryNav.value.slice(0, 4) },
  { key: 'life', label: t('parent.nav.groupLife'), items: primaryNav.value.slice(4, 8) },
  { key: 'family', label: t('parent.nav.groupFamily'), items: [primaryNav.value[8]!, ...moreNav.value] },
])

const phoneNav = computed(() => primaryNav.value.slice(0, 5))

function isActive(name: string): boolean {
  return route.name === name
}

function isMoreActive(): boolean {
  return moreNav.value.some((item) => item.name === route.name) || primaryNav.value.slice(5).some((item) => item.name === route.name)
}

async function goMore(name: string): Promise<void> {
  moreOpen.value = false
  await router.push({ name })
}

async function toKidMode(): Promise<void> {
  if (!(await session.checkDevice())) {
    ElMessage.warning(t('parent.devices.needAuthFirst'))
    await router.push({ name: 'parent-devices', query: { for: 'kid' } })
    return
  }
  await session.logout()
  await router.push({ name: 'kid-select' })
}

async function logout(): Promise<void> {
  await session.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <el-config-provider :locale="epLocale">
    <div class="parent" :class="{ phone: isPhone }">
      <aside v-if="!isPhone" class="sidebar" aria-label="parent navigation">
        <div class="brand">
          <span class="brand-mark" aria-hidden="true">🪐</span>
          <div class="brand-copy">
            <strong>{{ t('app.name') }}</strong>
            <small>{{ t('parent.nav.parentConsole') }}</small>
          </div>
        </div>

        <nav class="side-nav">
          <section v-for="group in navGroups" :key="group.key" class="nav-group">
            <p class="nav-label">{{ group.label }}</p>
            <RouterLink
              v-for="item in group.items"
              :key="item.name"
              :to="{ name: item.name }"
              class="side-link"
              :class="{ active: isActive(item.name) }"
            >
              <span class="icon" aria-hidden="true">{{ item.icon }}</span>
              <span class="side-label">{{ item.label }}</span>
            </RouterLink>
          </section>
        </nav>

        <div class="side-foot">
          <button type="button" class="side-cta" @click="toKidMode">{{ t('parent.nav.kidMode') }}</button>
        </div>
      </aside>

      <div class="main">
        <header class="topbar">
          <div class="topbar-title">
            <p class="eyebrow">{{ t('parent.nav.parentConsole') }}</p>
            <h1>{{ pageTitle }}</h1>
          </div>
          <div class="actions">
            <LanguageSwitch />
            <el-button v-if="isPhone" size="small" round @click="toKidMode">{{ t('parent.nav.kidMode') }}</el-button>
            <el-dropdown trigger="click">
              <button type="button" class="avatar-btn" :aria-label="session.me?.user.display_name">
                {{ session.me?.user.display_name.slice(0, 1) }}
              </button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item disabled>{{ session.me?.user.display_name }} · {{ session.me?.family.name }}</el-dropdown-item>
                  <el-dropdown-item divided @click="logout">{{ t('common.logout') }}</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </header>

        <main class="content">
          <div class="content-frame">
            <RouterView />
          </div>
        </main>
      </div>

      <nav v-if="isPhone" class="bottom-nav">
        <RouterLink
          v-for="item in phoneNav"
          :key="item.name"
          :to="{ name: item.name }"
          class="bottom-link"
          :class="{ active: isActive(item.name) }"
        >
          <span class="icon">{{ item.icon }}</span>
          <span class="label">{{ item.label }}</span>
        </RouterLink>
        <button type="button" class="bottom-link more" :class="{ active: isMoreActive() || moreOpen }" @click="moreOpen = !moreOpen">
          <span class="icon">⋯</span>
          <span class="label">{{ t('parent.nav.more') }}</span>
        </button>
      </nav>

      <div v-if="isPhone && moreOpen" class="more-sheet" @click.self="moreOpen = false">
        <div class="more-panel">
          <button v-for="item in moreNav" :key="item.name" type="button" class="more-item" @click="goMore(item.name)">
            <span>{{ item.icon }}</span> {{ item.label }}
          </button>
          <button
            v-for="item in primaryNav.slice(5)"
            :key="item.name"
            type="button"
            class="more-item"
            @click="goMore(item.name)"
          >
            <span>{{ item.icon }}</span> {{ item.label }}
          </button>
        </div>
      </div>

      <SudoDialog />
    </div>
  </el-config-provider>
</template>

<style scoped>
.parent {
  --parent-sidebar: 260px;
  --parent-gutter: clamp(20px, 2.4vw, 40px);
  --parent-content-max: 1480px;
  --parent-surface: #f4f6fb;
  --parent-panel: #ffffff;
  --parent-line: #e6e8f0;
  --parent-ink: #1c2033;
  --parent-muted: #667085;
  display: flex;
  min-height: 100vh;
  min-height: 100dvh;
  background:
    radial-gradient(1200px 420px at 12% -8%, rgba(91, 141, 239, 0.14), transparent 60%),
    radial-gradient(900px 360px at 100% 0%, rgba(255, 181, 71, 0.1), transparent 55%),
    var(--parent-surface);
  color: var(--parent-ink);
}

.sidebar {
  width: var(--parent-sidebar);
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: linear-gradient(180deg, #171b45 0%, #23275f 100%);
  color: var(--gp-text-light);
  padding: 20px 14px 16px;
  border-right: 1px solid rgba(255, 255, 255, 0.06);
  position: sticky;
  top: 0;
  height: 100vh;
  height: 100dvh;
  overflow: auto;
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 10px 18px;
}
.brand-mark {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  background: rgba(255, 255, 255, 0.1);
  font-size: 22px;
}
.brand-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.brand-copy strong {
  font-size: 17px;
  font-weight: 700;
  letter-spacing: 0.01em;
}
.brand-copy small {
  color: rgba(255, 255, 255, 0.62);
  font-size: 12px;
  line-height: 1.3;
}

.side-nav {
  display: flex;
  flex-direction: column;
  gap: 16px;
  flex: 1;
}
.nav-group {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.nav-label {
  margin: 0 12px 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.42);
}

.side-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 12px;
  border-radius: 12px;
  color: rgba(255, 255, 255, 0.78);
  text-decoration: none;
  min-height: 44px;
  transition: background 160ms ease, color 160ms ease;
  cursor: pointer;
}
.side-link .icon {
  width: 1.4em;
  text-align: center;
  flex-shrink: 0;
}
.side-label {
  white-space: pre-line;
  line-height: 1.25;
}
.side-link:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}
.side-link.active {
  background: rgba(255, 181, 71, 0.18);
  color: #fff;
  box-shadow: inset 3px 0 0 var(--gp-sun);
}

.side-foot {
  padding: 12px 8px 4px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  margin-top: 8px;
}
.side-cta {
  width: 100%;
  min-height: 44px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  transition: background 160ms ease, border-color 160ms ease;
}
.side-cta:hover {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.28);
}

.main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px var(--parent-gutter);
  background: rgba(255, 255, 255, 0.86);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--parent-line);
  position: sticky;
  top: 0;
  z-index: 10;
}
.topbar-title {
  min-width: 0;
}
.eyebrow {
  margin: 0 0 2px;
  font-size: 12px;
  font-weight: 600;
  color: var(--parent-muted);
  letter-spacing: 0.04em;
}
.topbar h1 {
  margin: 0;
  font-size: clamp(20px, 1.4vw + 14px, 28px);
  font-weight: 700;
  white-space: pre-line;
  line-height: 1.25;
  letter-spacing: -0.02em;
}
.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.avatar-btn {
  width: 40px;
  height: 40px;
  border-radius: 999px;
  border: 1px solid var(--parent-line);
  background: #eef1f8;
  color: var(--parent-ink);
  font-weight: 700;
  cursor: pointer;
}

.content {
  padding: 28px var(--parent-gutter) 48px;
  flex: 1;
}
.content-frame {
  width: min(100%, var(--parent-content-max));
  margin: 0 auto;
}

.phone .topbar {
  padding: calc(10px + env(safe-area-inset-top)) 16px 10px;
  backdrop-filter: none;
  background: #fff;
}
.phone .eyebrow {
  display: none;
}
.phone .topbar h1 {
  font-size: 18px;
}
.phone .content {
  padding: 16px 14px calc(88px + env(safe-area-inset-bottom));
}
.phone .content-frame {
  width: 100%;
}

.bottom-nav {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow-x: auto;
  background: #fff;
  border-top: 1px solid var(--parent-line);
  padding-bottom: env(safe-area-inset-bottom);
  z-index: 20;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.bottom-nav::-webkit-scrollbar {
  display: none;
}
.bottom-link {
  flex: 1 0 64px;
  min-width: 64px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-height: 56px;
  font-size: 10px;
  color: #6b6f80;
  text-decoration: none;
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 0 4px;
}
.bottom-link .label {
  white-space: pre-line;
  line-height: 1.15;
  text-align: center;
}
.bottom-link .icon {
  font-size: 20px;
}
.bottom-link.active {
  color: var(--el-color-primary);
  font-weight: 600;
}

.more-sheet {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 25;
  display: flex;
  align-items: flex-end;
}
.more-panel {
  width: 100%;
  background: #fff;
  border-radius: 16px 16px 0 0;
  padding: 12px 12px calc(12px + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.more-item {
  min-height: 48px;
  border: none;
  background: #f5f6fa;
  border-radius: 12px;
  font-size: 16px;
  text-align: left;
  padding: 0 16px;
  cursor: pointer;
}

@media (min-width: 768px) and (max-width: 1023px) {
  .parent {
    --parent-sidebar: 220px;
    --parent-gutter: 20px;
    --parent-content-max: 100%;
  }
  .brand-copy small {
    display: none;
  }
}

@media (min-width: 1440px) {
  .parent {
    --parent-sidebar: 280px;
    --parent-content-max: 1600px;
  }
}

@media (orientation: landscape) {
  .phone .content {
    padding: 12px 12px calc(64px + env(safe-area-inset-bottom));
  }
  .bottom-link {
    min-height: 44px;
    flex-basis: 56px;
    min-width: 56px;
  }
  .bottom-link .label {
    font-size: 9px;
  }
  .more-sheet {
    align-items: center;
    padding: 8px 12px;
  }
  .more-panel {
    max-height: min(88vh, 420px);
    overflow-y: auto;
    border-radius: 16px;
    padding: 12px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .side-link,
  .side-cta {
    transition: none;
  }
}
</style>
