# 成长星球 — 分阶段开发计划（V1.0）

## 1. 原则

- 按需求 §19 的六个阶段推进；每阶段交付"迁移 + 后端 + 前端 + 测试 + 文档"可运行的纵向切片。
- 未开放模块在孩子端显示"即将开放"，不显示假数据。
- 每阶段结束：运行测试与构建 → 修复 → 更新文档 → Git commit → 汇报（已完成 / 未完成 / 启动验证方式 / 下一阶段）。
- 涉及儿童安全、隐私或不可逆数据操作的重大歧义，先询问家长（见 §4）。

## 2. 阶段与范围

| 阶段 | 内容 | 状态 |
|---|---|---|
| 0 | 架构规划文档 | ✅ |
| 1 | 基础框架：Docker Compose、Laravel API、Vue 前端、MySQL、登录与权限、家庭与孩子档案、基础导航、家长端与孩子端首页 | ✅ 已完成 |
| 2 | 成长核心：任务、习惯、XP、金币账本、奖励商城、统一审核中心 | ✅ 已完成 |
| 3 | 生活管理：书包星球、勇敢探索、食物探索日记 | ✅ 已完成 |
| 4 | 电影星球 + 统一屏幕时间 | ✅ 已完成 |
| 4.1 | 电影系列（折叠与管理）：TMDB collection 识别、家长合并/拆分、列表折叠、推荐聚合、孩子端防泄漏、系列统计 | ✅ 已完成（迁移 + MovieCollectionService + 家长/孩子 API + 折叠 UI + Feature 测试） |
| 5 | 英语探索星球（电视观看后自行登记、家长确认、奖励） | ✅ 已完成 |
| 6 | 成长报告、成长收藏馆、PWA、动画与交互、移动端、安全测试、性能、部署文档 | ✅ 已完成（PWA 基础壳缓存；Playwright/全设备矩阵仍延后） |

## 3. 第一阶段实施计划

### 3.1 范围

| 类别 | 内容 |
|---|---|
| 基础设施 | `deploy/docker`（PHP 8.4-FPM 镜像、Nginx 配置含安全头）、`compose.yaml`（mysql、app、nginx）、`Makefile`、`.env.example` |
| 后端骨架 | Laravel 安装；UTC 时区；MySQL utf8mb4；统一 JSON 错误格式；`/api/health`；模块目录 `app/Modules/{Core,Identity}` |
| Core | `Clock`（家庭时区、今日、周范围、年龄）、`AuditLogger`、`SettingsRegistry`、`audit_logs` 只追加触发器 |
| Identity | 迁移：`families`、`users`、`family_members`、`child_profiles`（生日加密）、`trusted_devices`、`system_settings`、`audit_logs`、`notifications`、`idempotency_keys`；家长登录/退出/锁定；二次验证；设备授权；孩子 PIN 登录；角色中间件；孩子档案 CRUD；`family:create` 命令；`DemoSeeder`（仅本地） |
| 前端 | Vite + Vue 3 + TS strict + Pinia + Router + vue-i18n + Element Plus（家长端按需）；API 客户端（CSRF、423 二次验证重试）；家长登录、孩子头像 + PIN 登录；家长端布局（桌面侧栏 / 手机底部导航）与首页、孩子管理、设备管理、审计日志、家庭设置；孩子端星球首页（11 个入口，未开放模块显示"即将开放"）；动画/声音开关 |
| 测试 | PHPUnit 功能测试（认证、限流、二次验证、设备、孩子越权、跨家庭隔离、路由遍历、年龄计算、时区、审计只追加）；Vitest（API 客户端、年龄/时间工具、星球首页组件）；`vue-tsc` 类型检查与生产构建 |

### 3.2 不在第一阶段

积分、任务、审核中心、商城（第二阶段）；PWA 清单与离线（第六阶段）；Playwright（第二阶段起引入，第六阶段全设备矩阵）。

### 3.3 验收

