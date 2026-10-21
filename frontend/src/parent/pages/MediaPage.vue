<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import MoviePoster from '@/shared/MoviePoster.vue'
import { overviewParagraphs } from '@/shared/movieOverview'
import type {
  EnglishContentDraft,
  EnglishLabPath,
  EnglishLabProgress,
  EnglishLabSummary,
  EnglishShow,
  EnglishSpeakAttempt,
  EnglishWatchLog,
  ParentCatalogItem,
  ParentChild,
  ParentMovie,
  MovieSuggestion,
  ParentMovieCollection,
  ScreenTimePolicyRow,
  TmdbMovieHit,
} from '@/shared/api/types'
import { useIsPhone } from '@/shared/useMediaQuery'

const EXPAND_KEY = 'gp.parent.movies.expanded'

const { t } = useI18n()
const isPhone = useIsPhone()
const tab = ref<'movies' | 'english' | 'screen'>('movies')
const children = ref<ParentChild[]>([])
const childId = ref<number | undefined>()
const loading = ref(true)

const movies = ref<ParentMovie[]>([])
const suggestions = ref<MovieSuggestion[]>([])
const catalog = ref<ParentCatalogItem[]>([])
const collections = ref<ParentMovieCollection[]>([])
const shows = ref<EnglishShow[]>([])
const englishLogs = ref<EnglishWatchLog[]>([])
const labProgress = ref<EnglishLabProgress | null>(null)
const labSummary = ref<EnglishLabSummary | null>(null)
const labDrafts = ref<EnglishContentDraft[]>([])
const labSpeaks = ref<EnglishSpeakAttempt[]>([])
const speakAudioUrl = ref<string | null>(null)
const labBusy = ref(false)
const policies = ref<ScreenTimePolicyRow[]>([])
const expanded = ref<Record<string, boolean>>(loadExpanded())

const showForm = ref({ title: '', emoji: '📺' })
const searchQuery = ref('')
const searchHits = ref<TmdbMovieHit[]>([])
const searching = ref(false)
const searchNote = ref('')
const deleteTarget = ref<
  { kind: 'movie'; movie: ParentMovie } | { kind: 'series'; collection: ParentMovieCollection } | null
>(null)
const deleting = ref(false)

const deleteOpen = computed({
  get: () => deleteTarget.value !== null,
  set: (open: boolean) => {
    if (!open) deleteTarget.value = null
  },
})

const deleteTitle = computed(() =>
  deleteTarget.value?.kind === 'series' ? t('common.deleteSeries') : t('common.deleteMovie'),
)

const deleteBody = computed(() => {
  const target = deleteTarget.value
  if (!target) return ''
  if (target.kind === 'movie') return t('common.confirmDeleteMovie', { title: target.movie.title_zh })
  return t('common.confirmDeleteSeries', { name: target.collection.name })
})

const importedExternalIds = computed(
  () => new Set(movies.value.filter((movie) => movie.source === 'tmdb' && movie.external_id).map((movie) => movie.external_id)),
)

const libraryKeys = computed(() => {
  const keys = new Set<string>()
  for (const movie of movies.value) {
    if (movie.external_id) keys.add(movie.external_id)
    keys.add(normalizeTitle(movie.title_zh))
    if (movie.original_title) keys.add(normalizeTitle(movie.original_title))
  }
  return keys
})

const wallSuggestions = computed(() => suggestions.value.filter((item) => !suggestionOwned(item)))

const wallSourceHint = computed(() => {
  const sources = new Set(wallSuggestions.value.map((item) => item.source))
  if (sources.has('builtin') && sources.has('tmdb')) return t('common.wallSourcesBoth')
  if (sources.has('tmdb')) return t('common.wallSourcesTmdb')
  return t('common.wallSourcesBuiltin')
})

function normalizeTitle(title: string): string {
  return title.replace(/\s+/g, '').toLowerCase()
}

function suggestionOwned(item: MovieSuggestion): boolean {
  if (item.external_id && libraryKeys.value.has(item.external_id)) return true
  if (libraryKeys.value.has(item.key)) return true
  if (libraryKeys.value.has(normalizeTitle(item.title_zh))) return true
  return item.original_title ? libraryKeys.value.has(normalizeTitle(item.original_title)) : false
}

function parentPoster(movie: ParentMovie | undefined): string {
  return movie?.poster_key ? `/api/v1/parent/movies/${movie.id}/poster` : ''
}

