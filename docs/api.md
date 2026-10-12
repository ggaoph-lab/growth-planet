# 成长星球 — API 设计（V1.0）

> RESTful｜前缀 `/api/v1`｜JSON｜Sanctum SPA Cookie 会话｜时间为 ISO 8601 带家庭时区偏移

## 1. 通用约定

| 项 | 规则 |
|---|---|
| CSRF | 先 `GET /sanctum/csrf-cookie`，变更请求携带 `X-XSRF-TOKEN` |
| 角色 | `/api/v1/parent/*` 仅家长；`/api/v1/kid/*` 仅孩子会话 |
| 幂等 🔁 | 必须带 `Idempotency-Key: <uuid>`；同键同请求体重放首次响应；同键不同请求体 → 422 |
| 二次验证 🔐 | 需 10 分钟内调用过 `POST /auth/confirm-password`，否则 423 |
| 乐观锁 🔒 | 请求体带 `lock_version`，冲突 409 |
| 隔离 | 不属于当前家庭/孩子、或对孩子不可见的资源一律 **404** |

响应：`{ "data": ..., "meta": {...} }`；错误：`{ "error": { "code": "...", "message": "中文提示", "details": {...} } }`

| HTTP | code |
|---|---|
| 400 | `IDEMPOTENCY_KEY_REQUIRED` |
| 401 | `UNAUTHENTICATED` / `DEVICE_NOT_AUTHORIZED` |
| 403 | `FORBIDDEN` |
| 404 | `NOT_FOUND` |
| 409 | `VERSION_CONFLICT` / `INVALID_STATE` / `ALREADY_DECIDED` |
| 422 | `VALIDATION_FAILED` / `INSUFFICIENT_COINS` / `LIMIT_REACHED` / `IDEMPOTENCY_KEY_REUSED` |
| 423 | `SUDO_REQUIRED` |
| 429 | `TOO_MANY_ATTEMPTS` |

## 2. 第一阶段接口（已规划实现）

### 2.1 系统

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/health` | `{status, database, timezone, time_utc}`，无敏感信息 |

### 2.2 认证

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| POST | `/api/v1/auth/login` | 游客 | 家长登录 `{login, password}`；限流 5 次/15 分钟/账号+IP；失败递增锁定 |
| POST | `/api/v1/auth/logout` | 已登录 | |
| GET | `/api/v1/auth/me` | 已登录 | 用户、角色、家庭（时区）、孩子档案（孩子会话）、`sudo_until` |
| POST | `/api/v1/auth/confirm-password` | 家长 | `{password}` → `{sudo_until}`；限流 |
| GET | `/api/v1/auth/device` | 游客 | 当前设备是否已授权 `{authorized, name}` |
| GET | `/api/v1/auth/kid/profiles` | 已授权设备 | 可登录的孩子 `[{id, nickname, avatar, theme_color, pin_required}]` |
| POST | `/api/v1/auth/kid/login` | 已授权设备 | `{child_id, pin}`；限流 5 次/10 分钟/设备+孩子 |

### 2.3 家长 — 家庭

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/parent/family` | 家庭资料 |
| PATCH | `/api/v1/parent/family` 🔐 | `{name?, timezone?}`（时区修改影响周期计算，需二次验证） |
| GET | `/api/v1/parent/dashboard` | 家长首页：孩子概览、已授权设备数、最近审计（随阶段扩充） |

### 2.4 家长 — 孩子档案

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/parent/children` | 列表（含动态年龄） |
| POST | `/api/v1/parent/children` | `{nickname, avatar, birth_date, grade, theme_color, pin?}` |
| GET | `/api/v1/parent/children/{id}` | 详情 |
| PATCH | `/api/v1/parent/children/{id}` | `{nickname?, avatar?, grade?, theme_color?, status?, preferences?}` |
| PUT | `/api/v1/parent/children/{id}/birth-date` 🔐 | `{birth_date}`（影响内容年龄判断） |
| PUT | `/api/v1/parent/children/{id}/pin` 🔐 | `{pin}`（4–6 位数字）或 `{pin: null}` 关闭 PIN |

### 2.5 家长 — 设备与审计

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/parent/devices` | 已授权设备 |
| POST | `/api/v1/parent/devices` 🔐 | 将**当前浏览器**授权为孩子设备 `{name}`，下发 HttpOnly Cookie |
| DELETE | `/api/v1/parent/devices/{id}` 🔐 | 撤销 |
| GET | `/api/v1/parent/audit-logs` | 分页、按动作筛选 |