- `make up && make migrate && make test` 全部通过。
- 浏览器访问 `http://localhost:8088`：家长登录 → 新建孩子 → 授权当前设备 → 退出 → 孩子选择头像输入 PIN → 进入星球首页。
- 孩子会话调用任何 `/api/v1/parent/*` 返回 403。

### 3.4 实施结果与偏差

- 已达成 3.3 全部验收项：后端 54 个测试（224 个断言）通过；前端 13 个 Vitest 用例、`vue-tsc`、ESLint、生产构建通过；浏览器端完整走通上述流程（含错误密码、错误 PIN、二次验证弹窗与重试、孩子访问家长页面被重定向、手机宽度布局）。
- `idempotency_keys` 表已建，但幂等中间件推迟到第二阶段与金币账本一起实现（第一阶段没有会产生重复副作用的写接口）。
- 前端组件测试覆盖 `PinPad`、`PlanetTile`；页面级端到端测试按 3.2 在第二阶段引入 Playwright。

### 3.5 第二阶段实施结果

- 后端模块：`Ledger`、`Approval`、`Task`、`Habit`、`Shop`；幂等中间件 `Idempotency-Key`；孩子建档时自动开 XP/金币账户；家庭创建时种子奖励策略。
- 前端：家长端审核中心 / 任务 / 习惯 / 商城；孩子端今日任务、习惯打卡、积分钱包、奖励商城；星球首页 Phase 2 入口已开放。
- 测试：后端 63 通过；前端 typecheck / lint / Vitest 15 / 生产构建通过。
- 简化：分步任务接口已就绪，主流程以标准每日任务验收；页面级 Playwright 仍延后。

## 3.6 阶段 4.1：电影系列（已完成）

> 文档：`database.md` §10（`movie_collections*`）、`architecture.md` ADR-010、`api.md` 第四阶段系列行、`security.md` §5。与「成长收藏馆」命名无关。

| 类别 | 内容 |
|---|---|
| 数据 | 迁移 `2026_10_11_410000_create_movie_collection_tables`：`movie_collections`、`movie_collection_items`、`movie_collection_preferences`；未改现有进度/会话/奖励表 |
| 后端 | `MovieCollectionService`；家长 CRUD / 合并 / 拆分 / 排序 / 批量批准 / TMDB sync；孩子分组目录与系列详情防泄漏；导入时按 TMDB `belongs_to_collection` 关联（禁止标题相似） |
| 前端 | 孩子端可见电影系列折叠（默认收起、动画、`sessionStorage`）；家长端推荐聚合 + 手动创建系列 |
| 测试 | `MovieCollectionTest`（折叠、防泄漏、进度/奖励独立、锁定字段、推荐聚合） |
| 兼容 | 无系列电影行为不变；`GET /kid/movies?flat=1` 可取扁平列表 |

**验收测试**

- ✅ 同系列正确折叠；展开后按 `sort_mode` 排序正确。
- ✅ 只批准第一部时不泄露第二部（列表/搜索/详情/系列 API/统计）。
- ✅ 不同电影分别记录观看进度；系列内奖励独立。
- ✅ 未批准续集不能进入孩子端。
- ✅ 家长修改系列后观看记录仍在；TMDB 系列数据更新不覆盖家长手动调整。

## 4. 待家长确认的问题（不阻塞第一阶段）

| 问题 | 默认值（可配置） | 何时需要 |
|---|---|---|
| ~~英语视频的观看方式~~ | **已确认（2026-10-11）**：孩子在电视上观看；英语星球只做"孩子自行登记 → 家长确认 → 发放奖励"，不接入 YouTube | — |
| 英语登记奖励默认值 | 每条确认的登记 +5 金币 +5 XP；主动选择看英语额外 +3 金币；每天最多奖励 2 条 | 第五阶段 |
| 是否提供 TMDB API Key | 无 Key 时仅手动录入电影 | 第四阶段前 |
| 电影首次开始奖励等默认值 | 采用需求中的默认值（+3 / +5 / +5） | 第四阶段 |
| 每周电影额度、每日英语额度默认值 | 电影 180 分钟/周，英语 20 分钟/天 | 第四、五阶段 |
