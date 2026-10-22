> 当前实现文档（三资产经济）。与 `architecture.md`、`database.md`、`api.md` 冲突时以本文件与代码为准。

# 成长星球 — 积分经济规则

## 1. 三种资产

| | 经验 XP | 积分 Points | 成长币 Coins |
|---|---|---|---|
| 用途 | 等级进度条、称号 | 兑换虚拟奖励（游戏/电视时间、家庭活动、虚拟装饰） | 兑换实体礼物（冰激凌、玩具等） |
| 主要来源 | 任务、习惯、清单等 `xp_amount` | **每日固定津贴**；游戏（舒尔特/镜像/数独） | 任务、习惯、清单等 `coin_amount` |
| 减少 | **升级时扣除本级阈值**（溢出结转）；家长冲正 | 兑换预留/扣款；家长调整 | 兑换预留/扣款；审核扣分；冲正 |
| 负余额 | 禁止 | 禁止 | 禁止 |

三种资产各有独立账户与流水，互不换算。

## 2. 经验与等级（Habitica 曲线）

```
toNextLevel(lvl) =
  lvl < 5  → 25 * lvl
  lvl == 5 → 150
  else     → round((0.25*lvl² + 10*lvl + 139.75) / 10) * 10
```

- `xp_accounts.balance` = **当前等级内经验**
- `xp_accounts.lifetime_earned` = 终身累计入账（升级不扣减）
- 发 XP 后循环：`while (balance >= toNextLevel(level) && level < max) { balance -= need; level++ }`，并记 `entry_type=level_up` 流水
- 默认最高等级 300（设置 `xp.max_level`）；满级后经验条封顶不再继续涨

## 3. 每日积分津贴

- 设置键：`points.daily_allowance`（默认 10，家庭可覆盖）
- 政策码：`points.daily_allowance`
- 命令：`php artisan points:grant-daily-allowance`（scheduler 每 10 分钟，幂等键 `daily_points:{child_id}:{业务日}`）

## 4. 商城货币

新建/编辑奖励时可选手动选择购买货币（`point_price >= 1` → 积分，否则成长币）。类型切换会预填建议货币：实物默认成长币，其余类型默认积分，可覆盖。

## 5. 流水类型（摘要）

| type | 资产 | 说明 |
|---|---|---|
| `reward` | XP / Points / Coins | 策略发放 |
| `parent_bonus` | 三者 | 家长手动奖励 |
| `daily_allowance` | Points | 每日津贴（也可记为 reward + policy） |
| `level_up` | XP | 升级扣除阈值 / 满级封顶 |
| `redemption` | Points / Coins | 兑换扣款 |
| `reversal` | 对应资产 | 冲正 |

## 6. 一致性

沿用既有账本算法：事务 + 行锁 + 幂等键 + `CHECK (balance >= 0)`；金币/积分另有 `held` 与 `CHECK (held <= balance)`。
