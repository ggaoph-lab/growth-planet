> ⚠️ **V0 规划文档**（2026-10-11 首版需求）。已被 V1.0 需求部分取代：与 `architecture.md`、`database.md`、`api.md`、`security.md`、`development-plan.md` 冲突之处以 V1.0 文档为准；本文件将在对应开发阶段更新。

# 成长星球 — 智能书包与物品追踪

## 1. 目标

帮助一年级孩子建立"出门前检查、回家后核对"的习惯，同时让家长知道每件重要物品**最后一次被确认在哪里**。

核心原则：**未确认 ≠ 丢失**。系统只记录事实（谁在什么时候确认了什么），不推断"丢了"。

## 2. 三维状态模型

物品状态拆成三个互相独立的维度：

| 维度 | 字段 | 取值 | 谁能改 |
|---|---|---|---|
| 位置（最后确认在哪） | `last_known_place_id` → `places.kind` | 家里 `home`、书包 `bag`、学校 `school`、其他 `other`、借出 `lent` | 孩子、家长（通过事件） |
| 确认状态 | `confirmation_state` | 已确认 `confirmed`、待确认 `pending` | 孩子、家长、系统（清单生成时可置为 pending） |
| 遗失状态 | `missing_state` | 正常 `none`、疑似遗失 `suspected`、寻找中 `searching`、已找回 `found`、确认遗失 `lost` | `suspected`/`lost` 仅家长；`searching`/`found` 孩子和家长 |

**为什么分离**：如果只用一个字段，"在学校"和"待确认"和"疑似丢失"会互相覆盖——例如孩子把水杯留在学校（位置=学校）但放学核对时说不确定（确认=待确认），这时如果直接变成"丢失"就违背了原则 P4。分离后：位置仍然是"学校（最后确认于周一 15:40）"，确认状态是"待确认"，遗失状态是"正常"。

## 3. 展示规则（组合 → 界面标签）

按优先级从上到下匹配：

| 条件 | 显示 | 颜色 |
|---|---|---|
| `missing_state = lost` | 确认遗失 | 灰 |
| `missing_state = suspected` | 疑似遗失（最后在：学校） | 橙 |
| `missing_state = searching` | 寻找中 | 黄 |
| `confirmation_state = pending` | 待确认（最后在：书包，10/10 07:30） | 浅黄 |
| `missing_state = found` 且 24 小时内 | 已找回 · 在家里 | 绿 |
| 其他 | 在家里 / 在书包 / 在学校 / 其他位置：xx / 借出：xx | 蓝/绿 |

另外：`allow_stay_at_school = true` 的物品在学校不显示为异常；`is_important = true` 且 `pending` 超过 1 个上学日时，家长首页高亮。

## 4. 物品事件（只追加）

| event_type | 位置变化 | 确认 | 遗失 | 触发者 |
|---|---|---|---|---|
| `registered` | → 默认地点 | confirmed | none | 家长创建物品 |
| `location_confirmed` | → 指定地点 | confirmed | 不变（若 searching → found） | 孩子/家长 |
| `packed` | → 书包 | confirmed | 不变 | 打包清单勾选"已放入" |
| `pack_missing` | 不变 | pending | 不变 | 打包清单标记"找不到" |
| `brought_home` | → 家里 | confirmed | 不变 | 放学核对 |
| `left_at_school` | → 学校 | confirmed | 不变 | 放学核对 |
| `marked_unsure` | 不变 | pending | 不变 | 放学核对"不确定" |
| `marked_suspected_missing` | 不变 | 不变 | → suspected | **仅家长** |
| `search_started` | 不变 | 不变 | → searching | 孩子/家长 |
| `search_note` | 不变 | 不变 | 不变 | 孩子/家长（备注："问过老师了"） |
| `found` | → 找到的地点 | confirmed | → found | 孩子/家长 |
| `lent` / `returned` | → 借出 / → 家里 | confirmed | 不变 | 家长 |
| `closed_as_lost` | 不变 | 不变 | → lost | **仅家长** |
| `restored` | → 地点 | confirmed | → none | 家长（确认遗失后又找到） |
| `correction` | 任意 | 任意 | 任意 | 家长纠错（必须填原因） |

- 每个事件同时保存变化前后的值，`items` 表的状态列在同一事务内更新（投影）。
- `php artisan items:rebuild-projection {item?}` 可从事件重放重建投影，用于校验。
- 事件不可修改、删除（触发器）；纠错只能追加 `correction` 事件。
- `occurred_at` 允许家长补录（如"昨天其实带回来了"），`created_at` 记录真实录入时间。

## 5. 课程表与日期例外

**已实现**（`Schedule` 模块 + `ScheduleResolver`）：周课表、日期例外、科目→物品、临时加带；书包清单按解析结果生成。详见 `database.md` §8.1、`api.md` 课程表行。