### 2.6 孩子

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/kid/home` | 昵称、头像、主题色、年龄、偏好、星球入口列表（含每个模块是否已开放） |
| PATCH | `/api/v1/kid/preferences` | `{animations?, sounds?}`（孩子可改自己的动画/声音开关） |

## 3. 后续阶段接口（规划）

### 第二阶段：成长核心

| 资源 | 家长 | 孩子 |
|---|---|---|
| 任务 | `GET/POST/PUT/DELETE /parent/tasks`（创建/更新支持 `schedules.frequency=weekly|once`、`weekdays`、`due_time`、`end_date`、`with_parent`；更新可同步 steps/schedules 并刷新 pending 实例快照）、`POST /parent/homework/parse`、`POST /parent/homework/confirm`（生成 stepped 任务+当日实例，支持 `with_parent`，默认无需审核）、`/parent/task-instances?date=`、`POST /parent/task-instances/{id}/approve|return` 🔁（仍可用，任务页已去掉审核入口） | `GET /kid/tasks?date=`、`POST /kid/task-instances/{id}/ready|first-step|steps/{sid}/check|self-check|submit` 🔁 |
| 习惯 | `GET/POST/PUT /parent/habits` | `GET /kid/habits`、`POST /kid/habits/{id}/records` 🔁 |
| 账本 | `GET /parent/children/{id}/ledger?asset=xp|coin`、`POST /parent/ledger/{ulid}/reverse` 🔁🔐、`POST /parent/children/{id}/bonus` 🔁🔐 | `GET /kid/wallet` |
| 奖励策略 | `GET/PUT /parent/reward-policies` 🔐 | — |
| 商城 | `GET/POST/PUT/DELETE /parent/rewards`（创建可带 `image` multipart；`POST/DELETE /parent/rewards/{id}/image`；`GET .../image`）、`GET /parent/redemptions` | `GET /kid/shop`、`GET /kid/shop/{id}/image`、`POST /kid/redemptions` 🔁、`POST /kid/redemptions/{ulid}/cancel` 🔁 |
| 审核中心 | `GET /parent/approvals?status=PENDING`、`POST /parent/approvals/{id}/approve|reject|return` 🔁🔒、`GET /parent/approvals/{id}/events` | `GET /kid/requests`（我的申请状态） |

### 第三阶段：生活管理

| 资源 | 家长 | 孩子 |
|---|---|---|
| 书包 | `/parent/items`、`/parent/item-locations`、`/parent/items/{id}/events`、`/parent/item-checklists` | `GET /kid/bag`、`POST /kid/item-checklists/{id}/lines/{lid}` 🔁、`POST /kid/items/{id}/report-missing|found` 🔁 |
| 勇敢探索 | `/parent/explorations`、`/parent/explorations/{id}/events` | `GET/POST /kid/explorations`、`POST /kid/explorations/{id}/events` 🔁 |
| 食物日记 | `/parent/foods`、`/parent/food-records` | `GET /kid/foods`、`POST /kid/food-records` 🔁 |

### 第四阶段：电影星球

| 资源 | 家长 | 孩子 |
|---|---|---|
| 外部检索 | `GET /parent/movies/external-search?q=`（后端代理 TMDB，**仅家长**） | — |
| 电影库 | `POST /parent/movies`（从 TMDB 导入或手动）、`PUT /parent/movies/{id}`、`POST /parent/movies/{id}/reviews` 🔐（安全状态、适龄范围）、`PUT /parent/movies/{id}/approvals/{child}` | `GET /kid/movies?q=`（仅可见集合内搜索）、`GET /kid/movies/{id}`（不可见 → 404） |
| 电影系列 | `GET/POST /parent/movie-collections`、`GET/PUT/DELETE /parent/movie-collections/{id}`（删除系列只取消分组，不删电影与观看记录）、`POST .../merge`、`POST .../split`、`PUT .../items`、`PUT .../preferences`、`POST .../approvals`；`DELETE /parent/movies/{id}`（软删除，孩子端不再展示）；推荐 `GET /parent/movies/recommendations` 按系列聚合 | `GET /kid/movies` / `GET /kid/movie-library` 支持系列折叠：可见 ≥2 部才返回 `collection` 摘要；`GET /kid/movie-collections/{id}` 仅含可见成员（仅 1 部可见时 404）；不可见 → 404 |
| 申请 | `GET /parent/movie-requests` → 审核中心 | `POST /kid/movie-requests` 🔁（`movie_id` 或 `free_text`） |
| 观影 | — | `GET /kid/movie-library`、`POST /kid/movie-library/{id}/slot` 🔁、`POST .../sessions/start|stop` 🔁、`PUT .../progress`（只改位置）、`POST .../complete|pause|stop` 🔁、`POST .../review` 🔁 |
| 时间额度 | `GET/PUT /parent/screen-time/policies` 🔐、`POST /parent/screen-time/adjustments` 🔐、`GET /parent/screen-time/pending` | `GET /kid/screen-time` |

> 电影系列（Movie Collection / Franchise）≠ 成长收藏馆 `GET /kid/collection`。系列折叠默认收起；展开状态由前端按页面记住。单部电影不强制包装成系列。

### 第五阶段：英语探索星球

节目在电视上观看，系统只负责登记与奖励。

家长：`GET/POST /parent/english/shows`、`PATCH /parent/english/shows/{id}`；登记确认走统一审核中心（`approval_requests.type = english_log`，可修正时长）；`GET /parent/english/logs?child_id=&from=&to=`。
孩子：`GET /kid/english/shows`（本家庭启用的节目）、`POST /kid/english/logs` 🔁（`show_id`、`watch_date` 今天/昨天、`minutes`、`self_chosen`、`liking`、可选 `words_learned`）、`GET /kid/english/logs`（自己的登记与确认状态）、`DELETE /kid/english/logs/{id}`（仅撤回自己待确认的登记）。

### 第六阶段：报告与设置

`GET /parent/reports/daily|weekly|growth`、`POST /parent/exports` 🔐、`DELETE /parent/children/{id}` 🔐（软删除 + 30 天清除）、`GET /kid/collection`（成长收藏馆）。
