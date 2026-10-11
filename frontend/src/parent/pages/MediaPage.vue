<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import MoviePoster from '@/shared/MoviePoster.vue'
import type {
  EnglishShow,
  EnglishWatchLog,
  ParentCatalogItem,
  ParentChild,
  ParentMovie,
  MovieSuggestion,
  ParentMovieCollection,
  ScreenTimePolicyRow,
  TmdbMovieHit,
} from '@/shared/api/types'

const EXPAND_KEY = 'gp.parent.movies.expanded'

const { t } = useI18n()
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
const policies = ref<ScreenTimePolicyRow[]>([])
const expanded = ref<Record<string, boolean>>(loadExpanded())

const movieForm = ref({ title_zh: '', runtime_minutes: 90 })
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

const libraryTitles = computed(() => new Set(movies.value.map((movie) => movie.title_zh)))

function parentPoster(movie: ParentMovie | undefined): string {
  return movie?.poster_key ? `/api/v1/parent/movies/${movie.id}/poster` : ''
}

const posterDetail = ref<{ title: string; overview: string; meta: string; poster: string } | null>(null)

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

function showPosterDetail(title: string, overview: string | null | undefined, meta: string, poster: string): void {
  posterDetail.value = {
    title,
    overview: overview?.trim() || t('common.noOverview'),
    meta,
    poster,
  }
}

function openParentMovie(movie: ParentMovie): void {
  showPosterDetail(movie.title_zh, movie.overview, movieMeta(movie.release_year, movie.runtime_minutes), parentPoster(movie))
}

function openSuggestion(item: MovieSuggestion): void {
  showPosterDetail(item.title_zh, item.overview, movieMeta(item.release_year, item.runtime_minutes), item.poster_url)
}

