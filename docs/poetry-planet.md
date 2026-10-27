# 诗词星球

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

Kid：`GET /api/v1/kid/poetry`（枢纽）· `GET .../required|recommend|challenge` · `GET .../poems/{id}`（含 `line_meanings`、`voice_available`）· `GET .../poems/{id}/audio?clip=full|line-0|story|meaning-0`（朗诵资料库，优先 ElevenLabs 表情朗读并落盘）· `POST .../audio/warm` · `POST .../read-aloud`（逐句跟读完成）· `POST .../transcribe`（中文 ASR）· `POST .../recite`（`mode=recite|review|challenge|read_aloud`，朗读/背诵 AI 打分）

孩子端诗词页布局：左栏题目/诗句（🔊 全诗与逐句）+ 逐句跟读/朗读/背诵；右栏插图与小故事；底栏点击诗句展示含义并自动朗读。

## 朗诵资料库

- 表 `poetry_audio_cache` + 文件 `storage/app/public/poetry-audio/{poem_id}/{clip}.mp3`
- `full` 使用有停顿的表情朗诵稿（题目、朝代、作者 + 联句节奏），优先 ElevenLabs（`poetry.audio.*` / 家庭 `tts.elevenlabs_api_key`），失败再回退通用 TTS
- 同一 `script_hash` + voice 命中后只读本地文件，不再计费

Parent：`GET /api/v1/parent/poetry`、`PUT .../settings`、`GET .../search`、`POST .../assignments`
