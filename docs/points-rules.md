> ⚠️ **V0 规划文档**（2026-10-11 首版需求）。已被 V1.0 需求部分取代：与 `architecture.md`、`database.md`、`api.md`、`security.md`、`development-plan.md` 冲突之处以 V1.0 文档为准；本文件将在对应开发阶段更新。

# 成长星球 — 积分经济规则

## 1. 两种资产

| | 成长经验 XP | 可消费金币 Coins |
|---|---|---|
| 用途 | 等级、称号、成就 | 商城兑换 |
| 来源 | 任务、家长奖励、习惯正向行为、成就 | 同左 |
| 减少 | **不因扣分或消费减少**；仅家长撤销"误审核"时可冲正（`xp_correction`，需二次验证，可在设置中关闭） | 兑换、家长审核的规则性扣分、撤销 |
| 负余额 | 禁止 | 禁止 |

两种资产各有独立账户（`point_accounts`，每个孩子各 1 个），独立流水，互不换算。

## 2. 流水类型

| type | 资产 | 方向 | 产生者 | 说明 |
|---|---|---|---|---|
| `task_reward` | XP / Coins | + | 任务审核通过（或 auto 审核） | |
| `parent_bonus` | XP / Coins | + | 家长奖励确认 | |
| `habit_bonus` | XP / Coins | + | 习惯观察奖励 | 只奖励替代行为和自我觉察 |
| `achievement_bonus` | XP / Coins | + | 获得徽章时（可配置） | |
| `rule_deduction` | Coins | − | 家长扣分确认 | 必须关联规则、原因、确认人 |
| `redemption` | Coins | − | 兑换审批通过 | |
| `refund` | Coins | + | 已扣款订单取消 | |
| `reversal` | Coins | ± | 家长撤销流水 | 与原流水金额相反 |
| `xp_correction` | XP | − | 家长撤销误审核 | 仅冲正对应的 `task_reward` XP |

## 3. 任务奖励计算

```
coins = base_coins
      + Σ 已完成可选步骤 bonus_coins
      + autonomy_rule.coin_bonus[autonomy_level]
      + autonomy_rule.quality_coin_bonus[quality]
      + (check_homework 且 self_checked ? autonomy_rule.self_check_bonus : 0)

xp    = base_xp + autonomy_rule.xp_bonus[autonomy_level]
```

- 所有加成配置值必须 ≥ 0（校验层强制），因此计算结果永远 ≥ base，**提醒多只是少了加成，不会扣分**。
- 漏题数（`missed_questions`）只记录，不参与计算（原则 P1）。
- 自主程度建议值由提醒次数按 `level_thresholds` 推算，家长审核时可修改，以家长确认为准。
- 计算明细写入 `task_reviews.calc_detail`，孩子端可展示"为什么得到这些金币"。

**示例**（默认规则：自主加成 L3=+3、L2=+1、L1/L0=0；质量加成 3 星 +1）：

| 情况 | base | 自主 | 质量 | 合计 |
|---|---|---|---|---|
| 0 次提醒自己开始作业，认真完成 | 5 | +3 | +1 | 9 |
| 提醒 1 次后开始 | 5 | +1 | +1 | 7 |
| 提醒 3 次后开始 | 5 | 0 | +1 | 6 |
| 家长陪同完成 | 5 | 0 | 0 | 5 |

## 4. 上限

| 设置键 | 默认 | 说明 |
|---|---|---|
| `points.daily_task_coin_cap` | 50 | 每个孩子每业务日 `task_reward` 金币上限；超出部分截断，`meta.capped_from` 记录原值 |
| `points.daily_cap_includes_parent_bonus` | false | 家长手动奖励是否计入上限 |
| `points.single_bonus_cap` | 50 | 单次家长奖励上限 |
| `points.single_deduction_cap` | 10 | 单次扣分上限 |
| `points.daily_deduction_cap` | 20 | 每日扣分合计上限 |
| `points.allow_xp_correction` | true | 是否允许 XP 冲正 |
| `shop.unfulfilled_alert_days` | 7 | 未履约订单提醒天数 |

修改上限属于敏感操作（🔐），写审计日志。

## 5. 一致性与并发

### 5.1 记账算法（`PointsLedger::post()`）