function openHit(hit: TmdbMovieHit): void {
  showPosterDetail(hit.title_zh, hit.overview, movieMeta(hit.release_year, null), '')
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

function toggle(key: string): void {
  expanded.value = { ...expanded.value, [key]: !expanded.value[key] }
  sessionStorage.setItem(EXPAND_KEY, JSON.stringify(expanded.value))
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

async function createMovie(): Promise<void> {
  const title = movieForm.value.title_zh.trim()
  const match = suggestions.value.find((item) => item.title_zh === title)
  if (match) {
    movieForm.value = { title_zh: '', runtime_minutes: 90 }
    await addSuggestion(match)
    return
  }
  try {
    const movie = await api.post<ParentMovie>('/parent/movies', { source: 'manual', ...movieForm.value })
    await api.post(`/parent/movies/${movie.id}/reviews`, {
      safety_status: 'APPROVED_SUITABLE',
      min_age: 6,
      reason: t('common.parentEnteredReason'),
    })
    if (childId.value) {
      await api.put(`/parent/movies/${movie.id}/approvals/${childId.value}`, { visible: true })
    }
    ElMessage.success(t('common.movieAdded'))
    movieForm.value = { title_zh: '', runtime_minutes: 90 }
    await loadMovies()
  } catch (e) {
    reportError(e)
  }
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

onMounted(loadAll)
</script>

<template>
  <div>
    <div v-loading="loading" class="page">
      <el-segmented v-model="tab" :options="tabOptions" />

      <el-select v-if="childId" v-model="childId" class="child" :placeholder="t('common.selectChild')" @change="loadAll">
        <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
      </el-select>

      <section v-if="tab === 'movies'">
        <el-card shadow="never">
          <template #header>{{ t('common.addMovieManual') }}</template>
          <el-form inline @submit.prevent="createMovie">
            <el-input v-model="movieForm.title_zh" :placeholder="t('common.movieTitle')" />
            <el-input-number v-model="movieForm.runtime_minutes" :min="1" />
            <el-button type="primary" @click="createMovie">{{ t('common.addMovieOpen') }}</el-button>
          </el-form>
        </el-card>

        <h3 v-if="collections.length" style="margin: 16px 0 8px">{{ t('common.seriesList') }}</h3>
        <el-table v-if="collections.length" :data="collections" style="margin-bottom: 12px">
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
        <div v-if="suggestions.length" class="poster-grid">
          <article v-for="item in suggestions" :key="item.key" class="poster-card">
            <button type="button" class="poster-hit" :aria-label="item.title_zh" @click="openSuggestion(item)">
              <MoviePoster :title="item.title_zh" :src="item.poster_url" />
            </button>
            <div class="poster-copy">
              <strong>{{ item.title_zh }}</strong>
              <span v-if="item.series_name" class="meta">{{ item.series_name }}</span>
              <span class="meta">
                {{ item.release_year ?? '—' }}
                <template v-if="item.runtime_minutes"> · {{ item.runtime_minutes }} {{ t('common.minutes') }}</template>
              </span>
              <el-button
                v-if="!libraryTitles.has(item.title_zh) && !(item.external_id && importedExternalIds.has(item.external_id))"
                type="primary"
                size="small"
                @click="addSuggestion(item)"
              >
                {{ t('common.addToLibrary') }}
              </el-button>
              <span v-else class="meta">{{ t('common.inLibrary') }}</span>
            </div>
          </article>
        </div>
        <p v-else-if="catalog.length === 0" class="hint">{{ t('common.noRecommendationCards') }}</p>

        <div v-if="searchHits.length" class="poster-grid">
          <article v-for="hit in searchHits" :key="hit.external_id" class="poster-card">
            <button type="button" class="poster-hit" :aria-label="hit.title_zh" @click="openHit(hit)">
              <MoviePoster :title="hit.title_zh" />
            </button>
            <div class="poster-copy">
              <strong>{{ hit.title_zh }}</strong>
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
          <article
            v-for="row in catalog"
            :key="row.kind === 'collection' ? `c-${row.collection.id}` : `m-${row.movie.id}`"
            class="poster-card"
            :class="{ series: row.kind === 'collection', open: row.kind === 'collection' && expanded[`p-${row.collection.id}`] }"
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
              <button
                type="button"
                class="series-face"
                :aria-expanded="!!expanded[`p-${row.collection.id}`]"
                @click="toggle(`p-${row.collection.id}`)"
              >
                <span class="stack">
                  <span class="layer l2" />
                  <span class="layer l1" />
                  <MoviePoster :title="row.collection.name" :src="parentPoster(row.movies[0])" />
                </span>
                <span class="series-copy">
                  <strong>{{ row.collection.name }}</strong>
                  <span class="meta">{{ t('common.moviesInSeries', { count: row.collection.movie_count }) }}</span>
                  <span class="meta">{{ expanded[`p-${row.collection.id}`] ? t('common.collapseSeries') : t('common.expandSeries') }}</span>
                </span>
                <span class="chevron" aria-hidden="true" />
              </button>
              <el-button type="danger" link @click="askDeleteSeries(row.collection)">{{ t('common.deleteSeries') }}</el-button>
              <div
                class="fold"
                :class="{ open: expanded[`p-${row.collection.id}`] }"
                :inert="!expanded[`p-${row.collection.id}`]"
              >
                <div class="fold-clip">
                  <div class="filmstrip">
                    <button
                      v-for="(m, order) in row.movies"
                      :key="m.id"
                      type="button"
                      class="member-card"
                      @click="openParentMovie(m)"
                    >
                      <MoviePoster :title="m.title_zh" :src="parentPoster(m)" />
                      <strong><span class="order">{{ order + 1 }}</span>{{ m.title_zh }}</strong>
                    </button>
                  </div>
                </div>
              </div>
            </template>
          </article>
        </div>

        <el-table :data="movies" style="margin-top: 16px">
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
        <el-card shadow="never">
          <el-form inline @submit.prevent="createShow">
            <el-input v-model="showForm.title" :placeholder="t('common.showName')" />
            <el-input v-model="showForm.emoji" style="width: 80px" />
            <el-button type="primary" @click="createShow">{{ t('common.addShow') }}</el-button>
          </el-form>
        </el-card>
        <el-table :data="shows" style="margin-top: 12px">
          <el-table-column :label="t('common.show')"><template #default="{ row }">{{ row.emoji }} {{ row.title }}</template></el-table-column>
          <el-table-column prop="active" :label="t('common.enabled')" width="80" />
        </el-table>
        <h3>{{ t('common.logRecords') }}</h3>
        <el-table :data="englishLogs">
          <el-table-column prop="watch_date" :label="t('common.today')" width="120" />
          <el-table-column prop="minutes" :label="t('common.minutes')" width="80" />
          <el-table-column prop="status" :label="t('common.status')" />
        </el-table>
        <p class="hint">{{ t('common.englishLogsHint') }}</p>
      </section>

      <section v-else>
        <el-table :data="policies">
          <el-table-column prop="category" :label="t('common.category')" />
          <el-table-column prop="period" :label="t('common.period')" width="100" />
          <el-table-column :label="t('common.minutesLimit')">
            <template #default="{ row }">
              <el-input-number v-model="row.minutes" :min="0" :step="5" size="small" />
            </template>
          </el-table-column>
        </el-table>
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
          <p v-if="posterDetail.meta" class="meta">{{ posterDetail.meta }}</p>
          <h4>{{ t('common.movieIntro') }}</h4>
          <p class="overview">{{ posterDetail.overview }}</p>
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
.search-row { display: flex; gap: 8px; flex-wrap: wrap; }
.search-row .el-input { flex: 1; min-width: 200px; }
.poster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
  gap: 14px;
}
.shelf { margin-top: 8px; }
.poster-card {
  border: 1px solid var(--el-border-color);
  border-radius: 16px;
  padding: 10px;
  background: #fff;
  min-width: 0;
}
.poster-card.series.open { grid-column: 1 / -1; }
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
.series-face {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
  width: 100%;
  padding: 0 16px 0 0;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  color: inherit;
}
.series.open .series-face { flex-direction: row; align-items: center; }
.series.open .stack { width: 84px; flex: none; }
.series-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
.stack { position: relative; margin: 8px 12px 0 0; }
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
.chevron {
  position: absolute;
  right: 0;
  top: 16px;
  width: 9px;
  height: 9px;
  border-right: 2px solid currentColor;
  border-bottom: 2px solid currentColor;
  transform: rotate(45deg);
  transition: transform 0.2s ease;
}
.series.open .chevron { top: 22px; transform: rotate(225deg); }
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
.delete-body { margin: 0; line-height: 1.6; }
</style>
