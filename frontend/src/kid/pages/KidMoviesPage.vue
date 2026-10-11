<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError, api } from '@/shared/api/client'
import type { KidCatalogItem, KidMovie, KidScreenTimeSummary, MovieViewing, MovieViewingUpdate } from '@/shared/api/types'
import MoviePoster from '@/shared/MoviePoster.vue'
import { overviewParagraphs } from '@/shared/movieOverview'
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
const drafts = ref<Record<string, number>>({})
const sheetError = ref('')
const rewardNote = ref('')
const saving = ref(false)
const persistChain = ref(Promise.resolve())

const detailMovie = computed(() => selected.value?.movie ?? null)
const introParagraphs = computed(() => overviewParagraphs(detailMovie.value?.overview))

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
  const opening = !expanded.value[key]
  expanded.value = opening ? { [key]: true } : {}
  persistExpanded()
  tap()
}

function expandSeries(key: string): void {
  if (expanded.value[key]) return
  toggle(key)
}

function onCatalogClick(row: KidCatalogItem, event: MouseEvent): void {
  if (row.kind !== 'collection') return
  const target = event.target
  if (target instanceof Element && target.closest('button, a')) return
  expandSeries(`cat-${row.collection.id}`)
}

function collapseExpanded(): void {
  if (Object.keys(expanded.value).length === 0) return
  expanded.value = {}
  persistExpanded()
}

function onDocumentClick(event: MouseEvent): void {
  const target = event.target
  if (target instanceof Element && target.closest('[data-series-keep]')) return
  collapseExpanded()
}

function openMovie(movie: KidMovie, order: number | null = null): void {
  // Opening a movie from another card collapses the series; filmstrip members keep it open.
  if (order === null) collapseExpanded()
  tap()
  selected.value = { movie, order }
}