const posterDetail = ref<{
  title: string
  originalTitle: string
  overview: string
  meta: string
  poster: string
} | null>(null)

const introParagraphs = computed(() => overviewParagraphs(posterDetail.value?.overview))

const detailOpen = computed({
  get: () => posterDetail.value !== null,
  set: (open: boolean) => {
    if (!open) posterDetail.value = null
  },
})

function movieMeta(year: number | null | undefined, runtime: number | null | undefined): string {
  const bits: string[] = []
  if (year) bits.push(String(year))
  if (runtime) bits.push(`${runtime} ${t('common.minutes')}`)
  return bits.join(' · ')
}

function showPosterDetail(
  title: string,
  originalTitle: string | null | undefined,
  overview: string | null | undefined,
  meta: string,
  poster: string,
): void {
  const english = originalTitle?.trim() ?? ''
  posterDetail.value = {
    title,
    originalTitle: english && english !== title ? english : '',
    overview: overview?.trim() || t('common.noOverview'),
    meta,
    poster,
  }
}

function openParentMovie(movie: ParentMovie, fromSeries = false): void {
  if (!fromSeries) collapseExpanded()
  showPosterDetail(
    movie.title_zh,
    movie.original_title,
    movie.overview,
    movieMeta(movie.release_year, movie.runtime_minutes),
    parentPoster(movie),
  )
}

function openSuggestion(item: MovieSuggestion): void {
  showPosterDetail(
    item.title_zh,
    item.original_title,
    item.overview,
    movieMeta(item.release_year, item.runtime_minutes),
    item.poster_url,
  )
}

function openHit(hit: TmdbMovieHit): void {
  showPosterDetail(hit.title_zh, hit.original_title, hit.overview, movieMeta(hit.release_year, null), '')
}

const tabOptions = computed(() => [
  { label: t('common.moviesTab'), value: 'movies' as const },
  { label: t('common.englishTab'), value: 'english' as const },
  { label: t('common.screenTab'), value: 'screen' as const },
])

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

function toggle(key: string): void {
  const opening = !expanded.value[key]
  expanded.value = opening ? { [key]: true } : {}
  persistExpanded()
}

function expandSeries(key: string): void {
  if (expanded.value[key]) return
  toggle(key)
}

