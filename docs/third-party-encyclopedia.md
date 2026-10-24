# 科学小百科第三方数据说明

成长星球「科学小百科星球」使用 [Marble Skill Taxonomy](https://github.com/withmarbleapp/os-taxonomy)（os-taxonomy）的 **Science** 主题子集作为知识结构与英文原文来源。

## 版本与文件

- 上游：Marble Skill Taxonomy **v1**
- 本地裁剪：`backend/app/Modules/Encyclopedia/data/marble/`
  - `topics.science.json` — Science 微主题
  - `dependencies.science.json` — Science 内部先修边
  - `zh-overlay.json` — 成长星球中文儿童文案（AI 草稿与已核对条目）
  - `NOTICE` — 上游许可与归因

重新裁剪：

```bash
node scripts/import-marble-science.mjs --download
```

用家庭已配置的 AI 生成/补全中文词条：

```bash
docker compose exec app php artisan encyclopedia:prepare-zh --family=1
```

生成结果写入 overlay。孩子端发布条件：**有非空中文 `name` + `body` 即可**（不再要求 `reviewed`）。`reviewed` 仅作质量标记。命令还会尝试生成：

- `words`：3–6 个儿童适宜英文生词（`word` + 中文 `meaning`）
- `video_query`：检索用关键词（**不会**自动写入外链）

短视频需人工写入：

```json
"video": { "provider": "bilibili", "id": "BVxxxxxxxxxx" }
```

或 `"provider": "youtube", "id": "xxxxxxxxxxx"`。孩子端按年龄带过滤主题；已发布且同年龄可见的先修未读时主题会锁定。文章展示中文正文 + 下方英文；点英文词可加入英语星球当日生词包。

## 许可

| 层 | 许可 |
|---|---|
| 数据库结构、ID、主题关系 | ODbL 1.0 |
| Marble 撰写的英文主题文案等 | CC BY-SA 4.0 |
| 课程标准原文 | 见上游 `PROVENANCE.md`（本产品未打包课程标准全文） |

产品内使用属于 ODbL 意义上的 *produced work*。若对外再分发**衍生的 taxonomy 数据库**本身，需遵守 ODbL share-alike。

## 归因（须展示）

> Marble Skill Taxonomy (v1) · © Generative Spark, Inc. (Marble) · https://withmarble.com · licensed under ODbL 1.0 (database) and CC BY-SA 4.0 (content).

孩子端百科页脚已展示简短归因；详细说明见本文件与 `NOTICE`。
