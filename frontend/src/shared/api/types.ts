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
  user: { id: number; role: Role; display_name: string; username: string | null; is_site_admin?: boolean }
  family: { id: number; name: string; timezone: string; locale: string }
  child: KidProfile | null
  sudo_until: string | null
  server_time: string
}

export type FamilyInviteStatus = 'available' | 'used' | 'revoked' | 'expired'

export interface FamilyInvite {
  id: number
  code_prefix: string
  note: string | null
  status: FamilyInviteStatus
  expires_at: string | null
  used_at: string | null
  used_by_family_id: number | null
  revoked_at: string | null
  created_at: string | null
  /** Present only on create response. */
  code?: string
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
  model_customized: boolean
  hint: string | null
  /** OpenAI-compatible relay root (…/v1). Present on the openai slot. */
  base_url?: string | null
  base_url_customized?: boolean
}

export interface AiModelChoice {
  id: string
  label: string
}

export interface AiModelList {
  provider: string
  models: AiModelChoice[]
  latest: string | null
  error: string | null
  probed?: boolean
}

export interface AiTtsVoiceOption {
  id: string
  name_zh: string
  name_en: string
  desc_zh: string
  desc_en: string
  tags: string[]
}

export interface AiTtsSettings {
  provider: 'auto' | 'qwen' | 'edge' | string
  configured: boolean
  source: 'tts' | 'ai' | 'env' | 'none' | string
  hint: string | null
  voice: string
  voice_en: string
  voice_customized: boolean
  voice_en_customized: boolean
  voices?: {
    zh: AiTtsVoiceOption[]
    en: AiTtsVoiceOption[]
  }
}

export interface TtsPreviewResult {
  available: boolean
  audio_base64: string | null
  mime: string | null
  provider: string | null
  qwen_configured: boolean
  hint?: string | null
  voice?: string
  lang?: string
}

export interface AiSettings {
  provider: string
  gemini: AiProviderStatus
  qwen: AiProviderStatus
  openai: AiProviderStatus
  tts: AiTtsSettings
}

