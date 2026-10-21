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

生成结果只是草稿。每个条目须对照上游主题核对事实和年龄适宜性，必要时修订中文标题及正文，再将该条目的 `reviewed` 设为 `true`；只有已核对条目会在孩子端出现。使用 `--force` 重新生成条目会重置其审核状态。当前首批已核对 13 篇，其余条目仍是草稿。孩子端目前是文字加 emoji，未提供插图。

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
