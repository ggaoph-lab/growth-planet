<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type {
  ExplorationProject,
  FoodExplorationRecord,
  FoodItem,
  ItemChecklist,
  ItemLocation,
  ParentChild,
  ParentItem,
} from '@/shared/api/types'
import { useIsPhone } from '@/shared/useMediaQuery'

const { t } = useI18n()
const isPhone = useIsPhone()
const tab = ref<'items' | 'explore' | 'food'>('items')
const children = ref<ParentChild[]>([])
const childId = ref<number | undefined>()
const loading = ref(true)

const locations = ref<ItemLocation[]>([])
const items = ref<ParentItem[]>([])
const checklists = ref<ItemChecklist[]>([])
const explorations = ref<ExplorationProject[]>([])
const foods = ref<FoodItem[]>([])
const foodRecords = ref<FoodExplorationRecord[]>([])

const itemDialog = ref(false)
const itemForm = ref({ name: '', carry_daily: true, location_id: undefined as number | undefined })
const locationDialog = ref(false)
const locationForm = ref<{ name: string; kind: 'home' | 'bag' }>({ name: '', kind: 'home' })
const locationBusy = ref(false)
const exploreDialog = ref(false)
const exploreForm = ref({ title: '', category: 'activity' })
const foodDialog = ref(false)
const foodForm = ref({ name: '', category: '' })
const eventDialog = ref(false)
const eventProject = ref<ExplorationProject | null>(null)
const eventForm = ref({ stage: 1, feeling: '😊', willing_next: 'yes', parent_observation: '' })

async function loadChildren(): Promise<void> {
  children.value = await api.get<ParentChild[]>('/parent/children')
  if (!childId.value) {
    childId.value = children.value.find((c) => c.status === 'active')?.id
  }
}

async function loadItems(): Promise<void> {
  if (!childId.value) return
  locations.value = await api.get<ItemLocation[]>('/parent/item-locations')
  items.value = await api.get<ParentItem[]>('/parent/items', { child_id: childId.value })
  checklists.value = await api.get<ItemChecklist[]>('/parent/item-checklists', {
    child_id: childId.value,
  })
}

async function loadExplore(): Promise<void> {
  explorations.value = await api.get<ExplorationProject[]>('/parent/explorations', {
    child_id: childId.value,
  })
}

async function loadFood(): Promise<void> {
  foods.value = await api.get<FoodItem[]>('/parent/foods')
  foodRecords.value = await api.get<FoodExplorationRecord[]>('/parent/food-records', {
    child_id: childId.value,
  })
}

async function loadAll(): Promise<void> {
  loading.value = true
  try {
    await loadChildren()
    await Promise.all([loadItems(), loadExplore(), loadFood()])
  } catch (e) {
    if (e instanceof ApiError) ElMessage.error(e.message)
  } finally {
    loading.value = false
  }
}

function reportError(e: unknown): void {
  if (e instanceof ApiError) ElMessage.error(Object.values(e.fieldErrors)[0]?.[0] ?? e.message)
}

async function createItem(): Promise<void> {
  if (!childId.value || !itemForm.value.name.trim()) return
  try {
    const created = await api.post<ParentItem>('/parent/items', {
      child_id: childId.value,
      name: itemForm.value.name.trim(),
      carry_daily: itemForm.value.carry_daily,
      location_id: itemForm.value.location_id,
    })
    items.value.unshift(created)
    itemDialog.value = false
    itemForm.value = { name: '', carry_daily: true, location_id: undefined }
    ElMessage.success(t('common.itemAdded'))
    await loadItems()
  } catch (e) {
    reportError(e)
  }
}

async function moveItem(item: ParentItem, locationId: number): Promise<void> {
  try {
    const res = await api.postIdempotent<{ item: ParentItem }>(`/parent/items/${item.id}/events`, {
      event_type: 'moved',
      to_location_id: locationId,
    })
    const i = items.value.findIndex((x) => x.id === item.id)
    if (i >= 0) items.value[i] = res.item
  } catch (e) {
    reportError(e)
  }
}

const managedLocations = computed(() =>
  locations.value.filter((l) => l.kind === 'home' || l.kind === 'bag'),
)