示例：

| 日期 | 例外 | 结果 |
|---|---|---|
| 2026-10-12（周一） | 无 | 周一课表 |
| 2026-10-01（周四） | `no_school` 国庆 | 无课，不生成书包清单 |
| 2026-10-10（周六） | `makeup_day`，`follow_weekday=5` | 按周五课表，生成书包清单 |
| 2026-10-20（周二） | `custom`：语文、语文、体育、秋游 | 使用临时课表 |

## 6. 书包清单生成（`ItemChecklistService` + `ScheduleResolver`）

**已实现**（V1 表名为 `item_checklists` / `item_checklist_lines`，字段 `carry_daily` 对应规划中的每日必带）。

**输入**：孩子、目标日期（`before_school`=次日，`after_school`=今日）。

**步骤**：

1. 用 `ScheduleResolver` 解析目标日期课程。非上学日 → 不生成（若已生成且仍为 `open` 则标 `cancelled`）。未配置任何周课表时，视为上学日且科目为空（仅每日必带，兼容旧行为）。
2. 收集候选行：
   - 每日必带：`carry_daily = true`。
   - 科目所需：当日科目 → `subject_item_requirements`。
   - 家长临时要求：`bag_extra_requirements`（有物品或纯文字；文字会物化为一次性物品）。
3. 合并：同一物品多来源合并为一行，`sources` 记录全部来源。
4. 排除：`before_school` 时当前位置已在学校的物品。
5. 写入清单行，`generated_from` 保存课程快照。

**重新生成**：已有 `present`/`not_found`/`left_at_school` 保留；新增追加；不再需要的 `pending` → `not_needed`。幂等：UNIQUE(`child_id`, `kind`, `target_date`)。

## 7. 孩子端四框拖拽（现行）

孩子书包星球展示四个（可更多）框：

| 框 | 含义 |
|---|---|
| **我的物品** | 暂存区（系统「家里」/书桌等未分到其它框的物品） |
| **家里** | 家里地点（系统框 + 家长自定义，如奶奶家） |
| **书包** | 书包地点（系统框 + 家长自定义，如体育袋） |
| **学校里** | 学校 |

**上学前**：把要带的物品从「我的物品」拖进「书包」，点一次「完成核对」→ 更新位置并发放 `item.checklist.before_school` 奖励。

**放学后**：检查书包，带回的拖进「家里」，留下的拖进「学校」，再点一次「完成」→ 发放 `item.checklist.after_school` 奖励。

完成接口可提交 `placements: [{ item_id, location_id }]`（`location_id: null` = 我的物品 → 默认家里）。

家长可在生活页增减「家里」「书包」框（`POST/DELETE /parent/item-locations`，系统地点不可删）。

## 8. 放学回家核对（事件语义）

| 拖到 | 含义 | 事件 |
|---|---|---|
| 家里 / 我的物品 | 带回家里 | `brought_home` / `checked` |
| 学校 | 留在学校 | `left_at_school`（result） |
| 书包 | 仍在书包 | `checked` |

## 9. 遗失与找回闭环

```
pending/unsure ──(家长判断)──▶ suspected ──▶ searching ──▶ found ──(24h后显示恢复正常)
                                    │             │
                                    └─────────────┴──▶ lost（家长关闭）──▶ restored（又找到了）
```

报表统计：疑似遗失次数、找回次数、平均找回时长、确认遗失件数、"不确定"次数（用于观察核对习惯，不用于扣分）。

## 10. 与积分、成就的关系

- 孩子完成核对（`POST /kid/item-checklists/{id}/complete`）时按家庭奖励策略发放 XP 与成长币：`item.checklist.before_school` / `item.checklist.after_school`（默认各 5 XP / 2 币；`grant_key` 保证每份清单只发一次）；金额可在家长「系统设置 → 书包星球」调整。
- 完成后可 `POST /kid/item-checklists/{id}/reopen` 重新核对：状态回到 `open`，行结果回到 `pending`，物品停在当前地点；再次完成不再发奖。
- **清单中有缺失项不扣分**（仍可完成核对并发奖）；遗失物品不自动扣分。
- 也可另建 `pack_bag` 任务按任务规则发放；与核对完成奖励相互独立。
- 徽章"书包小管家"：7 天中 5 天自主完成打包清单（弹性窗口）。

## 11. 测试要点

- 课程表例外：放假、调休、临时课表、孩子专属例外优先于全家例外。
- 清单生成幂等与重新生成保留结果。
- 孩子调用 `suspected_missing` 返回 403/422。
- 遗失 → 寻找 → 找回 全流程事件完整、投影正确、可重放重建。
- 事件表 UPDATE/DELETE 被触发器拒绝。