export interface KidLoginProfile {
  id: number
  nickname: string
  avatar: string
  theme_color: string
  pin_required: boolean
  /** Digit count when pin_required; used so the pad can auto-submit. */
  pin_length: number | null
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

export interface EncyclopediaDomainSummary {
  key: string
  domain: string
  title: string
  emoji: string
  topic_count: number
}

export interface EncyclopediaSubject {
  subject: string
  title: string
  emoji: string
  topic_count: number
  attribution: string
  domains: EncyclopediaDomainSummary[]
}

export interface EncyclopediaUnlockHint {
  id: string
  name_zh: string
  name_en: string
  emoji: string
}

export interface EncyclopediaWord {
  word: string
  meaning: string
}

export interface EncyclopediaVideo {
  provider: 'bilibili' | 'youtube'
  id: string
}

export interface EncyclopediaTopicListItem {
  id: string
  domain_key: string
  name: string
  name_zh: string
  name_en: string
  emoji: string
  age_start: number
  age_end: number
  read: boolean
  locked: boolean
  unlock_hint?: EncyclopediaUnlockHint | null
}

export interface EncyclopediaDomainDetail {
  key: string
  title: string
  emoji: string
  topics: EncyclopediaTopicListItem[]
}

export interface EncyclopediaTopicDetail {
  id: string
  domain_key: string
  domain: string
  name: string
  name_zh: string
  name_en: string
  body: string
  body_zh: string
  body_en: string
  emoji: string
  age_start: number
  age_end: number
  read: boolean
  locked: boolean
  unlock_hint?: EncyclopediaUnlockHint | null
  words: EncyclopediaWord[]
  video: EncyclopediaVideo | null
  prerequisites: EncyclopediaTopicListItem[]
  next_topics: EncyclopediaTopicListItem[]
  attribution: string
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
  child_name?: string | null
  summary?: {
    title?: string | null
    group_title?: string | null
    description?: string | null
    xp_reward?: number
    coin_reward?: number
    point_reward?: number
    kind?: string | null
    coin_price?: number
  } | null
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
  point_reward: number
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

export type HomeworkParserKind = 'gemini' | 'qwen' | 'openai' | 'rules'

export interface HomeworkParseResult {
  parser: HomeworkParserKind | string
  subject: HomeworkSubject | string
  task_date: string
  title: string
  items: HomeworkItemPreview[]
  xp_reward: number
  coin_reward: number
  point_reward?: number
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
    description?: string | null
    xp_reward?: number
    coin_reward?: number
    point_reward?: number
    due_time?: string | null
    with_parent?: boolean
    requires_self_check?: boolean
    group_title?: string | null
    group_task_id?: number | string | null
    steps?: { id?: number; title: string; sort?: number; is_required?: boolean }[]
  } & Record<string, unknown>
  instance_steps?: {
    id: number
    title: string
    sort?: number
    checked?: boolean
    is_required?: boolean
  }[]
  ready_at: string | null
  first_step_at?: string | null
  submitted_at: string | null
  completed_at: string | null
  can_withdraw?: boolean
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
  point_reward: number
  is_active: boolean
}

export type RewardKind = 'physical' | 'family_activity' | 'virtual_decoration' | 'screen_time'
export type RewardStatus = 'draft' | 'on_shelf' | 'off_shelf'
export type RewardCurrency = 'coin' | 'point'

export interface Reward {
  id: number
  family_id: number
  name: string
  description: string | null
  kind: RewardKind
  currency: RewardCurrency
  coin_price: number
  point_price: number
  price: number
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
  coin_price: number
  point_price?: number | null
  reward_snapshot: { name?: string; currency?: RewardCurrency; price?: number } & Record<string, unknown>
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
  xp: {
    balance: number
    lifetime_earned: number
    level: number
    max_level: number
    to_next: number
    progress: number
  }
  points: { balance: number; held: number; available: number; lifetime_earned: number }
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

export interface ChecklistCompleteResult {
  checklist: ItemChecklist
  reward: { xp: number; coins: number } | null
}

export interface RewardPolicy {
  id: number
  family_id: number
  code: string
  xp_amount: number
  coin_amount: number
  requires_approval: boolean
  max_per_event: number | null
  max_per_day: number | null
  max_per_week: number | null
  max_per_subject: number | null
  is_active: boolean
}

export interface KidBag {
  items: ParentItem[]
  locations: ItemLocation[]
  checklists: ItemChecklist[]
}

export interface ItemEventLocation {
  id: number
  name: string
  kind: string
}

export interface ItemEvent {
  id: number
  item_id: number
  event_type: string
  from_location_id: number | null
  to_location_id: number | null
  from_location?: ItemEventLocation | null
  to_location?: ItemEventLocation | null
  from_status: string | null
  to_status: string | null
  checklist_line_id: number | null
  occurred_at: string | null
  note: string | null
}

export interface ItemFlowHistory {
  item: ParentItem
  events: ItemEvent[]
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

export type EnglishLabPath = 'standard' | 'rich'

export interface EnglishLabProgress {
  completed_today: number
  remaining_today: number
  due: number
  learned: number
  mastered: number
  total: number
  next_review: string | null
  vocabulary: {
    estimated_size: number
    band: number
    path?: EnglishLabPath
    effective_band?: number
  }
  path?: EnglishLabPath
  accuracy_7d: number | null
  missed_words: string[]
  as_of: string
}

export interface EnglishLabSummary {
  week_start: string
  summary: string
  focuses: string[]
  status: string
  accuracy_7d: number | null
  missed_words: string[]
}

export interface EnglishContentDraft {
  id: number
  kind: 'grammar' | 'story' | string
  status: 'pending' | 'published' | 'rejected' | string
  payload: {
    prompt?: string
    question?: string
    text?: string
    options?: string[]
    answer?: string
    tip?: string
    emoji?: string
    theme?: string
  }
  published_at: string | null
  created_at: string
  updated_at: string
  generation_status?: string
}

export interface EnglishSpeakAttempt {
  id: number
  business_date: string
  target_key: string
  target_text: string
  target_meaning: string
  score: number | null
  feedback: string | null
  status: string
  duration_ms: number
  expires_at: string
  created_at: string
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

export interface SchoolPeriodCell {
  id?: number
  weekday: number
  period_index: number
  subject: string
  start_time?: string | null
  end_time?: string | null
  room?: string | null
}

export interface SchoolSchedule {
  id: number
  child_id: number
  title: string
  period_count: number
  effective_from: string
  effective_to: string | null
  periods: SchoolPeriodCell[]
}

export type ScheduleExceptionType = 'no_school' | 'makeup_day' | 'custom'

export interface ScheduleException {
  id: number
  family_id: number
  child_id: number | null
  exception_date: string
  type: ScheduleExceptionType
  follow_weekday: number | null
  custom_periods: SchoolPeriodCell[] | null
  note: string | null
}

export interface SubjectItemRequirement {
  id: number
  family_id: number
  child_id: number
  subject: string
  item_id: number
  quantity: number
  item?: ParentItem
}

export interface BagExtraRequirement {
  id: number
  family_id: number
  child_id: number
  target_date: string
  item_id: number | null
  label: string | null
  quantity: number
  note: string | null
  item?: ParentItem | null
}

export interface ResolvedDaySchedule {
  date: string
  is_school_day: boolean
  periods: SchoolPeriodCell[]
  subjects: string[]
  schedule_id: number | null
  exception: {
    id: number
    type: ScheduleExceptionType
    follow_weekday: number | null
    note: string | null
    child_id: number | null
  } | null
}

export interface KidScheduleOverview {
  today: ResolvedDaySchedule
  tomorrow: ResolvedDaySchedule
}
