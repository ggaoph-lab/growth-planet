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

const primaryNav = computed(() => [
  { name: 'parent-dashboard', label: t('parent.nav.dashboard'), icon: '🏠' },
  { name: 'parent-approvals', label: t('parent.nav.approvals'), icon: '✅' },
  { name: 'parent-tasks', label: t('parent.nav.tasks'), icon: '📋' },
  { name: 'parent-habits', label: t('parent.nav.habits'), icon: '🌱' },
  { name: 'parent-shop', label: t('parent.nav.shop'), icon: '🎁' },
  { name: 'parent-life', label: t('parent.nav.life'), icon: '🎒' },
  { name: 'parent-media', label: t('parent.nav.media'), icon: '🎬' },
  { name: 'parent-children', label: t('parent.nav.children'), icon: '🧒' },
])

const moreNav = computed(() => [
  { name: 'parent-reports', label: t('parent.nav.reports'), icon: '📊' },
  { name: 'parent-devices', label: t('parent.nav.devices'), icon: '📱' },
  { name: 'parent-audit', label: t('parent.nav.audit'), icon: '📜' },
  { name: 'parent-family', label: t('parent.nav.family'), icon: '⚙️' },
])

const sidebarNav = computed(() => [...primaryNav.value, ...moreNav.value])

const phoneNav = computed(() => primaryNav.value.slice(0, 5))

function isActive(name: string): boolean {
  return route.name === name
}

function isMoreActive(): boolean {
  return moreNav.value.some((item) => item.name === route.name)
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
      <aside v-if="!isPhone" class="sidebar">
        <div class="brand">🪐 {{ t('app.name') }}</div>
        <nav>
          <RouterLink
            v-for="item in sidebarNav"
            :key="item.name"
            :to="{ name: item.name }"
            class="side-link"
            :class="{ active: isActive(item.name) }"
          >
            <span class="icon">{{ item.icon }}</span>{{ item.label }}
          </RouterLink>
        </nav>
      </aside>

      <div class="main">
        <header class="topbar">
          <h1>{{ pageTitle }}</h1>
          <div class="actions">
            <LanguageSwitch />
            <el-button size="small" round @click="toKidMode">{{ t('parent.nav.kidMode') }}</el-button>
            <el-dropdown trigger="click">
              <el-button size="small" circle>{{ session.me?.user.display_name.slice(0, 1) }}</el-button>
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
          <RouterView />
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
  display: flex;
  min-height: 100vh;
  background: #f5f6fa;
  color: #1f2333;
}
.sidebar {
  width: 220px;
  flex-shrink: 0;
  background: var(--gp-space-dark);
  color: var(--gp-text-light);
  padding: 20px 12px;
}
.brand {
  font-size: 20px;
  font-weight: 700;
  padding: 0 12px 20px;
}
.side-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  border-radius: 10px;
  color: rgba(255, 255, 255, 0.8);
  text-decoration: none;
  min-height: 44px;
}
.side-link.active,
.side-link:hover {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
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
  gap: 12px;
  padding: 14px 24px;
  background: #fff;
  border-bottom: 1px solid #e8e9f0;
  position: sticky;
  top: 0;
  z-index: 10;
}
.topbar h1 {
  margin: 0;
  font-size: 18px;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.content {
  padding: 24px;
  flex: 1;
}
.phone .topbar {
  padding: calc(10px + env(safe-area-inset-top)) 16px 10px;
}
.phone .content {
  padding: 16px 12px calc(88px + env(safe-area-inset-bottom));
}
.bottom-nav {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow-x: auto;
  background: #fff;
  border-top: 1px solid #e8e9f0;
  padding-bottom: env(safe-area-inset-bottom);
  z-index: 20;
  -webkit-overflow-scrolling: touch;
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
</style>
