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

见 `DATABASE.md` §6 的解析算法。示例：

| 日期 | 例外 | 结果 |
|---|---|---|
| 2026-10-12（周一） | 无 | 周一课表 |
| 2026-10-01（周四） | `no_school` 国庆 | 无课，不生成书包清单 |
| 2026-10-10（周六） | `makeup_day`，`follow_weekday=5` | 按周五课表，生成书包清单 |
| 2026-10-20（周二） | `custom`：语文、语文、体育、秋游 | 使用临时课表 |

## 6. 书包清单生成（`ChecklistGenerator`）

**输入**：孩子、目标日期（次日）。

**步骤**：

1. 用 `ScheduleResolver` 解析目标日期课程。非上学日 → 不生成（若已生成且尚未开始则标记作废）。
2. 收集候选行：
   - 每日必带：`needs_daily_check = true` 且 `allow_stay_at_school = false` 的物品（如校牌、水杯、文具盒）。
   - 科目所需：每门课 → `subject_item_requirements`（如数学 → 数学课本、口算本）。
   - 家长临时要求：`bag_extra_requirements` 中 `target_date` 匹配的记录（有物品或纯文字）。
3. 合并：同一物品多来源合并为一行，`sources` 记录全部来源，数量取最大值。
4. 排除：当前已确认在学校且允许留校的物品不需要放入。
5. 写入 `bag_checklists` + `bag_checklist_lines`，`generated_from` 保存课程快照。
6. 若存在 `form=item_check, checklist_kind=pack_for_school` 的任务实例，建立关联。

**重新生成**（课表或临时要求变化后）：已有结果（packed/missing）的行保留；新增行追加；已不需要的行标记 `not_needed`（不删除）。幂等：UNIQUE(`child_id`, `kind`, `target_date`)。

## 7. 打包流程（孩子端）

```
清单卡片（大图标 + 物品照片）
  ├─ 点"放好了 ✓" → packed → 事件 packed（位置=书包，已确认）
  └─ 点"找不到 ?" → missing → 事件 pack_missing（待确认）
         ├─ "我去找找"：显示物品照片、最后确认位置和时间（帮助回忆）
         ├─ 写寻找备注（可选，语音转文字由系统输入法完成）
         └─ 找到了 → "放好了 ✓"（packed）
全部处理完 → "完成整理" → 清单 completed / completed_with_issues
            → 关联任务自动提交 → 家长审核（可在审核时记录提醒次数）
```

缺失项**不会**自动变成疑似遗失。家长在审核中心看到 `completed_with_issues` 后可以：帮助寻找、标记疑似遗失、或备注"已知放在奶奶家"（`location_confirmed` 到 `other`）。

## 8. 放学回家核对

**默认核对项**（初始化时作为物品模板创建，家长可修改）：校牌、水杯、课本、作业本、文具盒，以及家长自定义物品。

生成规则：上学日 15:30（可配置）生成 `return_home` 清单，包含：
- 当天打包清单中 `packed` 的物品；
- `needs_daily_check = true` 的物品；
- 允许留校的物品默认预选"留校"，孩子可改。

| 结果 | 含义 | 产生事件 |
|---|---|---|
| 已带回 `brought_home` | 在家里 | `brought_home` |
| 留校 `left_at_school` | 主动放在学校 | `left_at_school` |
| 不确定 `unsure` | 想不起来 | `marked_unsure`（待确认，**不是丢失**） |
| 疑似遗失 `suspected_missing` | 家长判断可能丢了 | `marked_suspected_missing`（仅家长） |
| 已找回 `found` | 之前不确定/疑似遗失，现在找到了 | `found` |

## 9. 遗失与找回闭环

```
pending/unsure ──(家长判断)──▶ suspected ──▶ searching ──▶ found ──(24h后显示恢复正常)
                                    │             │
                                    └─────────────┴──▶ lost（家长关闭）──▶ restored（又找到了）
```

报表统计：疑似遗失次数、找回次数、平均找回时长、确认遗失件数、"不确定"次数（用于观察核对习惯，不用于扣分）。

## 10. 与积分、成就的关系

- 完成打包清单（`pack_bag` 任务）按任务规则发放金币和 XP；**清单中有缺失项不扣分**，只影响自主加成（由家长根据提醒次数确认）。
- 遗失物品不自动扣分。
- 徽章"书包小管家"：7 天中 5 天自主完成打包清单（弹性窗口）。

## 11. 测试要点

- 课程表例外：放假、调休、临时课表、孩子专属例外优先于全家例外。
- 清单生成幂等与重新生成保留结果。
- 孩子调用 `suspected_missing` 返回 403/422。
- 遗失 → 寻找 → 找回 全流程事件完整、投影正确、可重放重建。
- 事件表 UPDATE/DELETE 被触发器拒绝。
