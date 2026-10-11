> ⚠️ **V0 规划文档**（2026-10-11 首版需求）。已被 V1.0 需求部分取代：与 `architecture.md`、`database.md`、`api.md`、`security.md`、`development-plan.md` 冲突之处以 V1.0 文档为准；本文件将在对应开发阶段更新。

# 成长星球 — 测试计划

> 原则：**所有测试必须真实执行并报告结果**；阶段汇报中列出运行的命令、通过/失败数量和失败原因，不得声称未运行的测试已通过。

## 1. 测试分层

| 层 | 工具 | 运行环境 | 命令 |
|---|---|---|---|
| 后端单元测试 | Pest | `app` 容器 | `make test-backend` |
| 后端功能测试（HTTP + 数据库） | Pest + MySQL 8.4 测试库 | 容器 | 同上 |
| 后端并发测试 | Pest + 多进程（Symfony Process） | 容器 | `make test-concurrency` |
| 架构测试 | Pest Arch | 容器 | 包含在 `make test-backend` |
| 静态分析 | Larastan、Pint、ESLint、vue-tsc | 容器/本机 | `make lint` |
| 前端组件测试 | Vitest + Vue Test Utils + happy-dom | 本机或 node 容器 | `make test-frontend` |
| 端到端测试 | Playwright | 本机 + Compose 栈 | `make test-e2e` |
| 备份恢复测试 | Bash + 临时 MySQL 容器 | Docker | `make test-backup` |
| 全部 | — | — | `make test` |

### 1.1 后端测试数据库

- 使用独立数据库 `growth_planet_test`（Compose 中 MySQL 服务初始化脚本创建）。
- 功能测试使用 `RefreshDatabase`（事务回滚）；并发测试需要真正提交，使用 `DatabaseMigrations` + 独立连接，测试后清理。
- 时间：所有测试通过 `Clock` 冻结时间（`travelTo`），默认 `2026-10-12 08:00 Asia/Shanghai`。

## 2. 必测场景（需求 §14 对应）

| 编号 | 场景 | 层 | 验证方法 |
|---|---|---|---|
| T-PTS-01 | 重复提交任务不会重复发分 | 功能 | 同一 `Idempotency-Key` 提交两次 → 第二次返回首次响应；不同键并发提交 → 一次成功一次 409；审核后流水仅 1 条 |
| T-PTS-02 | 重复审核不会重复发分 | 功能 + 并发 | 两个进程同时 approve 同一实例 → 1 次成功、1 次 409；`point_transactions` 中该审核相关仅 1 条 Coins + 1 条 XP |
| T-SHOP-01 | 重复兑换不会重复扣分 | 功能 | 同一键重复申请 → 1 个订单；同一订单重复审批 → 1 条 `redemption` 流水 |
| T-SHOP-02 | 积分不足时兑换失败 | 功能 | 余额 10、价格 20 → 422 `INSUFFICIENT_BALANCE`，无订单、无流水 |
| T-SHOP-03 | 并发兑换不出现负余额 | 并发 | 余额 100，10 个进程同时审批 10 个价格 30 的订单 → 恰好 3 个成功，最终余额 10，`balance == SUM(amount)` |
| T-PTS-03 | 家长撤销积分交易 | 功能 | 撤销 → 生成反向流水，原流水不变；重复撤销 → 409；撤销反向流水 → 422；余额不足以撤销 → 422 |
| T-PTS-04 | 历史流水不可修改 | 功能 | 直接 `UPDATE/DELETE point_transactions` → 触发器异常 |
| T-PTS-05 | 每日奖励上限与单次扣分上限 | 单元 + 功能 | 上限截断、`meta.capped_from` 记录；超扣分上限 → 422 |
| T-PTS-06 | 无自动扣分路径 | 架构 | 断言只有 `ConfirmPointAdjustment` 引用 `TransactionType::RuleDeduction`；Habit 模块不依赖扣分接口 |
| T-TASK-01 | 修改任务模板不影响历史 | 功能 | 生成实例 → 修改模板标题/积分/步骤 → 历史实例快照、步骤、已发积分不变；次日新实例使用新版本 |
| T-TASK-02 | 跨天任务生成正确 | 单元 + 功能 | 冻结时间在 `23:59:59+08:00` 与 `00:00:00+08:00`（UTC 15:59/16:00）之间；`task_date` 按家庭时区；`day_boundary=04:00` 时 02:00 仍属前一天；重复运行生成器不产生重复实例 |
| T-TASK-03 | 状态机 | 单元 | 所有合法/非法迁移；过期 → 补交标记 `is_late` |
| T-TASK-04 | 撤销审核 | 功能 | 撤销 → 反向流水 + XP 冲正（可配置关闭）→ 状态 `submitted` → 可再审核 |
| T-TASK-05 | 奖励计算 | 单元 | 自主等级、质量、可选步骤、自查加成组合；提醒多不会低于 base |
| T-SCH-01 | 课程表例外日期正确 | 单元 | 放假无课、调休按指定星期、临时课表、孩子专属例外优先、课程表有效期切换 |
| T-ITEM-01 | 物品丢失和找回记录完整 | 功能 | pending → suspected → searching → found 每步一个事件，前后值正确；投影与重放一致 |
| T-ITEM-02 | 未确认不等于丢失 | 功能 | 放学核对"不确定" → `confirmation_state=pending`，`missing_state=none` |
| T-ITEM-03 | 孩子不能标记疑似遗失 | 功能 | 孩子提交 `suspected_missing` → 403 |
| T-ITEM-04 | 书包清单生成 | 单元 + 功能 | 科目物品、每日必带、临时要求合并去重；留校物品排除；重新生成保留已确认结果 |
| T-ITEM-05 | 物品事件不可覆盖 | 功能 | UPDATE/DELETE `item_events` → 触发器异常 |
| T-SEC-01 | 孩子不能访问家长管理接口 | 功能 | 遍历路由表中全部 `parent/*` 路由，以孩子会话请求 → 全部 403；未登录 → 401 |
| T-SEC-02 | 跨家庭隔离 | 功能 | 家庭 A 家长访问家庭 B 资源 → 404 |
| T-SEC-03 | 二次验证 | 功能 | 敏感接口未二次验证 → 423；验证后 10 分钟内可用，过期后 423 |
| T-SEC-04 | 登录/PIN 限流 | 功能 | 超过阈值 → 429；锁定时间递增 |
| T-SEC-05 | 未授权设备孩子登录 | 功能 | 无设备 Cookie / 已撤销设备 → 401 |
| T-UI-01 | 手机和平板页面正常 | E2E | Playwright projects：iPhone 14、iPad (gen 7) 横/竖、Pixel 7、Galaxy Tab S4、Desktop Chrome；核心流程 + 无横向滚动断言 + 触控目标尺寸断言 + 截图 |
| T-BAK-01 | 数据备份能够恢复 | 脚本 | 生成测试数据 → `backup.sh` → 新建空库 → `restore.sh` → 行数、校验和、积分对账、物品投影全部一致 |

