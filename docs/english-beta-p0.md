# 英语星球 Beta · P0

独立入口：儿童 `/kid/english-beta`，家长 `/parent/english-beta`。现有英语探索入口、表和积分账本不变。家庭星球开关 `planets.english_beta` 可即时隐藏入口并禁止儿童 API。

## 数据与设置

迁移 `2026_10_25_120000_create_english_beta_tables.php` 创建独立会话、轮次、场景任务、三阶段词汇表。家庭设置默认每次 20 分钟、每周 5 天；家长可在 Beta 页面修改。每周天数是练习建议，P0 不强制锁定日期。会话到时拒绝新轮次。P0 不发放积分、经验或成长币。

AI 服务商选项：Qwen、豆包、模拟。Qwen 仅在家长开启 AI 且现有家庭 AI 设置中有 Qwen 密钥时调用。密钥只从加密的 `ai.qwen_api_key` 设置读取，不写入仓库或 Beta 记录。豆包是预留接口，当前走模拟回复。Qwen 网络或内容异常也回退模拟模式。儿童输入限长、限流；链接和联系方式请求不存入会话；家长报告仅展示汇总与掌握状态，不展示对话正文。

## 验证

在测试数据库运行 `php artisan test tests/Feature/Growth/EnglishBetaTest.php tests/Feature/Growth/PlanetSettingsTest.php`；前端运行 `vue-tsc --noEmit` 与 `vite build --configLoader runner`。上线前还需在目标环境执行迁移，并用真实儿童与家长会话验证浏览器交互。当前未部署。

## 回滚

1. 家长先关闭「英语星球 Beta」，即刻阻止儿童使用并保留记录。
2. 回滚应用代码到引入 Beta 前的版本。旧英语探索和其他星球保持原有表与路由。
3. 若确定不再保留 Beta 数据，先备份四张 `english_beta_*` 表，再针对该迁移执行回滚；迁移的 `down()` 仅删除这四张 Beta 表。不要对整套数据库运行无范围的回滚。
