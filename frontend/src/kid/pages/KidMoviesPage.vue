<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidCatalogItem, KidMovie, KidScreenTimeSummary } from '@/shared/api/types'
import MoviePoster from '@/shared/MoviePoster.vue'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const EXPAND_KEY = 'gp.kid.movies.expanded'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const catalog = ref<KidCatalogItem[]>([])
const screenTime = ref<KidScreenTimeSummary | null>(null)
const loading = ref(true)
const error = ref('')
const expanded = ref<Record<string, boolean>>(loadExpanded())
const selected = ref<{ movie: KidMovie; order: number | null } | null>(null)

const detailMovie = computed(() => selected.value?.movie ?? null)

function loadExpanded(): Record<string, boolean> {
  try {
    const raw = sessionStorage.getItem(EXPAND_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as Record<string, boolean>
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

function persistExpanded(): void {
  sessionStorage.setItem(EXPAND_KEY, JSON.stringify(expanded.value))
}

function tap(): void {
  if (session.me?.child?.preferences.sounds) playTap()
}

function toggle(key: string): void {
  expanded.value = { ...expanded.value, [key]: !expanded.value[key] }
  persistExpanded()
  tap()
}

function openMovie(movie: KidMovie, order: number | null = null): void {
  tap()
  selected.value = { movie, order }
}

function closeMovie(): void {
  selected.value = null
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') closeMovie()
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [items, st] = await Promise.all([
      api.get<KidCatalogItem[]>('/kid/movies'),
      api.get<KidScreenTimeSummary>('/kid/screen-time'),
    ])
    catalog.value = items
    screenTime.value = st
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.loadFailed')
  } finally {
    loading.value = false
  }
}

function kidPoster(movie: { id: number; poster_key?: string | null } | undefined): string {
  return movie?.poster_key ? `/api/v1/kid/movies/${movie.id}/poster` : ''
}

function mins(seconds: number): number {
  return Math.round(seconds / 60)
}

function watchStatus(movie: KidMovie): string {
  return movie.library?.progress?.status || 'NOT_STARTED'
}

function watchPercent(movie: KidMovie): number {
  if (watchStatus(movie) === 'COMPLETED') return 100
  const runtime = movie.runtime_minutes
  const seconds = movie.library?.progress?.position_seconds ?? 0
  if (!runtime || runtime <= 0) return 0
  return Math.min(100, Math.round((seconds / (runtime * 60)) * 100))
}

function watchLabel(movie: KidMovie): string {
  const status = watchStatus(movie)
  if (status === 'COMPLETED') return t('common.watchCompleted')
  if (status === 'WATCHING' || (movie.library?.progress?.position_seconds ?? 0) > 0) return t('common.watchInProgress')
  return t('common.watchNotStarted')
}

function watchedLine(movie: KidMovie): string {
  const total = movie.runtime_minutes
  const done = watchStatus(movie) === 'COMPLETED' && total
    ? total
    : Math.floor((movie.library?.progress?.position_seconds ?? 0) / 60)
  if (total) return t('common.watchedOfRuntime', { done: Math.min(done, total), total })
  if (done > 0) return t('common.watchedMinutesOnly', { done })
  return ''
}

function metaLine(movie: KidMovie): string {
  const bits: string[] = []
  if (movie.release_year) bits.push(String(movie.release_year))
  if (movie.runtime_minutes) bits.push(`${movie.runtime_minutes} ${t('common.minutes')}`)
  return bits.join(' · ')
}

function seriesPercent(ratio: number): number {
  return Math.min(100, Math.max(0, Math.round(ratio * 100)))
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  void load()
})
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.movies') }}</h1>
    </header>
    <p v-if="error" class="error">{{ error }}</p>
    <section v-if="screenTime" class="quota">
      <p>
        {{
          t('common.weeklyMovieQuota', {
            used: mins(screenTime.movie.used_seconds),
            limit: mins(screenTime.movie.limit_seconds),
          })
        }}
      </p>
    </section>
    <section v-if="!loading">
      <h2>{{ t('common.myLibrary') }}</h2>
      <p v-if="catalog.length === 0" class="muted">{{ t('common.kidRecommendationsEmpty') }}</p>
      <div v-else class="poster-grid">
        <article
          v-for="row in catalog"
          :key="row.kind === 'collection' ? `c-${row.collection.id}` : `m-${row.movie.id}`"
          class="card"
          :class="{ series: row.kind === 'collection', open: row.kind === 'collection' && expanded[`cat-${row.collection.id}`] }"
        >
          <template v-if="row.kind === 'movie'">
            <button type="button" class="poster-hit" :aria-label="row.movie.title_zh" @click="openMovie(row.movie)">
              <span class="poster-wrap">
                <MoviePoster :title="row.movie.title_zh" :src="kidPoster(row.movie)" />
                <span
                  class="poster-bar"
                  role="progressbar"
                  :aria-valuenow="watchPercent(row.movie)"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  :aria-label="watchLabel(row.movie)"
                >
                  <span class="fill" :class="{ done: watchPercent(row.movie) === 100 }" :style="{ width: `${watchPercent(row.movie)}%` }" />
                </span>
              </span>
              <span class="copy">
                <strong>{{ row.movie.title_zh }}</strong>
                <span class="muted">{{ watchLabel(row.movie) }}</span>
              </span>
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              class="series-toggle"
              :aria-expanded="!!expanded[`cat-${row.collection.id}`]"
              @click="toggle(`cat-${row.collection.id}`)"
            >
              <span class="stack">
                <span class="layer l2" />
                <span class="layer l1" />
                <MoviePoster :title="row.collection.name" :src="kidPoster(row.movies[0])" />
              </span>
              <span class="copy">
                <strong>{{ row.collection.name }}</strong>
                <span class="muted">
                  {{
                    t('common.seriesProgress', {
                      done: row.collection.completed_count,
                      total: row.collection.visible_count,
                    })
                  }}
                </span>
                <span
                  class="track"
                  role="progressbar"
                  :aria-valuenow="seriesPercent(row.collection.progress_ratio)"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  :aria-label="t('common.seriesProgress', { done: row.collection.completed_count, total: row.collection.visible_count })"
                >
                  <span class="fill" :class="{ done: seriesPercent(row.collection.progress_ratio) === 100 }" :style="{ width: `${seriesPercent(row.collection.progress_ratio)}%` }" />
                </span>
                <span class="fold-hint">{{ expanded[`cat-${row.collection.id}`] ? t('common.collapseSeries') : t('common.expandSeries') }}</span>
              </span>
              <span class="chevron" aria-hidden="true" />
            </button>
            <div
              class="fold"
              :class="{ open: expanded[`cat-${row.collection.id}`] }"
              :inert="!expanded[`cat-${row.collection.id}`]"
            >
              <div class="fold-clip">
                <div class="filmstrip">
                  <button
                    v-for="(movie, order) in row.movies"
                    :key="movie.id"
                    type="button"
                    class="member"
                    @click="openMovie(movie, order + 1)"
                  >
                    <span class="poster-wrap">
                      <MoviePoster :title="movie.title_zh" :src="kidPoster(movie)" />
                      <span class="order">{{ order + 1 }}</span>
                      <span
                        class="poster-bar"
                        role="progressbar"
                        :aria-valuenow="watchPercent(movie)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="watchLabel(movie)"
                      >
                        <span class="fill" :class="{ done: watchPercent(movie) === 100 }" :style="{ width: `${watchPercent(movie)}%` }" />
                      </span>
                    </span>
                    <strong>{{ movie.title_zh }}</strong>
                    <span class="muted">{{ watchLabel(movie) }}</span>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </article>
      </div>
    </section>

    <div v-if="detailMovie" class="sheet-mask" @click.self="closeMovie">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="detailMovie.title_zh">
        <div class="sheet-head">
          <div class="sheet-poster">
            <MoviePoster :title="detailMovie.title_zh" :src="kidPoster(detailMovie)" />
          </div>
          <div class="sheet-titles">
            <h2>{{ detailMovie.title_zh }}</h2>
            <p
              v-if="detailMovie.original_title && detailMovie.original_title !== detailMovie.title_zh"
              class="muted"
            >
              {{ detailMovie.original_title }}
            </p>
            <p v-if="metaLine(detailMovie)" class="muted">{{ metaLine(detailMovie) }}</p>
            <p v-if="selected?.order" class="muted">{{ t('common.seriesPart', { n: selected.order }) }}</p>
          </div>
        </div>
        <h3>{{ t('common.movieIntro') }}</h3>
        <p class="overview">{{ detailMovie.overview?.trim() || t('common.noOverview') }}</p>
        <div class="watch-block">
          <div class="watch-label">
            <span>{{ watchLabel(detailMovie) }}</span>
            <span>{{ watchPercent(detailMovie) }}%</span>
          </div>
          <div
            class="track tall"
            role="progressbar"
            :aria-valuenow="watchPercent(detailMovie)"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="watchLabel(detailMovie)"
          >
            <div class="fill" :class="{ done: watchPercent(detailMovie) === 100 }" :style="{ width: `${watchPercent(detailMovie)}%` }" />
          </div>
          <p v-if="watchedLine(detailMovie)" class="muted">{{ watchedLine(detailMovie) }}</p>
        </div>
        <button type="button" class="close" @click="closeMovie">{{ t('common.back') }}</button>
      </div>
    </div>
  </main>
</template>

<style scoped>
.page { padding: 0 16px 32px; max-width: 960px; margin: 0 auto; }
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.back { background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; min-height: 44px; }
.quota { background: rgba(255,255,255,0.08); padding: 12px; border-radius: 12px; margin-bottom: 16px; }
.quota p { margin: 0; }
h2 { margin: 18px 0 10px; font-size: 20px; }
h3 { margin: 0; font-size: 15px; }
.poster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
  gap: 14px;
}
.card {
  background: rgba(255,255,255,0.06);
  border-radius: 16px;
  padding: 10px;
  min-width: 0;
}
.card.series.open { grid-column: 1 / -1; }
.poster-hit, .series-toggle, .member {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
  width: 100%;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  text-align: left;
  cursor: pointer;
}
.series-toggle { position: relative; padding-right: 18px; }
.series.open .series-toggle { flex-direction: row; align-items: center; gap: 12px; }
.series.open .stack { width: 84px; flex: none; }
.copy { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-width: 0; }
.copy strong, .member strong { line-height: 1.3; }
.member strong { font-size: 13px; }
.muted { opacity: 0.75; margin: 0; font-size: 13px; }
.error { color: #ffb4b4; }
.poster-wrap { position: relative; display: block; }
.poster-bar, .track {
  height: 6px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.35);
  overflow: hidden;
}
.poster-bar {
  position: absolute;
  left: 8px;
  right: 8px;
  bottom: 8px;
  z-index: 2;
}
.track { width: 100%; background: rgba(255, 255, 255, 0.16); }
.track.tall { height: 12px; }
.fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--gp-sun);
}
.fill.done { background: #86efac; }
.stack { position: relative; margin: 8px 12px 0 0; }
.layer {
  position: absolute;
  inset: 0;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.18);
}
.l2 { transform: translate(10px, -8px); }
.l1 { transform: translate(5px, -4px); background: rgba(255, 255, 255, 0.24); }
.stack :deep(.poster) { position: relative; z-index: 1; }
.chevron {
  position: absolute;
  right: 2px;
  top: 18px;
  width: 9px;
  height: 9px;
  border-right: 2px solid currentColor;
  border-bottom: 2px solid currentColor;
  transform: rotate(45deg);
  transition: transform 0.2s ease;
}
.series.open .chevron { top: 50%; transform: translateY(-60%) rotate(225deg); }
.fold-hint { font-size: 12px; opacity: 0.7; }
.fold {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.28s ease;
}
.fold.open { grid-template-rows: 1fr; }
.fold-clip { overflow: hidden; }
.filmstrip {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 12px 2px 4px;
  scroll-snap-type: x mandatory;
}
.member { flex: 0 0 112px; scroll-snap-align: start; width: 112px; }
.order {
  position: absolute;
  left: 6px;
  top: 6px;
  z-index: 2;
  display: inline-flex;
  min-width: 22px;
  height: 22px;
  align-items: center;
  justify-content: center;
  padding: 0 6px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 12px;
}
.sheet-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 60;
}
.sheet {
  width: min(520px, 100%);
  max-height: min(88vh, 720px);
  overflow: auto;
  background: #262a66;
  color: #fff;
  border-radius: 24px 24px 0 0;
  padding: 20px 20px calc(20px + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sheet-head { display: flex; gap: 14px; align-items: flex-start; }
.sheet-poster { width: 104px; flex: none; }
.sheet-titles { min-width: 0; }
.sheet h2 { margin: 0 0 6px; font-size: 22px; line-height: 1.3; }
.overview { margin: 0; line-height: 1.6; white-space: pre-wrap; }
.watch-block { display: flex; flex-direction: column; gap: 8px; }
.watch-label { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; }
.close {
  min-height: 56px;
  border: none;
  border-radius: 16px;
  background: var(--gp-sun);
  color: var(--gp-space-dark);
  font-size: 17px;
  font-weight: 700;
  cursor: pointer;
}
</style>