## 3. 前端组件测试

| 组件 | 断言 |
|---|---|
| `TaskCard`（孩子端） | 各状态显示、开始/提交按钮可用性、必选步骤未完成时提交禁用 |
| `CoinBurst` 动画 | `prefers-reduced-motion` 时不播放 |
| `ChecklistLine` | 放好了/找不到交互，孩子端无"疑似遗失"选项 |
| `WishProgress` | 进度计算（可用余额/价格），封顶 100% |
| API 客户端 | 自动生成并在重试时复用 `Idempotency-Key`；423 时弹出二次验证后重放 |
| `ReviewDialog`（家长端） | 自主等级建议值随提醒次数变化，可手动修改 |
| 时间显示工具 | 设备时区为 UTC/美国时区时仍按 `Asia/Shanghai` 显示 |

## 4. E2E 核心流程

1. 家长登录 → 创建任务模板 → 授权孩子设备。
2. 孩子 PIN 登录 → 开始任务 → 勾选步骤 → 提交 → 完成动画。
3. 家长记录提醒 → 审核通过 → 孩子端金币增加。
4. 家长撤销审核（二次验证） → 孩子端金币恢复。
5. 生成次日书包清单 → 孩子打包（一项找不到）→ 家长审核中心看到问题项。
6. 放学核对"不确定" → 家长标记疑似遗失 → 孩子找回。
7. 孩子许愿 → 家长转为商品 → 孩子兑换 → 家长审批 → 履约。
8. 孩子会话直接访问 `/parent` 路由 → 被拦截；直接调用家长 API → 403。

## 5. 阶段汇报格式

每阶段结束汇报：

```
## 测试结果（真实执行）
- 命令：make test-backend
  结果：Tests: 128 passed, 0 failed (412 assertions)  Duration: 21.3s
- 命令：make test-frontend
  结果：...
- 未运行：Playwright（原因：本阶段无 UI 变更 / 环境缺少 xxx）
- 已知失败：无 / 列表
```

## 6. 覆盖率目标

- 积分、任务状态机、清单生成、课程表解析：行覆盖率 ≥ 90%。
- 后端整体 ≥ 75%；前端 store 与工具函数 ≥ 80%。
- 覆盖率需要 PCOV 扩展，在开发镜像中安装。
