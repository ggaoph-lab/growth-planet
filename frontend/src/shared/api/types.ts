export type Role = 'parent' | 'child'

export interface Preferences {
  animations: boolean
  sounds: boolean
}

export interface KidProfile {
  id: number
  nickname: string
  avatar: string
  theme_color: string
  grade: number
  age: number
  preferences: Preferences
}

export interface Me {
  user: { id: number; role: Role; display_name: string; username: string | null }
  family: { id: number; name: string; timezone: string; locale: string }
  child: KidProfile | null
  sudo_until: string | null
  server_time: string
}

export type ChildStatus = 'active' | 'paused' | 'archived'

export interface ParentChild {
  id: number
  nickname: string
  avatar: string
  birth_date: string
  age: number
  grade: number
  theme_color: string
  status: ChildStatus
  pin_enabled: boolean
  preferences: Preferences
  created_at: string
  updated_at: string
}

export interface Device {
  id: number
  name: string
  is_current: boolean
  authorized_by: string | null
  created_at: string
  last_used_at: string | null
  revoked_at: string | null
}

export interface AuditEntry {
  id: number
  action: string
  actor: string | null
  actor_role: Role | null
  subject_type: string | null
  subject_id: number | null
  changes: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface Dashboard {
  family: { name: string; timezone: string }
  today: string
  children: ParentChild[]
  active_devices: number
  recent_activity: { id: number; action: string; actor_role: Role | null; created_at: string }[]
}

export interface Family {
  id: number
  name: string
  timezone: string
  locale: string
  week_starts_on: number
}

export interface ParentAccount {
  id: number
  user_id: number
  display_name: string
  username: string | null
  email: string | null
  status: string
  is_self: boolean
  last_login_at: string | null
}

export interface AiProviderStatus {
  configured: boolean
  source: 'family' | 'env' | 'none' | string
  model: string
  hint: string | null
}

export interface AiSettings {
  provider: string
  gemini: AiProviderStatus
  qwen: AiProviderStatus
}

export interface KidLoginProfile {
  id: number
  nickname: string
  avatar: string
  theme_color: string
  pin_required: boolean
}

export interface Planet {
  key: string
  title: string
  icon: string
  available: boolean
  opens_in_phase: number
}

export interface KidHome {
  profile: KidProfile
  planets: Planet[]
}

export type ApprovalStatus = 'PENDING' | 'APPROVED' | 'REJECTED' | 'RETURNED' | 'CANCELLED'
export type ApprovalType = 'task_completion' | 'reward_grant' | 'redemption' | 'english_log'

export interface ApprovalRequest {
  id: number
  family_id: number
  child_id: number
  type: ApprovalType
  subject_type: string
  subject_id: number
  status: ApprovalStatus
  requested_at: string
  decided_at: string | null
  decision_note: string | null
  lock_version: number
  created_at: string
  updated_at: string
}

export type TaskKind = 'standard' | 'stepped' | 'long_term'
export type TaskInstanceStatus = 'pending' | 'in_progress' | 'submitted' | 'returned' | 'completed' | 'cancelled'

export type ScheduleFrequency = 'daily' | 'weekly' | 'dates' | 'once'

export interface TaskSchedule {
  id?: number
  task_id?: number
  frequency: ScheduleFrequency | string
  weekdays?: number[] | null
  dates?: string[] | null
  due_time?: string | null
}

export interface TaskCategory {
  id: number
  family_id: number
  name: string
  sort: number
}

export interface ParentTask {
  id: number
  family_id: number
  parent_id?: number | null
  child_id: number | null
  category?: string | null
  category_id?: number | null
  task_category?: TaskCategory | null
  title: string
  icon: string | null
  description: string | null
  kind: TaskKind
  start_date: string
  end_date: string | null
  xp_reward: number
  coin_reward: number
  requires_approval: boolean
  requires_self_check: boolean
  with_parent: boolean
  is_active: boolean
  version: number
  schedules: TaskSchedule[]
  steps?: { id: number; title: string; is_required: boolean; sort: number }[]
  children?: ParentTask[]
}

export type HomeworkSubject = '语文' | '数学' | '英语' | '科学' | '道法' | '体育' | '美术' | '音乐' | '其他'

export interface HomeworkItemPreview {
  title: string
  detail: string | null
  suggested_coins: number
  suggested_xp: number
}

export type HomeworkParserKind = 'gemini' | 'qwen' | 'rules'

export interface HomeworkParseResult {
  parser: HomeworkParserKind | string
  subject: HomeworkSubject | string
  task_date: string
  title: string
  items: HomeworkItemPreview[]
  xp_reward: number
  coin_reward: number
  confidence: number
  notes: string | null
  source_text: string
  ai_configured: boolean
  providers_available?: string[]
}

export interface HomeworkConfirmResult {
  task: ParentTask
  instances: TaskInstance[]
}

export interface TaskInstance {
  id: number
  task_id: number
  child_id: number
  task_date: string
  status: TaskInstanceStatus
  lock_version: number
  snapshot: {
    title?: string
    xp_reward?: number
    coin_reward?: number
    due_time?: string | null
    with_parent?: boolean
    group_title?: string | null
    group_task_id?: number | string | null
    steps?: { id?: number; title: string; sort?: number }[]
  } & Record<string, unknown>
  instance_steps?: { id: number; title: string; sort?: number; checked?: boolean }[]
  ready_at: string | null
  submitted_at: string | null
  completed_at: string | null
}

export interface ParentHabit {
  id: number
  family_id: number
  child_id: number
  title: string
  icon: string | null
  frequency: string
  target_per_period: number
  xp_reward: number
  coin_reward: number
  is_active: boolean
}

export type RewardKind = 'physical' | 'family_activity' | 'virtual_decoration'
export type RewardStatus = 'draft' | 'on_shelf' | 'off_shelf'

export interface Reward {
  id: number
  family_id: number
  name: string
  description: string | null
  kind: RewardKind
  coin_price: number
  stock: number | null
  requires_approval: boolean
  status: RewardStatus
  version: number
  image_attachment_id?: number | null
  image_url?: string | null
}

export type RedemptionStatus = 'requested' | 'approved' | 'rejected' | 'cancelled' | 'fulfilled'

export interface RewardRedemption {
  id: number
  ulid: string
  family_id: number
  child_id: number
  reward_id: number
  status: RedemptionStatus
  coin_cost: number
  reward_snapshot: { name?: string } & Record<string, unknown>
  created_at: string
}

export type WishStatus = 'pending' | 'rejected' | 'converted'

export interface Wish {
  id: number
  family_id: number
  child_id: number
  name: string
  description: string | null
  coin_price: number | null
  status: WishStatus
  reward_id: number | null
  created_at: string
  child?: { id: number; nickname: string }
  reward?: Reward | null
}

export interface KidWallet {
  xp: { balance: number; lifetime_earned: number }
  coin: { balance: number; held: number; available: number; lifetime_earned: number }
}

export interface ItemLocation {
  id: number
  family_id: number
  kind: string
  name: string
  is_system: boolean
  sort: number
}

export type ItemStatus = 'normal' | 'temporarily_missing' | 'lost' | 'found' | 'damaged'

export interface ParentItem {
  id: number
  family_id: number
  child_id: number
  name: string
  category: string | null
  color: string | null
  note: string | null
  location_id: number
  status: ItemStatus
  carry_daily: boolean
  location?: ItemLocation
}

export interface ItemChecklistLine {
  id: number
  item_checklist_id: number
  item_id: number
  result: string
  item?: ParentItem
}

export interface ItemChecklist {
  id: number
  child_id: number
  kind: 'before_school' | 'after_school'
  target_date: string
  status: string
  lines?: ItemChecklistLine[]
}

export interface KidBag {
  items: ParentItem[]
  locations: ItemLocation[]
  checklists: ItemChecklist[]
}

export interface ExplorationProject {
  id: number
  family_id: number
  child_id: number
  title: string
  category: string
  current_stage: number
  status: string
  events_count?: number
  events?: ExplorationEvent[]
}

export interface ExplorationEvent {
  id: number
  project_id: number
  event_date: string
  stage: number
  feeling: string | null
  willing_next: string | null
  child_words: string | null
}

export interface FoodItem {
  id: number
  family_id: number
  name: string
  category: string | null
}

export interface FoodExplorationRecord {
  id: number
  food_id: number
  record_date: string
  looked: boolean
  smelled: boolean
  touched: boolean
  tasted_voluntarily: boolean
  ate: boolean
  liking: number | null
  said_tasty: boolean | null
  willing_again: boolean | null
  child_words: string | null
  food?: FoodItem
}

export interface ParentMovie {
  id: number
  family_id: number
  title_zh: string
  runtime_minutes: number | null
  data_completeness: string
  source: string
  external_id?: string | null
  original_title?: string | null
  overview?: string | null
  release_year?: number | null
  poster_key?: string | null
}

export interface MovieSuggestion {
  key: string
  source: 'builtin' | 'tmdb'
  external_id: string | null
  title_zh: string
  original_title: string | null
  release_year: number | null
  runtime_minutes: number | null
  overview: string | null
  series_key: string | null
  series_name: string | null
  poster_url: string
  belongs_to_collection: { external_id: string; name: string } | null
}

export interface TmdbMovieHit {
  external_id: string
  title_zh: string
  original_title: string
  release_year: number | null
  overview: string
  poster_path: string | null
  belongs_to_collection: { external_id: string; name: string } | null
}

export interface ParentMovieCollection {
  id: number
  name: string
  overview: string | null
  sort_mode: 'story' | 'release' | 'custom'
  movie_count: number
  structure_locked: boolean
  member_count_in_view?: number
  items?: { id: number; movie_id: number; movie?: ParentMovie }[]
}

export type ParentCatalogItem =
  | { kind: 'movie'; movie: ParentMovie }
  | { kind: 'collection'; collection: ParentMovieCollection; movies: ParentMovie[] }

export interface MovieViewing {
  id: number
  sequence: number
  language: 'zh' | 'en'
  position_percent: number
  status: string
  reward_eligible: boolean
  rewarded: boolean
}

export interface MovieViewingUpdate {
  viewings: MovieViewing[]
  reward: { xp: number; coins: number } | null
}

export interface KidMovie {
  id: number
  title_zh: string
  original_title?: string | null
  runtime_minutes: number | null
  overview: string | null
  release_year?: number | null
  poster_key?: string | null
  collection_position?: { story: number | null; release: number | null; custom: number | null }
  library?: {
    id: number
    shelf: string
    slot: string | null
    progress?: { position_seconds: number; status: string } | null
    viewings?: MovieViewing[]
  }
}

export interface KidMovieCollectionSummary {
  id: number
  name: string
  visible_count: number
  watched_count: number
  completed_count: number
  progress_ratio: number
  next_movie_id: number | null
  sort_mode: string
}

export type KidCatalogItem =
  | { kind: 'movie'; movie: KidMovie }
  | { kind: 'collection'; collection: KidMovieCollectionSummary; movies: KidMovie[] }

export interface KidMovieLibraryEntry {
  id: number
  movie_id: number
  shelf: string
  slot: string | null
  movie?: KidMovie
  progress?: { position_seconds: number; status: string }
}

export interface KidScreenTimeSummary {
  movie: { period: string; period_start: string; limit_seconds: number; used_seconds: number; remaining_seconds: number }
  english: { period: string; period_start: string; limit_seconds: number; used_seconds: number; remaining_seconds: number }
}

export interface ScreenTimePolicyRow {
  id: number
  child_id: number
  category: string
  period: string
  minutes: number
}

export interface EnglishShow {
  id: number
  family_id: number
  title: string
  emoji: string
  note: string | null
  active: boolean
  sort: number
}

export interface EnglishWatchLog {
  id: number
  show_id: number
  watch_date: string
  minutes: number
  self_chosen: boolean
  liking: string | null
  status: string
  show?: EnglishShow
}

export interface GrowthDailyReport {
  date: string
  tasks_completed: number
  habits_logged: number
  english_minutes: number
  screen_time_seconds: number
}

export interface GrowthWeeklyReport {
  week_start: string
  week_end: string
  tasks_completed: number
  movies_completed: number
  english_minutes: number
}

export interface GrowthSummaryReport {
  tasks_completed_total: number
  habit_records_total: number
  movies_completed_total: number
  english_logs_approved_total: number
}

export interface CollectionStamp {
  key: string
  title: string
  earned: boolean
}