function openAddLocation(kind: 'home' | 'bag'): void {
  locationForm.value = { name: '', kind }
  locationDialog.value = true
}

async function createLocation(): Promise<void> {
  const name = locationForm.value.name.trim()
  if (!name || locationBusy.value) return
  locationBusy.value = true
  try {
    const created = await api.post<ItemLocation>('/parent/item-locations', {
      name,
      kind: locationForm.value.kind,
    })
    locations.value = [...locations.value, created].sort((a, b) => a.sort - b.sort || a.id - b.id)
    locationDialog.value = false
    ElMessage.success(t('common.locationBoxAdded'))
  } catch (e) {
    reportError(e)
  } finally {
    locationBusy.value = false
  }
}

async function removeLocation(loc: ItemLocation): Promise<void> {
  if (loc.is_system) return
  try {
    await api.delete(`/parent/item-locations/${loc.id}`)
    locations.value = locations.value.filter((l) => l.id !== loc.id)
    ElMessage.success(t('common.locationBoxRemoved'))
    await loadItems()
  } catch (e) {
    reportError(e)
  }
}

async function createExplore(): Promise<void> {
  if (!childId.value || !exploreForm.value.title.trim()) return
  try {
    const created = await api.post<ExplorationProject>('/parent/explorations', {
      child_id: childId.value,
      title: exploreForm.value.title.trim(),
      category: exploreForm.value.category,
    })
    explorations.value.unshift(created)
    exploreDialog.value = false
    exploreForm.value = { title: '', category: 'activity' }
    ElMessage.success(t('common.exploreCreated'))
  } catch (e) {
    reportError(e)
  }
}

function openEvent(p: ExplorationProject | Record<string, unknown>): void {
  if (!('id' in p) || typeof p.id !== 'number') return
  const project = p as ExplorationProject
  eventProject.value = project
  eventForm.value = { stage: project.current_stage, feeling: '😊', willing_next: 'yes', parent_observation: '' }
  eventDialog.value = true
}

async function saveEvent(): Promise<void> {
  if (!eventProject.value) return
  try {
    await api.postIdempotent(`/parent/explorations/${eventProject.value.id}/events`, eventForm.value)
    eventDialog.value = false
    await loadExplore()
    ElMessage.success(t('common.eventRecorded'))
  } catch (e) {
    reportError(e)
  }
}

async function createFood(): Promise<void> {
  if (!foodForm.value.name.trim()) return
  try {
    const created = await api.post<FoodItem>('/parent/foods', {
      name: foodForm.value.name.trim(),
      category: foodForm.value.category || null,
    })
    foods.value.unshift(created)
    foodDialog.value = false
    foodForm.value = { name: '', category: '' }
    ElMessage.success(t('common.foodAdded'))
  } catch (e) {
    reportError(e)
  }
}

onMounted(loadAll)
</script>