function onCatalogClick(row: ParentCatalogItem, event: MouseEvent): void {
  if (row.kind !== 'collection') return
  const target = event.target
  if (target instanceof Element && target.closest('button, a, .el-button')) return
  expandSeries(`p-${row.collection.id}`)
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

async function loadChildren(): Promise<void> {
  children.value = await api.get<ParentChild[]>('/parent/children')
  childId.value ??= children.value.find((c) => c.status === 'active')?.id
}

async function loadMovies(): Promise<void> {
  const [flat, grouped, cols, picks] = await Promise.all([
    api.get<ParentMovie[]>('/parent/movies'),
    api.get<ParentCatalogItem[]>('/parent/movies/recommendations'),
    api.get<ParentMovieCollection[]>('/parent/movie-collections'),
    api.get<MovieSuggestion[]>('/parent/movies/suggestions'),
  ])
  movies.value = flat
  catalog.value = grouped
  collections.value = cols
  suggestions.value = picks
}

async function loadEnglish(): Promise<void> {
  shows.value = await api.get<EnglishShow[]>('/parent/english/shows')
  englishLogs.value = await api.get<EnglishWatchLog[]>('/parent/english/logs', { child_id: childId.value })
  await loadEnglishLab()
}

async function loadEnglishLab(): Promise<void> {
  if (!childId.value) {
    labProgress.value = null
    labSummary.value = null
    labDrafts.value = []
    labSpeaks.value = []
    return
  }
  const params = { child_id: childId.value }
  const [progress, summary, drafts, speaks] = await Promise.all([
    api.get<EnglishLabProgress>('/parent/english/learning', params),
    api.get<EnglishLabSummary>('/parent/english/learning/summary', params),
    api.get<EnglishContentDraft[]>('/parent/english/learning/drafts', params),
    api.get<EnglishSpeakAttempt[]>('/parent/english/learning/speaks', params),
  ])
  labProgress.value = progress
  labSummary.value = summary
  labDrafts.value = drafts
  labSpeaks.value = speaks
}

function speakAudioSrc(id: number): string {
  return `/api/v1/parent/english/learning/speaks/${id}/audio?child_id=${childId.value}`
}

function playSpeak(id: number): void {
  speakAudioUrl.value = speakAudioSrc(id)
}

function speakStars(score: number | null): string {
  const n = Math.max(0, Math.min(5, score ?? 0))
  return '★'.repeat(n) + '☆'.repeat(5 - n)
}

async function updateLabPath(path: EnglishLabPath): Promise<void> {
  if (!childId.value || labBusy.value) return
  if ((labProgress.value?.path ?? 'standard') === path) return
  labBusy.value = true
  try {
    labProgress.value = await api.put<EnglishLabProgress>('/parent/english/learning/path', {
      child_id: childId.value,
      path,
    })
    await loadEnglishLab()
    ElMessage.success(t('common.saved'))
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    labBusy.value = false
  }
}

async function generateLabDraft(kind: 'grammar' | 'story'): Promise<void> {
  if (!childId.value || labBusy.value) return
  labBusy.value = true
  try {
    await api.post('/parent/english/learning/drafts/generate', { child_id: childId.value, kind })
    await loadEnglishLab()
    ElMessage.success(t('common.saved'))
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    labBusy.value = false
  }
}

async function publishLabDraft(id: number): Promise<void> {
  if (!childId.value || labBusy.value) return
  labBusy.value = true
  try {
    await api.post(`/parent/english/learning/drafts/${id}/publish`, { child_id: childId.value })
    await loadEnglishLab()
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    labBusy.value = false
  }
}

async function rejectLabDraft(id: number): Promise<void> {
  if (!childId.value || labBusy.value) return
  labBusy.value = true
  try {
    await api.post(`/parent/english/learning/drafts/${id}/reject`, { child_id: childId.value })
    await loadEnglishLab()
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    labBusy.value = false
  }
}

function draftPreview(payload: EnglishContentDraft['payload']): string {
  return [payload.prompt || payload.text, payload.question].filter(Boolean).join(' · ')
}

async function loadScreen(): Promise<void> {
  if (!childId.value) return
  policies.value = await api.get<ScreenTimePolicyRow[]>('/parent/screen-time/policies', { child_id: childId.value })
}

async function loadAll(): Promise<void> {
  loading.value = true
  try {
    await loadChildren()
    await Promise.all([loadMovies(), loadEnglish(), loadScreen()])
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError && e.code === 'SUDO_REQUIRED') return
  if (e instanceof ApiError) ElMessage.error(e.message)
}

function askDeleteMovie(movie: ParentMovie): void {
  deleteTarget.value = { kind: 'movie', movie }
}

function askDeleteMovieRow(row: unknown): void {
  askDeleteMovie(row as ParentMovie)
}

function askDeleteSeries(collection: ParentMovieCollection): void {
  deleteTarget.value = { kind: 'series', collection }
}

function askDeleteSeriesRow(row: unknown): void {
  askDeleteSeries(row as ParentMovieCollection)
}

async function confirmDelete(): Promise<void> {
  const target = deleteTarget.value
  if (!target) return
  deleting.value = true
  try {
    if (target.kind === 'movie') {
      await api.delete(`/parent/movies/${target.movie.id}`)
      ElMessage.success(t('common.movieDeleted'))
    } else {
      await api.delete(`/parent/movie-collections/${target.collection.id}`)
      ElMessage.success(t('common.seriesDeleted'))
    }
    deleteTarget.value = null
    await loadMovies()
  } catch (e) {
    reportError(e)
  } finally {
    deleting.value = false
  }
}

async function searchCatalog(): Promise<void> {
  const q = searchQuery.value.trim()
  if (q === '') {
    searchHits.value = []
    searchNote.value = ''
    return
  }
  searching.value = true
  searchNote.value = ''
  try {
    searchHits.value = await api.get<TmdbMovieHit[]>('/parent/movies/external-search', { q })
    if (searchHits.value.length === 0) searchNote.value = t('common.catalogEmpty')
  } catch (e) {
    reportError(e)
  } finally {
    searching.value = false
  }
}

async function addSuggestion(item: MovieSuggestion): Promise<void> {
  if (item.source === 'tmdb' && item.external_id) {
    await importHit({
      external_id: item.external_id,
      title_zh: item.title_zh,
      original_title: item.original_title ?? '',
      release_year: item.release_year,
      overview: item.overview ?? '',
      poster_path: null,
      belongs_to_collection: item.belongs_to_collection,
    })
    return
  }
  try {
    const result = await api.post<{ movies: ParentMovie[]; collection: { name: string } | null }>(
      `/parent/movies/suggestions/${item.key}/adopt`,
      { child_id: childId.value },
    )
    ElMessage.success(
      result.collection
        ? t('common.seriesAdded', { name: result.collection.name, count: result.movies.length })
        : t('common.movieAdded'),
    )
    await loadMovies()
  } catch (e) {
    reportError(e)
  }
}

async function importHit(hit: TmdbMovieHit): Promise<void> {
  try {
    const movie = await api.post<ParentMovie>('/parent/movies', {
      source: 'tmdb',
      external_id: hit.external_id,
      title_zh: hit.title_zh,
      original_title: hit.original_title || null,
      release_year: hit.release_year,
      overview: hit.overview,
      collection_external_id: hit.belongs_to_collection?.external_id,
      collection_name: hit.belongs_to_collection?.name,
    })
    await api.post(`/parent/movies/${movie.id}/reviews`, {
      safety_status: 'APPROVED_SUITABLE',
      min_age: 6,
      reason: t('common.parentEnteredReason'),
    })
    if (childId.value) {
      await api.put(`/parent/movies/${movie.id}/approvals/${childId.value}`, { visible: true })
    }
    ElMessage.success(t('common.movieAdded'))
    await loadMovies()
  } catch (e) {
    reportError(e)
  }
}

async function createShow(): Promise<void> {
  try {
    await api.post('/parent/english/shows', showForm.value)
    showForm.value = { title: '', emoji: '📺' }
    await loadEnglish()
  } catch (e) {
    reportError(e)
  }
}

async function savePolicies(): Promise<void> {
  if (!childId.value) return
  try {
    await api.put('/parent/screen-time/policies', {
      child_id: childId.value,
      policies: policies.value.map((p) => ({
        category: p.category,
        period: p.period,
        minutes: p.minutes,
      })),
    })
    ElMessage.success(t('common.screenPolicySaved'))
  } catch (e) {
    reportError(e)
  }
}

watch(tab, (next) => {
  if (next === 'movies') void loadMovies().catch(reportError)
})

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  void loadAll()
})
onUnmounted(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div>
    <div v-loading="loading" class="page">
      <el-segmented v-model="tab" :options="tabOptions" />

      <el-select v-if="childId" v-model="childId" class="child" :placeholder="t('common.selectChild')" @change="loadAll">
        <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
      </el-select>

      <section v-if="tab === 'movies'">
        <h3 v-if="collections.length" style="margin: 16px 0 8px">{{ t('common.seriesList') }}</h3>
        <el-table v-if="collections.length && !isPhone" :data="collections" style="margin-bottom: 12px">
          <el-table-column prop="name" :label="t('common.seriesName')" />
          <el-table-column :label="t('common.moviesTab')" width="100">
            <template #default="{ row }">{{ row.movie_count }}</template>
          </el-table-column>
          <el-table-column width="120">
            <template #default="{ row }">
              <el-button type="danger" link @click="askDeleteSeriesRow(row)">{{ t('common.deleteSeries') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div v-else-if="collections.length" class="phone-list" style="margin-bottom: 12px">
          <article v-for="row in collections" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.name }}</div>
              <div class="phone-meta">{{ row.movie_count }}</div>
            </div>
            <div class="phone-ops">
              <el-button size="small" type="danger" @click="askDeleteSeriesRow(row)">{{ t('common.deleteSeries') }}</el-button>
            </div>
          </article>
        </div>

        <h3 style="margin: 16px 0 8px">{{ t('common.systemRecommendations') }}</h3>
        <el-form class="search-row" @submit.prevent="searchCatalog">
          <el-input
            v-model="searchQuery"
            :placeholder="t('common.catalogSearchPlaceholder')"
            clearable
            @clear="searchCatalog"
            @keyup.enter="searchCatalog"
          />
          <el-button type="primary" :loading="searching" @click="searchCatalog">{{ t('common.catalogSearch') }}</el-button>
        </el-form>
        <p v-if="searchNote" class="hint">{{ searchNote }}</p>
        <p v-else-if="searchHits.length" class="hint">{{ t('common.tmdbAttribution') }}</p>
        <p v-if="wallSuggestions.length" class="hint">{{ wallSourceHint }}</p>
        <div v-if="wallSuggestions.length" class="poster-grid">
          <article v-for="item in wallSuggestions" :key="item.key" class="poster-card">
            <button type="button" class="poster-hit" :aria-label="item.title_zh" @click="openSuggestion(item)">
              <MoviePoster :title="item.title_zh" :src="item.poster_url" />
            </button>
            <div class="poster-copy">
              <strong>{{ item.title_zh }}</strong>
              <span class="meta title-en">{{ item.original_title || '\u00a0' }}</span>
              <span class="meta">
                {{ item.release_year ?? '—' }}
                <template v-if="item.runtime_minutes"> · {{ item.runtime_minutes }} {{ t('common.minutes') }}</template>
              </span>
              <el-button type="primary" size="small" @click="addSuggestion(item)">
                {{ t('common.addToLibrary') }}
              </el-button>
            </div>
          </article>
        </div>
        <p v-else-if="movies.length" class="hint">{{ t('common.recommendationsInLibrary') }}</p>
        <p v-else class="hint">{{ t('common.noRecommendationCards') }}</p>

        <div v-if="searchHits.length" class="poster-grid">
          <article v-for="hit in searchHits" :key="hit.external_id" class="poster-card">
            <button type="button" class="poster-hit" :aria-label="hit.title_zh" @click="openHit(hit)">
              <MoviePoster :title="hit.title_zh" />
            </button>
            <div class="poster-copy">
              <strong>{{ hit.title_zh }}</strong>
              <span class="meta title-en">{{ hit.original_title || '\u00a0' }}</span>
              <span class="meta">{{ hit.release_year ?? '—' }}</span>
              <el-button
                v-if="!importedExternalIds.has(hit.external_id)"
                type="primary"
                size="small"
                @click="importHit(hit)"
              >
                {{ t('common.addToLibrary') }}
              </el-button>
              <span v-else class="meta">{{ t('common.inLibrary') }}</span>
            </div>
          </article>
        </div>

        <h3 v-if="catalog.length" style="margin: 16px 0 8px">{{ t('common.recommendations') }}</h3>
        <div v-if="catalog.length" class="poster-grid shelf">
          <template v-for="row in catalog" :key="row.kind === 'collection' ? `c-${row.collection.id}` : `m-${row.movie.id}`">
            <article
              class="poster-card"
              :class="{ series: row.kind === 'collection' }"
              :data-series-keep="row.kind === 'collection' ? '' : undefined"
              @click="onCatalogClick(row, $event)"
            >
              <template v-if="row.kind === 'movie'">
                <button type="button" class="poster-hit" :aria-label="row.movie.title_zh" @click="openParentMovie(row.movie)">
                  <MoviePoster :title="row.movie.title_zh" :src="parentPoster(row.movie)" />
                </button>
                <div class="poster-copy">
                  <strong>{{ row.movie.title_zh }}</strong>
                  <span class="meta">{{ row.movie.runtime_minutes ?? '—' }} {{ t('common.minutes') }}</span>
                  <el-button type="danger" link @click="askDeleteMovie(row.movie)">{{ t('common.deleteMovie') }}</el-button>
                </div>
              </template>
              <template v-else>
                <span class="stack">
                  <span class="layer l2" />
                  <span class="layer l1" />
                  <MoviePoster :title="row.collection.name" :src="parentPoster(row.movies[0])" />
                </span>
                <div class="poster-copy">
                  <strong>{{ row.collection.name }}</strong>
                  <span class="meta">{{ t('common.moviesInSeries', { count: row.collection.movie_count }) }}</span>
                  <el-button
                    link
                    type="primary"
                    data-series-keep
                    :aria-expanded="!!expanded[`p-${row.collection.id}`]"
                    @click="toggle(`p-${row.collection.id}`)"
                  >
                    {{ expanded[`p-${row.collection.id}`] ? t('common.collapseSeries') : t('common.expandSeries') }}
                  </el-button>
                  <el-button type="danger" link @click="askDeleteSeries(row.collection)">{{ t('common.deleteSeries') }}</el-button>
                </div>
              </template>
            </article>
            <div
              v-if="row.kind === 'collection' && expanded[`p-${row.collection.id}`]"
              class="series-fold"
            >
              <div class="filmstrip">
                <button
                  v-for="(m, order) in row.movies"
                  :key="m.id"
                  type="button"
                  class="member-card"
                  data-series-keep
                  @click="openParentMovie(m, true)"
                >
                  <MoviePoster :title="m.title_zh" :src="parentPoster(m)" />
                  <strong><span class="order">{{ order + 1 }}</span>{{ m.title_zh }}</strong>
                </button>
              </div>
            </div>
          </template>
        </div>

        <el-table v-if="!isPhone" :data="movies" style="margin-top: 16px">
          <el-table-column prop="title_zh" :label="t('common.movieTitle')" />
          <el-table-column prop="data_completeness" :label="t('common.metadata')" width="120" />
          <el-table-column prop="runtime_minutes" :label="t('common.runtime')" width="80" />
          <el-table-column :label="t('common.deleteMovie')" width="100">
            <template #default="{ row }">
              <el-button type="danger" link @click="askDeleteMovieRow(row)">{{ t('common.deleteMovie') }}</el-button>
            </template>
          </el-table-column>
        </el-table>
      </section>

      <section v-else-if="tab === 'english'">
        <el-card v-if="labProgress" shadow="never" class="lab-card">
          <h3>{{ t('common.englishLabProgress') }}</h3>
          <div class="lab-path">
            <span class="lab-path-label">{{ t('common.englishLabPath') }}</span>
            <el-radio-group
              :model-value="labProgress.path ?? 'standard'"
              :disabled="labBusy || !childId"
              @update:model-value="(value: string | number | boolean | undefined) => updateLabPath(value === 'rich' ? 'rich' : 'standard')"
            >
              <el-radio-button value="standard">{{ t('common.englishLabPathStandard') }}</el-radio-button>
              <el-radio-button value="rich">{{ t('common.englishLabPathRich') }}</el-radio-button>
            </el-radio-group>
          </div>
          <p class="hint">{{ t('common.englishLabPathHint') }}</p>
          <p>
            {{ t('common.englishRemainingToday') }}：{{ labProgress.remaining_today }}
            · {{ t('common.englishAccuracy7d') }}：{{ labProgress.accuracy_7d == null ? '—' : Math.round(labProgress.accuracy_7d * 100) + '%' }}
            · {{ t('common.englishLabBand') }}：{{ labProgress.vocabulary.effective_band ?? labProgress.vocabulary.band }}
            · {{ labProgress.learned }}/{{ labProgress.total }}
          </p>
          <p v-if="labProgress.missed_words.length">{{ t('common.englishMissedWords') }}：{{ labProgress.missed_words.join('、') }}</p>
          <div v-if="labSummary" class="lab-summary">
            <strong>{{ t('common.englishLabSummary') }}</strong>
            <p>{{ labSummary.summary }}</p>
            <p v-if="labSummary.focuses.length">{{ labSummary.focuses.join(' · ') }}</p>
          </div>
        </el-card>
        <el-card shadow="never" class="lab-card" style="margin-top: 12px">
          <h3>{{ t('common.englishSpeakAlong') }}</h3>
          <el-table v-if="!isPhone && labSpeaks.length" :data="labSpeaks" style="margin-top: 8px">
            <el-table-column prop="business_date" :label="t('common.today')" width="120" />
            <el-table-column :label="t('common.movieTitle')">
              <template #default="{ row }">{{ row.target_text }} · {{ row.target_meaning }}</template>
            </el-table-column>
            <el-table-column :label="t('common.englishSpeakStars')" width="120">
              <template #default="{ row }">{{ speakStars(row.score) }}</template>
            </el-table-column>
            <el-table-column prop="feedback" :label="t('common.status')" />
            <el-table-column width="100">
              <template #default="{ row }">
                <el-button type="primary" link @click="playSpeak(row.id)">{{ t('common.englishSpeakPlay') }}</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-else-if="isPhone && labSpeaks.length" class="phone-list" style="margin-top: 8px">
            <article v-for="row in labSpeaks" :key="row.id" class="phone-card">
              <div class="phone-info">
                <div class="phone-title">{{ row.target_text }}</div>
                <div class="phone-meta">{{ row.business_date }} · {{ speakStars(row.score) }} · {{ row.feedback }}</div>
                <el-button size="small" type="primary" @click="playSpeak(row.id)">{{ t('common.englishSpeakPlay') }}</el-button>
              </div>
            </article>
          </div>
          <p v-else class="hint">{{ t('common.englishSpeakEmpty') }}</p>
          <audio v-if="speakAudioUrl" :src="speakAudioUrl" controls autoplay class="speak-audio" />
        </el-card>
        <el-card shadow="never" class="lab-card" style="margin-top: 12px">
          <h3>{{ t('common.englishLabDrafts') }}</h3>
          <div class="lab-actions">
            <el-button :disabled="labBusy || !childId" @click="generateLabDraft('grammar')">{{ t('common.englishGenerateGrammar') }}</el-button>
            <el-button :disabled="labBusy || !childId" @click="generateLabDraft('story')">{{ t('common.englishGenerateStory') }}</el-button>
          </div>
          <el-table v-if="!isPhone && labDrafts.length" :data="labDrafts" style="margin-top: 12px">
            <el-table-column prop="kind" :label="t('common.category')" width="100" />
            <el-table-column prop="status" :label="t('common.status')" width="110" />
            <el-table-column :label="t('common.movieTitle')">
              <template #default="{ row }">{{ draftPreview(row.payload) }}</template>
            </el-table-column>
            <el-table-column width="180">
              <template #default="{ row }">
                <el-button v-if="row.status === 'pending'" type="primary" link :disabled="labBusy" @click="publishLabDraft(row.id)">{{ t('common.englishPublishDraft') }}</el-button>
                <el-button v-if="row.status === 'pending'" type="danger" link :disabled="labBusy" @click="rejectLabDraft(row.id)">{{ t('common.englishRejectDraft') }}</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-else-if="isPhone" class="phone-list" style="margin-top: 12px">
            <article v-for="row in labDrafts" :key="row.id" class="phone-card">
              <div class="phone-info">
                <div class="phone-title">{{ row.kind }} · {{ row.status }}</div>
                <div class="phone-meta">{{ draftPreview(row.payload) }}</div>
                <div v-if="row.status === 'pending'" class="lab-actions">
                  <el-button size="small" type="primary" :disabled="labBusy" @click="publishLabDraft(row.id)">{{ t('common.englishPublishDraft') }}</el-button>
                  <el-button size="small" :disabled="labBusy" @click="rejectLabDraft(row.id)">{{ t('common.englishRejectDraft') }}</el-button>
                </div>
              </div>
            </article>
          </div>
          <p v-else class="hint">{{ t('common.englishNoDrafts') }}</p>
        </el-card>
        <el-card shadow="never" style="margin-top: 12px">
          <el-form inline @submit.prevent="createShow">
            <el-input v-model="showForm.title" :placeholder="t('common.showName')" />
            <el-input v-model="showForm.emoji" style="width: 80px" />
            <el-button type="primary" @click="createShow">{{ t('common.addShow') }}</el-button>
          </el-form>
        </el-card>
        <el-table v-if="!isPhone" :data="shows" style="margin-top: 12px">
          <el-table-column :label="t('common.show')"><template #default="{ row }">{{ row.emoji }} {{ row.title }}</template></el-table-column>
          <el-table-column prop="active" :label="t('common.enabled')" width="80" />
        </el-table>
        <div v-else class="phone-list" style="margin-top: 12px">
          <article v-for="row in shows" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.emoji }} {{ row.title }}</div>
              <div class="phone-meta">{{ row.active ? t('common.enabled') : t('common.disabledLabel') }}</div>
            </div>
          </article>
        </div>
        <h3>{{ t('common.logRecords') }}</h3>
        <el-table v-if="!isPhone" :data="englishLogs">
          <el-table-column prop="watch_date" :label="t('common.today')" width="120" />
          <el-table-column prop="minutes" :label="t('common.minutes')" width="80" />
          <el-table-column prop="status" :label="t('common.status')" />
        </el-table>
        <div v-else class="phone-list">
          <article v-for="row in englishLogs" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.watch_date }}</div>
              <div class="phone-meta">{{ row.minutes }} {{ t('common.minutes') }} · {{ row.status }}</div>
            </div>
          </article>
        </div>
        <p class="hint">{{ t('common.englishLogsHint') }}</p>
      </section>

      <section v-else>
        <el-table v-if="!isPhone" :data="policies">
          <el-table-column prop="category" :label="t('common.category')" />
          <el-table-column prop="period" :label="t('common.period')" width="100" />
          <el-table-column :label="t('common.minutesLimit')">
            <template #default="{ row }">
              <el-input-number v-model="row.minutes" :min="0" :step="5" size="small" />
            </template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <article v-for="row in policies" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.category }}</div>
              <div class="phone-meta">{{ row.period }}</div>
              <el-input-number v-model="row.minutes" :min="0" :step="5" size="small" />
            </div>
          </article>
        </div>
        <el-button type="primary" style="margin-top: 12px" @click="savePolicies">{{ t('common.savePolicy') }}</el-button>
      </section>
    </div>

    <el-dialog
      v-model="detailOpen"
      :title="posterDetail?.title"
      width="min(480px, 92vw)"
      align-center
      append-to-body
    >
      <div v-if="posterDetail" class="detail">
        <MoviePoster v-if="posterDetail.poster" class="detail-poster" :title="posterDetail.title" :src="posterDetail.poster" />
        <div>
          <p v-if="posterDetail.originalTitle" class="meta title-en">{{ posterDetail.originalTitle }}</p>
          <p v-if="posterDetail.meta" class="meta">{{ posterDetail.meta }}</p>
          <h4>{{ t('common.movieIntro') }}</h4>
          <p v-if="introParagraphs.length === 0" class="overview">{{ t('common.noOverview') }}</p>
          <p
            v-for="(paragraph, index) in introParagraphs"
            :key="index"
            class="overview"
            :lang="index === 0 ? 'zh-CN' : 'en'"
          >{{ paragraph }}</p>
        </div>
      </div>
    </el-dialog>

    <el-dialog
      v-model="deleteOpen"
      :title="deleteTitle"
      width="min(420px, 92vw)"
      align-center
      append-to-body
      :close-on-click-modal="false"
    >
      <p class="delete-body">{{ deleteBody }}</p>
      <template #footer>
        <el-button @click="deleteOpen = false">{{ t('common.cancel') }}</el-button>
        <el-button type="danger" :loading="deleting" @click="confirmDelete">{{ t('common.delete') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 12px; }
.child { max-width: 220px; }
.hint { color: #888; font-size: 13px; }
.lab-card h3 { margin: 0 0 8px; }
.lab-path { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 4px 0 6px; }
.lab-path-label { font-size: 14px; color: #606266; }
.lab-summary { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
.lab-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.speak-audio { display: block; width: 100%; margin-top: 12px; }
.search-row { display: flex; gap: 8px; flex-wrap: wrap; }
.search-row .el-input { flex: 1; min-width: 200px; }
.poster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(132px, 1fr));
  gap: 12px;
}
@media (min-width: 768px) {
  .poster-grid {
    grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    gap: 14px;
  }
}
@media (orientation: landscape) {
  .poster-grid {
    grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
    gap: 10px;
  }
}
.phone-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.phone-card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--el-border-color-lighter);
}
.phone-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.phone-title {
  font-weight: 600;
  font-size: 15px;
}
.phone-meta {
  color: #6b6f80;
  font-size: 13px;
}
.phone-ops {
  width: 100%;
  display: flex;
  justify-content: flex-end;
  gap: 6px;
}
.shelf { margin-top: 8px; }
.poster-card {
  border: 1px solid var(--el-border-color);
  border-radius: 16px;
  padding: 10px;
  background: #fff;
  min-width: 0;
}
.poster-hit {
  display: block;
  width: 100%;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
}
.poster-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; margin-top: 8px; }
.poster-copy strong { line-height: 1.3; }
.poster-copy .title-en { min-height: 1.2em; }
.stack { position: relative; display: block; margin: 8px 12px 0 0; }
.layer {
  position: absolute;
  inset: 0;
  border-radius: 14px;
  background: #eef1f8;
  border: 1px solid var(--el-border-color);
}
.l2 { transform: translate(10px, -8px); }
.l1 { transform: translate(5px, -4px); background: #f7f8fc; }
.stack :deep(.poster) { position: relative; z-index: 1; }
.series-fold {
  grid-column: 1 / -1;
  border: 1px solid var(--el-border-color);
  border-radius: 16px;
  padding: 8px 10px;
  background: #fafbfe;
}
.filmstrip {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 4px 2px;
}
.member-card {
  flex: 0 0 112px;
  width: 112px;
  padding: 0;
  border: none;
  background: none;
  text-align: left;
  cursor: pointer;
  color: inherit;
}
.member-card strong { display: block; margin-top: 6px; font-size: 13px; line-height: 1.3; }
.order {
  display: inline-flex;
  min-width: 18px;
  height: 18px;
  align-items: center;
  justify-content: center;
  margin-right: 4px;
  border-radius: 4px;
  background: #eef1f8;
  font-size: 12px;
}
.meta { color: #888; font-size: 13px; }
.detail { display: flex; gap: 16px; align-items: flex-start; }
.detail-poster { width: 120px; flex: none; }
.detail h4 { margin: 8px 0 6px; }
.overview { margin: 0; line-height: 1.6; white-space: pre-wrap; }
.overview + .overview { margin-top: 8px; }
.poster-card.series { cursor: pointer; }
.delete-body { margin: 0; line-height: 1.6; }
</style>