function closeMovie(): void {
  selected.value = null
  drafts.value = {}
  sheetError.value = ''
  rewardNote.value = ''
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

function latestViewing(movie: KidMovie): MovieViewing | undefined {
  const rows = movie.library?.viewings
  if (!rows?.length) return undefined
  return rows[rows.length - 1]
}

function legacyPercent(movie: KidMovie): number {
  if (watchStatus(movie) === 'COMPLETED') return 100
  const runtime = movie.runtime_minutes
  const seconds = movie.library?.progress?.position_seconds ?? 0
  if (!runtime || runtime <= 0) return 0
  return Math.min(100, Math.round((seconds / (runtime * 60)) * 100))
}

function watchPercent(movie: KidMovie): number {
  return latestViewing(movie)?.position_percent ?? legacyPercent(movie)
}

function watchLabel(movie: KidMovie): string {
  const latest = latestViewing(movie)
  if (latest) {
    const label = latest.status === 'COMPLETED'
      ? t('common.watchCompleted')
      : latest.position_percent > 0
        ? t('common.watchInProgress')
        : t('common.watchNotStarted')
    return latest.sequence > 1 ? `${t('common.watchPass', { n: latest.sequence })} · ${label}` : label
  }
  const status = watchStatus(movie)
  if (status === 'COMPLETED') return t('common.watchCompleted')
  if (status === 'WATCHING' || (movie.library?.progress?.position_seconds ?? 0) > 0) return t('common.watchInProgress')
  return t('common.watchNotStarted')
}

const editorViewings = computed((): MovieViewing[] => {
  const movie = detailMovie.value
  if (!movie) return []
  const rows = movie.library?.viewings
  if (rows && rows.length > 0) return rows
  const status = watchStatus(movie)
  return [{
    id: 0,
    sequence: 1,
    language: 'zh',
    position_percent: legacyPercent(movie),
    status,
    reward_eligible: status !== 'COMPLETED',
    rewarded: false,
  }]
})

function draftKey(viewing: MovieViewing): string {
  return viewing.id ? String(viewing.id) : `new-${viewing.sequence}`
}

function shownPercent(viewing: MovieViewing): number {
  const drafted = drafts.value[draftKey(viewing)]
  return drafted ?? viewing.position_percent
}

function patchMovie(movie: KidMovie, viewings: MovieViewing[]): KidMovie {
  if (!movie.library) return movie
  const latest = viewings[viewings.length - 1]
  const total = movie.runtime_minutes ?? 0
  const position = latest && total > 0
    ? Math.round((latest.position_percent / 100) * total * 60)
    : (movie.library.progress?.position_seconds ?? 0)
  return {
    ...movie,
    library: {
      ...movie.library,
      viewings,
      progress: {
        position_seconds: position,
        status: latest?.status ?? movie.library.progress?.status ?? 'NOT_STARTED',
      },
    },
  }
}

function applyViewings(movieId: number, viewings: MovieViewing[]): void {
  catalog.value = catalog.value.map((row) => {
    if (row.kind === 'movie' && row.movie.id === movieId) {
      return { ...row, movie: patchMovie(row.movie, viewings) }
    }
    if (row.kind === 'collection') {
      return { ...row, movies: row.movies.map((movie) => (movie.id === movieId ? patchMovie(movie, viewings) : movie)) }
    }
    return row
  })
  if (selected.value?.movie.id === movieId) {
    selected.value = { ...selected.value, movie: patchMovie(selected.value.movie, viewings) }
  }
}

function onSlide(viewing: MovieViewing, event: Event): void {
  if (viewing.rewarded) return
  const value = Number((event.target as HTMLInputElement).value)
  drafts.value = { ...drafts.value, [draftKey(viewing)]: value }
}

function enqueueSave<T>(work: () => Promise<T>): Promise<T> {
  const run = async (): Promise<T> => {
    saving.value = true
    try {
      return await work()
    } finally {
      saving.value = false
    }
  }
  const next = persistChain.value.then(run, run)
  persistChain.value = next.then(() => undefined, () => undefined)
  return next
}

async function persistViewing(viewing: MovieViewing, patch: { position_percent: number; language?: 'zh' | 'en' }): Promise<boolean> {
  const movie = detailMovie.value
  const libraryId = movie?.library?.id
  if (!movie || !libraryId) return false
  const key = draftKey(viewing)

  return enqueueSave(async () => {
    sheetError.value = ''
    try {
      const updated = await api.put<MovieViewingUpdate>(`/kid/movie-library/${libraryId}/viewings`, {
        viewing_id: viewing.id || undefined,
        position_percent: patch.position_percent,
        language: patch.language ?? viewing.language,
      })
      applyViewings(movie.id, updated.viewings)
      if (drafts.value[key] === patch.position_percent) {
        drafts.value = Object.fromEntries(Object.entries(drafts.value).filter(([entry]) => entry !== key))
      }
      return true
    } catch (e) {
      sheetError.value = e instanceof ApiError ? e.message : t('common.loadFailed')
      return false
    }
  })
}

function onSlideCommit(viewing: MovieViewing, event: Event): void {
  if (viewing.rewarded) return
  const value = Number((event.target as HTMLInputElement).value)
  drafts.value = { ...drafts.value, [draftKey(viewing)]: value }
  void persistViewing(viewing, { position_percent: value })
}

function setLanguage(viewing: MovieViewing, language: 'zh' | 'en'): void {
  if (viewing.rewarded) return
  if (viewing.language === language && viewing.id) return
  tap()
  void persistViewing(viewing, { position_percent: shownPercent(viewing), language })
}

async function addWatch(): Promise<void> {
  const movie = detailMovie.value
  const libraryId = movie?.library?.id
  if (!movie || !libraryId) return
  tap()
  sheetError.value = ''
  const current = editorViewings.value
  const last = current[current.length - 1]
  if (last && !last.id) {
    const saved = await persistViewing(last, { position_percent: shownPercent(last), language: last.language })
    if (!saved) return
  }
  await enqueueSave(async () => {
    try {
      const language = editorViewings.value.at(-1)?.language ?? 'zh'
      const created = await api.post<MovieViewingUpdate>(`/kid/movie-library/${libraryId}/viewings`, { language })
      applyViewings(movie.id, created.viewings)
    } catch (e) {
      sheetError.value = e instanceof ApiError ? e.message : t('common.loadFailed')
    }
  })
}

async function finishWatch(viewing: MovieViewing): Promise<void> {
  const movie = detailMovie.value
  const libraryId = movie?.library?.id
  const percent = shownPercent(viewing)
  if (!movie || !libraryId || percent < 90 || viewing.status === 'COMPLETED') return
  tap()
  sheetError.value = ''
  rewardNote.value = ''
  await enqueueSave(async () => {
    try {
      let viewingId = viewing.id
      if (!viewingId) {
        const saved = await api.put<MovieViewingUpdate>(`/kid/movie-library/${libraryId}/viewings`, {
          position_percent: percent,
          language: viewing.language,
        })
        applyViewings(movie.id, saved.viewings)
        viewingId = saved.viewings[0]?.id ?? 0
      }
      if (!viewingId) return
      const result = await api.post<MovieViewingUpdate>(`/kid/movie-library/${libraryId}/viewings/${viewingId}/complete`, {
        position_percent: percent,
      })
      applyViewings(movie.id, result.viewings)
      const key = draftKey(viewing)
      if (drafts.value[key] === percent) {
        drafts.value = Object.fromEntries(Object.entries(drafts.value).filter(([entry]) => entry !== key))
      }
      if (result.reward) {
        rewardNote.value = t('common.watchRewardClaimed', { coins: result.reward.coins, xp: result.reward.xp })
      }
    } catch (e) {
      sheetError.value = e instanceof ApiError ? e.message : t('common.loadFailed')
    }
  })
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
  document.addEventListener('click', onDocumentClick)
  void load()
})
onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('click', onDocumentClick)
})
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
        <template v-for="row in catalog" :key="row.kind === 'collection' ? `c-${row.collection.id}` : `m-${row.movie.id}`">
          <article
            class="card"
            :class="{ series: row.kind === 'collection' }"
            :data-series-keep="row.kind === 'collection' ? '' : undefined"
            @click="onCatalogClick(row, $event)"
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
              <span class="stack">
                <span class="layer l2" />
                <span class="layer l1" />
                <MoviePoster :title="row.collection.name" :src="kidPoster(row.movies[0])" />
              </span>
              <div class="copy">
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
                <button
                  type="button"
                  class="fold-btn"
                  data-series-keep
                  :aria-expanded="!!expanded[`cat-${row.collection.id}`]"
                  @click="toggle(`cat-${row.collection.id}`)"
                >
                  {{ expanded[`cat-${row.collection.id}`] ? t('common.collapseSeries') : t('common.expandSeries') }}
                </button>
              </div>
            </template>
          </article>
          <div
            v-if="row.kind === 'collection' && expanded[`cat-${row.collection.id}`]"
            class="series-fold"
          >
            <div class="filmstrip">
              <button
                v-for="(movie, order) in row.movies"
                :key="movie.id"
                type="button"
                class="member"
                data-series-keep
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
        </template>
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
        <p v-if="introParagraphs.length === 0" class="overview">{{ t('common.noOverview') }}</p>
        <p
          v-for="(paragraph, index) in introParagraphs"
          :key="index"
          class="overview"
          :lang="index === 0 ? 'zh-CN' : 'en'"
        >{{ paragraph }}</p>
        <div class="watch-block">
          <p class="muted">{{ t('common.watchRewardRule') }}</p>
          <div v-for="viewing in editorViewings" :key="viewing.id || `new-${viewing.sequence}`" class="pass">
            <div class="watch-label">
              <span>{{ t('common.watchPass', { n: viewing.sequence }) }}</span>
              <span>{{ shownPercent(viewing) }}%</span>
            </div>
            <div class="lang-row" role="group" :aria-label="t('common.watchLanguageForPass', { n: viewing.sequence })">
              <span class="lang-hint">{{ t('common.watchLanguageForPass', { n: viewing.sequence }) }}</span>
              <button type="button" class="lang-btn" :disabled="viewing.rewarded" :aria-pressed="viewing.language === 'zh'" @click="setLanguage(viewing, 'zh')">
                {{ t('common.watchLanguageZh') }}
              </button>
              <button type="button" class="lang-btn" :disabled="viewing.rewarded" :aria-pressed="viewing.language === 'en'" @click="setLanguage(viewing, 'en')">
                {{ t('common.watchLanguageEn') }}
              </button>
            </div>
            <div class="slider-wrap">
              <div
                class="track tall"
                aria-hidden="true"
              >
                <div class="fill" :class="{ done: shownPercent(viewing) === 100 }" :style="{ width: `${shownPercent(viewing)}%` }" />
              </div>
              <input
                type="range"
                min="0"
                max="100"
                step="1"
                :value="shownPercent(viewing)"
                :disabled="viewing.rewarded"
                role="slider"
                :aria-valuenow="shownPercent(viewing)"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="t('common.watchPass', { n: viewing.sequence })"
                @input="onSlide(viewing, $event)"
                @change="onSlideCommit(viewing, $event)"
              />
            </div>
            <p v-if="detailMovie.runtime_minutes" class="muted">
              {{ t('common.watchedOfRuntime', { done: Math.round((shownPercent(viewing) / 100) * detailMovie.runtime_minutes), total: detailMovie.runtime_minutes }) }}
            </p>
            <button
              v-if="shownPercent(viewing) >= 90 && viewing.status !== 'COMPLETED'"
              type="button"
              class="claim"
              :disabled="saving"
              @click="finishWatch(viewing)"
            >
              {{ viewing.reward_eligible ? t('common.watchClaimReward') : t('common.watchFinishNoReward') }}
            </button>
            <p v-if="viewing.rewarded || viewing.status === 'COMPLETED'" class="muted">
              {{ viewing.rewarded ? t('common.watchRewardDone') : t('common.watchFinishedEditable') }}
            </p>
          </div>
          <p v-if="rewardNote" class="reward-note">{{ rewardNote }}</p>
          <p v-if="sheetError" class="error">{{ sheetError }}</p>
          <button type="button" class="add-watch" :disabled="saving || !detailMovie.library" @click="addWatch">
            {{ t('common.addWatch') }}
          </button>
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
.card.series { cursor: pointer; }
.poster-hit, .member {
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
.copy { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-width: 0; margin-top: 8px; }
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
.stack { position: relative; display: block; margin: 8px 12px 0 0; }
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
.fold-btn {
  margin-top: 2px;
  padding: 6px 0;
  border: none;
  background: none;
  color: var(--gp-sun, #fbbf24);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  min-height: 36px;
}
.series-fold {
  grid-column: 1 / -1;
  border-radius: 16px;
  padding: 8px 10px;
  background: rgba(255, 255, 255, 0.06);
}
.filmstrip {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 4px 2px;
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
.overview + .overview { margin-top: 8px; }
.overview[lang="en"] { opacity: 0.92; }
.watch-block { display: flex; flex-direction: column; gap: 12px; }
.pass { display: flex; flex-direction: column; gap: 8px; }
.watch-label { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; }
.lang-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.lang-hint {
  grid-column: 1 / -1;
  font-size: 12px;
  opacity: 0.7;
}
.lang-btn {
  min-height: 44px;
  border-radius: 12px;
  border: 2px solid transparent;
  background: rgba(255, 255, 255, 0.12);
  color: inherit;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  outline: none;
}
.lang-btn:focus-visible {
  box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.35);
}
.lang-btn[aria-pressed="true"] {
  border-color: var(--gp-sun);
  background: rgba(251, 191, 36, 0.28);
}
.lang-btn:disabled {
  opacity: 0.55;
  cursor: default;
}
.slider-wrap {
  position: relative;
  height: 32px;
  display: flex;
  align-items: center;
}
.slider-wrap .track {
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  transform: translateY(-50%);
  height: 12px;
  pointer-events: none;
  z-index: 0;
}
.slider-wrap input[type='range'] {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 32px;
  margin: 0;
  background: transparent;
  cursor: pointer;
  touch-action: none;
  -webkit-appearance: none;
  appearance: none;
}
.slider-wrap input[type='range']::-webkit-slider-runnable-track {
  height: 12px;
  background: transparent;
  border: none;
}
.slider-wrap input[type='range']::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 22px;
  height: 22px;
  margin-top: -5px;
  border-radius: 50%;
  background: var(--gp-sun);
  border: 2px solid #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
}
.slider-wrap input[type='range']::-moz-range-track {
  height: 12px;
  background: transparent;
  border: none;
}
.slider-wrap input[type='range']::-moz-range-thumb {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--gp-sun);
  border: 2px solid #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
}
.slider-wrap input[type='range']:disabled {
  cursor: default;
}
.slider-wrap input[type='range']:disabled::-webkit-slider-thumb {
  background: rgba(255, 255, 255, 0.55);
}
.slider-wrap input[type='range']:disabled::-moz-range-thumb {
  background: rgba(255, 255, 255, 0.55);
}
.claim, .add-watch {
  min-height: 56px;
  border: none;
  border-radius: 16px;
  font-size: 17px;
  font-weight: 700;
  cursor: pointer;
}
.claim { background: #86efac; color: #14532d; }
.add-watch { background: rgba(255, 255, 255, 0.14); color: inherit; }
.claim:disabled, .add-watch:disabled { opacity: 0.6; cursor: default; }
.reward-note { margin: 0; color: #86efac; font-weight: 700; }
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