<template>
  <div v-loading="loading">
    <el-tabs v-model="tab" @tab-change="loadAll">
      <el-tab-pane :label="t('common.bagTab')" name="items">
        <div class="toolbar">
          <el-select v-model="childId" :placeholder="t('common.selectChild')" style="width: 160px" @change="loadItems">
            <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
          <el-button type="primary" @click="itemDialog = true">{{ t('common.addItem') }}</el-button>
        </div>

        <h3 class="sub">{{ t('common.locationBoxes') }}</h3>
        <p class="hint">{{ t('common.locationBoxesHint') }}</p>
        <div class="loc-actions">
          <el-button size="small" @click="openAddLocation('home')">{{ t('common.addHomeBox') }}</el-button>
          <el-button size="small" @click="openAddLocation('bag')">{{ t('common.addBagBox') }}</el-button>
        </div>
        <div class="loc-list">
          <article v-for="loc in managedLocations" :key="loc.id" class="loc-card">
            <div>
              <strong>{{ loc.name }}</strong>
              <span class="loc-kind">{{ loc.kind === 'home' ? t('common.locationKindHome') : t('common.locationKindBag') }}</span>
              <el-tag v-if="loc.is_system" size="small" type="info">{{ t('common.systemLocation') }}</el-tag>
            </div>
            <el-button
              v-if="!loc.is_system"
              link
              type="danger"
              size="small"
              @click="removeLocation(loc)"
            >
              {{ t('common.deleteLocationBox') }}
            </el-button>
          </article>
        </div>

        <el-table v-if="!isPhone" :data="items" stripe>
          <el-table-column prop="name" :label="t('common.name')" />
          <el-table-column :label="t('common.location')">
            <template #default="{ row }">
              <el-select :model-value="row.location_id" size="small" @change="(v: number) => moveItem(row as ParentItem, v)">
                <el-option v-for="loc in locations" :key="loc.id" :label="loc.name" :value="loc.id" />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column prop="status" :label="t('common.status')" width="120" />
          <el-table-column :label="t('common.carryDaily')" width="100">
            <template #default="{ row }">{{ row.carry_daily ? t('common.yes') : t('common.no') }}</template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="items.length === 0" />
          <article v-for="row in items" :key="row.id" class="phone-card">
            <div class="phone-info">
              <div class="phone-title">{{ row.name }}</div>
              <div class="phone-meta">
                {{ row.status }} · {{ row.carry_daily ? t('common.carryDaily') : '' }}
              </div>
              <el-select :model-value="row.location_id" size="small" class="phone-select" @change="(v: number) => moveItem(row, v)">
                <el-option v-for="loc in locations" :key="loc.id" :label="loc.name" :value="loc.id" />
              </el-select>
            </div>
          </article>
        </div>
        <h3 v-if="checklists.length" class="sub">{{ t('common.todayChecklist') }}</h3>
        <el-collapse v-if="checklists.length">
          <el-collapse-item v-for="cl in checklists" :key="cl.id" :title="cl.kind === 'before_school' ? t('common.beforeSchool') : t('common.afterSchool')">
            <ul class="lines">
              <li v-for="line in cl.lines" :key="line.id">
                {{ line.item?.name ?? line.item_id }} — {{ line.result }}
              </li>
            </ul>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <el-tab-pane :label="t('common.exploreTab')" name="explore">
        <div class="toolbar">
          <el-select v-model="childId" style="width: 160px" @change="loadExplore">
            <el-option v-for="c in children" :key="c.id" :label="c.nickname" :value="c.id" />
          </el-select>
          <el-button type="primary" @click="exploreDialog = true">{{ t('common.newProject') }}</el-button>
        </div>
        <el-table v-if="!isPhone" :data="explorations" stripe @row-click="(row: ExplorationProject) => openEvent(row)">
          <el-table-column prop="title" :label="t('common.project')" />
          <el-table-column prop="category" :label="t('common.type')" width="100" />
          <el-table-column :label="t('common.stage')" width="80">
            <template #default="{ row }">{{ row.current_stage }}/5</template>
          </el-table-column>
          <el-table-column :label="t('common.records')" width="80">
            <template #default="{ row }">{{ row.events_count ?? 0 }}</template>
          </el-table-column>
        </el-table>
        <div v-else class="phone-list">
          <el-empty v-if="explorations.length === 0" />
          <article
            v-for="row in explorations"
            :key="row.id"
            class="phone-card tap"
            @click="openEvent(row)"
          >
            <div class="phone-info">
              <div class="phone-title">{{ row.title }}</div>
              <div class="phone-meta">
                {{ row.category }} · {{ row.current_stage }}/5 · {{ row.events_count ?? 0 }}
              </div>
            </div>
          </article>
        </div>
        <p class="hint">{{ t('common.clickRowForEvent') }}</p>
      </el-tab-pane>

      <el-tab-pane :label="t('common.foodTab')" name="food">
        <div class="toolbar">
          <el-button type="primary" @click="foodDialog = true">{{ t('common.addFood') }}</el-button>
        </div>
        <el-row :gutter="16">
          <el-col :xs="24" :sm="12">
            <h3 class="sub">{{ t('common.foodLibrary') }}</h3>
            <el-tag v-for="f in foods" :key="f.id" class="tag">{{ f.name }}</el-tag>
          </el-col>
          <el-col :xs="24" :sm="12">
            <h3 class="sub">{{ t('common.recentRecords') }}</h3>
            <ul class="lines">
              <li v-for="r in foodRecords" :key="r.id">
                {{ r.food?.name ?? r.food_id }} · {{ r.record_date }}
                <span v-if="r.said_tasty !== null"> · {{ t('common.saidTasty') }}: {{ r.said_tasty ? t('common.yes') : t('common.no') }}</span>
                <span v-if="r.willing_again !== null"> · {{ t('common.willingAgain') }}: {{ r.willing_again ? t('common.yes') : t('common.no') }}</span>
              </li>
            </ul>
          </el-col>
        </el-row>
      </el-tab-pane>
    </el-tabs>

    <el-dialog v-model="itemDialog" :title="t('common.addItem')" width="400px">
      <el-form label-width="80px">
        <el-form-item :label="t('common.name')"><el-input v-model="itemForm.name" /></el-form-item>
        <el-form-item :label="t('common.initialLocation')">
          <el-select v-model="itemForm.location_id" clearable :placeholder="t('common.defaultHome')">
            <el-option v-for="loc in locations" :key="loc.id" :label="loc.name" :value="loc.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('common.carryDaily')"><el-switch v-model="itemForm.carry_daily" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="itemDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createItem">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="locationDialog"
      :title="locationForm.kind === 'home' ? t('common.addHomeBox') : t('common.addBagBox')"
      width="400px"
    >
      <el-form label-width="80px" @submit.prevent="createLocation">
        <el-form-item :label="t('common.name')">
          <el-input
            v-model="locationForm.name"
            :placeholder="locationForm.kind === 'home' ? t('common.homeBoxNamePlaceholder') : t('common.bagBoxNamePlaceholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="locationDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="locationBusy" @click="createLocation">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="exploreDialog" :title="t('common.newExploreTitle')" width="400px">
      <el-form label-width="60px">
        <el-form-item :label="t('common.title')"><el-input v-model="exploreForm.title" /></el-form-item>
        <el-form-item :label="t('common.type')">
          <el-select v-model="exploreForm.category">
            <el-option :label="t('common.activity')" value="activity" />
            <el-option :label="t('common.sport')" value="sport" />
            <el-option :label="t('common.game')" value="game" />
            <el-option :label="t('common.story')" value="story" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="exploreDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createExplore">{{ t('common.create') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="eventDialog" :title="t('common.logExplore')" width="420px">
      <el-form label-width="80px">
        <el-form-item :label="t('common.stage')">
          <el-slider v-model="eventForm.stage" :min="1" :max="5" show-stops />
        </el-form-item>
        <el-form-item :label="t('common.feeling')"><el-input v-model="eventForm.feeling" /></el-form-item>
        <el-form-item :label="t('common.wantContinue')">
          <el-radio-group v-model="eventForm.willing_next">
            <el-radio value="yes">{{ t('common.want') }}</el-radio>
            <el-radio value="maybe">{{ t('common.maybe') }}</el-radio>
            <el-radio value="no">{{ t('common.dontWant') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('common.observation')"><el-input v-model="eventForm.parent_observation" type="textarea" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="eventDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="saveEvent">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="foodDialog" :title="t('common.addFood')" width="360px">
      <el-form label-width="60px">
        <el-form-item :label="t('common.name')"><el-input v-model="foodForm.name" /></el-form-item>
        <el-form-item :label="t('common.category')"><el-input v-model="foodForm.category" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="foodDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="createFood">{{ t('common.save') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.sub {
  margin: 20px 0 8px;
  font-size: 15px;
}
.lines {
  margin: 0;
  padding-left: 18px;
  font-size: 14px;
}
.tag {
  margin: 4px;
}
.hint {
  font-size: 13px;
  color: #888;
  margin-top: 8px;
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
.phone-card.tap {
  cursor: pointer;
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
.phone-select {
  width: 100%;
  max-width: 220px;
}
.loc-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.loc-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 20px;
}
.loc-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
}
.loc-kind {
  margin-left: 8px;
  font-size: 12px;
  color: #888;
}
</style>