```
前置：调用方已开启事务（运行时断言）

1. 若 point_transactions 中已存在 (family_id, idempotency_key) → 直接返回该流水（幂等重放）
2. SELECT * FROM point_accounts WHERE id = ? FOR UPDATE      -- 行锁
3. new_balance = balance + amount
4. 若 new_balance < 0 → 抛 InsufficientBalance（整个事务回滚）
5. 若为奖励 → 查当日已发（同一事务内 SUM，已受账户行锁保护）→ 截断
6. INSERT point_transactions (..., balance_after = new_balance)
   -- UNIQUE(family_id, idempotency_key) 冲突 → 捕获后重新查询返回（并发重放）
7. UPDATE point_accounts SET balance = new_balance, lifetime_*, lock_version = lock_version + 1
8. 事务提交后分发 PointTransactionPosted
```

- 涉及多个账户（同时发 XP 和 Coins）时，按 `account_id` 升序加锁，避免死锁。
- 捕获死锁（SQLSTATE 40001 / 1213）自动重试最多 3 次。
- 数据库 `CHECK (balance >= 0)` 是最后防线：即使应用层出错，也不会写入负余额。

### 5.2 业务幂等键规则

| 场景 | 业务幂等键 | 防止 |
|---|---|---|
| 任务奖励 | `task_review:{review_id}:{asset}` | 重复审核发分 |
| 任务状态迁移 | `UPDATE task_instances SET status='completed' WHERE id=? AND status='submitted' AND lock_version=?`，影响行数为 0 → 409 | 重复提交、重复审核、并发审核 |
| 撤销审核 | `task_reviews.revokes_review_id` UNIQUE；流水 `reversal_of_id` UNIQUE | 重复撤销 |
| 家长奖励/扣分 | `adjustment:{request_id}` | 重复确认 |
| 兑换扣款 | `redemption:{order_id}:debit` | 重复兑换扣款 |
| 兑换退款 | `redemption:{order_id}:refund` | 重复退款 |
| 撤销流水 | `reversal:{original_txn_id}` + `reversal_of_id` UNIQUE | 重复撤销 |
| 习惯奖励 | `habit_obs:{observation_id}` | 重复奖励 |

请求层 `Idempotency-Key` 处理"用户连点 / 网络重试"；业务层键处理"两个不同请求想做同一件事"（如两位家长同时点审核）。

### 5.3 对账

`php artisan points:reconcile`：对每个账户校验 `balance == SUM(amount)` 且 `balance_after` 序列连续；不一致时报警并写审计，**不自动修复**。每日在备份前运行。

## 6. 撤销规则

1. 只能撤销正向或负向的原始流水，不能撤销 `reversal` 本身（如需恢复，重新发起新的奖励/扣分）。
2. 撤销 Coins 收入时，若余额不足以扣回 → 返回 `INSUFFICIENT_BALANCE`，提示家长先处理（例如取消未履约订单）；**不允许负余额**。
3. 撤销任务审核：生成 Coins `reversal` +（若允许）XP `xp_correction`；任务状态回到 `submitted`，家长可重新审核。
4. 兑换退款使用 `refund` 类型（而不是 `reversal`），便于统计。

## 7. 扣分规则

- 扣分只能通过"创建草稿 → 预览 → 二次验证确认"产生，系统中没有任何定时任务或事件监听器会产生 `rule_deduction`（架构测试强制：只有 `ConfirmPointAdjustment` Action 可以使用该类型）。
- 规则启用前家长必须勾选声明：本规则不针对普通遗忘、能力不足或无意识身体习惯。
- 系统内置规则模板只提供示例（如"故意破坏物品"），默认**不启用**。
- 每次扣分记录：规则、原因（必填，至少 4 个字）、金额、发起人、确认人、时间。
- 孩子端显示扣分时使用中性文案（"这次扣除了 3 枚金币，原因：……"），并显示"如何赢回"的正向建议（家长可编辑）。

## 8. 商城兑换的积分流程

```
孩子申请                         家长审批                         取消
────────                        ────────                        ────
可用余额 = balance − Σ待审批订单    事务内：                          事务内：
可用余额 < 总价 → 422             1. 订单行锁，status=pending?       1. 订单行锁，status=approved
校验库存、限购                     2. 商品行锁，库存/限购再校验         2. post(refund, +total,
创建 pending 订单                  3. post(redemption, −total,          key=redemption:{id}:refund)
（幂等键=请求头）                       key=redemption:{id}:debit)     3. 恢复库存，status=refunded
                                  4. 扣库存，status=approved
```

## 9. 等级

默认曲线（累计 XP）：`xp_required(n) = 10 × n × (n − 1)`，即 2 级 20、3 级 60、10 级 900、30 级 8700。按每天约 40 XP 估算，一学年可到 20 级左右。曲线写入 `level_definitions`，家长可调整。等级由 `xp.lifetime_earned − Σ xp_correction` 计算；界面上的等级**不回退显示**（取历史最高等级）以避免挫败感，实际 XP 数值如实显示。
