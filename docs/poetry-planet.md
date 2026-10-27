# 诗词星球

## 诗词朗读（Qwen-Audio 3.1 TTS Flash）

家长在「诗词星球 → 设置」中选择阿里云朗读，使用现有通义 API Key 即可；工作空间 ID 可留空，此时使用阿里云普通 DashScope 域名。可调整温怀清音色、语速、风格及逐句跟读停顿。Key 使用现有加密设置存储，页面只显示配置状态，留空保存不会覆盖原 Key。也可以在服务端设置 `DASHSCOPE_API_KEY`，`POETRY_QWEN_WORKSPACE_ID` 仅在使用工作空间专用域名时需要。原有 ElevenLabs 可在家长页手动选择；Qwen 失败时不自动换音色。音频下载到本地后按家庭缓存，家长可在本页查看数量并清除本家庭的缓存。

部署时更新后端与前端，保留现有 `.env` 和持久化 `storage/app/poetry-library`，运行常规数据库迁移，检查 API Key 所属区域。新设置沿用 `system_settings`，无需专用迁移。可用家长页「试听」验证请求成功，再在孩子页打开诗词检查整首与逐句播放。试听会调用付费合成接口。

一年级古诗词背诵与复习板块：诗泉数据源、Qwen 故事/纠错、可切换生图 Provider + 缓存、艾宾浩斯复习。

## 模块位置

- Backend: `backend/app/Modules/Poetry/`
- Kid UI: `frontend/src/kid/pages/KidPoetryPage.vue`
- Parent UI: `frontend/src/parent/pages/PoetryPage.vue`
- Config: `backend/config/poetry.php`
- Planet key: `poetry_planet`

## 数据库

| 表 | 用途 |
| --- | --- |
| `poetry_poems` | 诗词主表（builtin 一年级精选 + 诗泉缓存） |
| `poetry_poem_enrichments` | 儿童化故事 + 生图 prompt（按诗缓存） |
| `poetry_image_cache` | 按「诗 + provider」缓存图片 URL，避免重复计费 |
| `poetry_assignments` | 家长布置的背诵任务 |
| `poetry_recitation_records` | 跟读 / 背诵 / 复习记录 |
| `poetry_review_plans` | 艾宾浩斯复习计划 |

## 生图适配器

`ImageProvider` 接口 + `ImageProviderManager`：

- `mock` — 默认：一年级精选优先用课本风水墨插图（`data/illustrations` → 前端 `/poetry-art/{key}.jpg`）；其他诗回退程序化 SVG
- `wanx` — 通义万相（DashScope）

家庭设置 `poetry.image_provider` 切换。新增 Flux / DALL·E 等只需实现接口并注册。

首次打开诗词：若走 mock 且有精选插图则直接缓存静态 URL；否则 Qwen（或本地回退）生成 image prompt → 调生图 API → 写入 `poetry_image_cache`；之后只读缓存。

## Qwen Prompt

见 `PoetryAiCoach`：

- `ENRICHMENT_SYSTEM_PROMPT` — 白话小故事 + 生图提示词
- `RECITATION_SYSTEM_PROMPT` — 背诵纠错与鼓励评语

`poetry.allow_ai=false` 时走本地启发式（字级比对），不产生 API 费用。

## 复习算法

首次成功背诵锚定日 `D`，间隔默认 `[1, 2, 4, 7, 15]` 天：

`next = D + intervals[step]`；复习通过后 `step++`，走完标记 `mastered`。

## 孩子端三入口

1. **要求诗词** — 家长搜索诗泉/精选并布置截止日期，孩子在此完成
2. **推荐诗词** — 按年龄/年级每周一首（`poetry_weekly_recommendations`），保留历史
3. **诗词挑战** — 从已成功背诵的诗中挑选，盲背挑战

## API

Kid：`GET /api/v1/kid/poetry`（枢纽）· `GET .../required|recommend|challenge` · `GET .../poems/{id}`（含 `line_meanings`、`author_zh`、`author_learned_poems`、`voice_available`）· `GET .../poems/{id}/audio?clip=full|line-0|story|author|meaning-0`（朗诵资料库元数据，按家庭缓存 Qwen 音频）· `GET .../audio/file?clip=`（鉴权流式播放 MP3）· `POST .../audio/warm` · `POST .../read-aloud`（逐句跟读完成）· `POST .../transcribe`（中文 ASR）· `POST .../recite`（`mode=recite|review|challenge|read_aloud`，朗读/背诵 AI 打分）

孩子端诗词页布局：左栏题目/诗句（🔊 全诗与逐句）+ 逐句跟读/朗读/背诵；右栏固定比例插图（完整展示）、小故事与作者简介（均可 🔊 用同一朗读模型；作者简介下列出已背会的同作者其他诗题）；右侧内容变长时左栏诗词卡片同步拉高对齐；底栏点击诗句展示含义并自动朗读。

## 朗诵资料库（永久静态资源）

布置与每周推荐生成后，后台自动预热该诗的语音包并下载到服务端：`full` + `author` + 各 `line-*` + 各 `meaning-*`。孩子端使用时直接静态播放，不再现场合成。Qwen 片段按家庭隔离保存，原有 ElevenLabs 种子仍可共享。故事（`story`）仍按家庭即时合成。

| 位置 | 用途 |
| --- | --- |
| `Modules/Poetry/data/audio/{key}/{clip}.mp3` | 可随镜像/仓库分发的种子（git） |
| `storage/app/poetry-library/audio/{key}/…` | 运行时持久卷（部署后继续累积） |
| 表 `poetry_audio_cache` | 元数据与命中索引 |

- `{key}` 优先用诗的 `external_id`（如 `jing-ye-si`）
- `full` 使用有停顿的朗诵稿（题目、朝代、作者 + 联句节奏）；默认用 Qwen-Audio 3.1 TTS Flash 和温怀清（温怀清不支持 Plus）；失败时提示配置问题，不自动换音色
- 孩子端通过 `GET .../poems/{id}/audio/file?clip=` 流式播放（nginx SPA 不挂载 `/storage`）
- 触发点：家长 `POST .../assignments`；孩子端首次生成本周推荐（`currentFor`）

### 部署时下载 / 同步资源

```bash
# 容器启动会自动 sync 种子 → 持久卷
php artisan poetry:audio-library sync

# 本机生成一年级精选后打包给其他环境
php artisan poetry:audio-library warm --grade1 --family=1
php artisan poetry:audio-library export --file=storage/app/poetry-audio-library.zip

# 新环境导入
php artisan poetry:audio-library import --file=poetry-audio-library.zip --seeds
```

Parent：`GET /api/v1/parent/poetry`、`PUT .../settings`、`GET .../search`、`POST .../assignments`（布置后自动预热朗读库）
