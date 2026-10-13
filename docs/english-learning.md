# 英语探索：每日学习与复习

适用基础：认识常用词、可以理解简单短句的小学低年级孩子。

## 已实现

- 在原有观看登记上方加入每日学习卡，18 项固定内容覆盖水果、餐桌、动物、颜色、书包、心情。每组两个词配一个短句；新课按主题选取，名额只有 2 时也尽量保留短句，避免整天只见单词。
- 每天最多引入 3 项新内容（近 7 天正确率低于 60% 时改为最多 2 项），优先安排到期复习，每天最多 10 题。名额里除去课程卡与已发布的语法/短故事后，用词汇/短句小测试估计孩子认识多少表达（约三分之一为短句）。按家庭时区计算日期，按孩子独立保存。
- 单词卡展示音标与词性；标题旁绿色小圆点表示当日 AI 已接入，灰色表示未选引擎或调用失败（已回退本地）。新内容先看英文、中文提示和插图，可以点击英语神经语音跟读（系统朗读只在语音服务不可用时兜底）；随后隐藏提示，选择中文意思。复习改为看中文选英文。词汇小测试直接选中文意思，不发金币。
- 答错展示正确表达与一句辅导提示，不扣分；尽可能穿插另一张卡后再出现。答错重置复习阶段；答对依次间隔 1、3、7、14 天，之后继续每 14 天复习。这是可调整的初始规则，不把选择题通过等同于口语掌握。
- 课程卡每天首次答对默认 2 XP + 1 金币，进入原有账本；每天最多 10 项课程奖励。服务端判题、事务、按孩子加锁、唯一奖励键和接口幂等共同避免重复发放。词汇小测试、探索挑战与跟读打分不发奖。
- 家长关闭奖励策略时照常学习但不发奖；将该策略设置为需要审核时暂停自动发奖，当前版本不创建练习审核单。
- 当天任务完成后，完成页提供「跟读今天一句」小话筒：从当日答对的短句（优先）或单词中选一句，孩子录音上传；服务端转写并给出 1–5 星鼓励分与中文评语。录音保留 7 天供家长回放，到期自动删除。文案明确：练开口，不是正式考试或专业发音鉴定。无麦克风或无 AI 时仍可保存录音并用本地粗分。

## API 与存储

`GET /api/v1/kid/english/learning` 获取当前任务、进度和词汇估计。

`POST /api/v1/kid/english/learning/answer` 提交 `key` 和 `answer`，需要 `Idempotency-Key`。返回判题结果、实际奖励、`coach_tip`、下次复习日期和最新任务。

`GET /api/v1/kid/english/learning/speak` 今日跟读目标与已有结果。

`POST /api/v1/kid/english/learning/speak` multipart `audio` + 可选 `duration_ms`，需要 `Idempotency-Key`。每天一条，重录覆盖。

家长：

- `GET /api/v1/parent/english/learning?child_id=` 进度、正确率、易错表达
- `GET /api/v1/parent/english/learning/summary?child_id=` 本周小结（有引擎则 AI，否则本地摘要）
- `GET/POST /api/v1/parent/english/learning/drafts…` 语法/短故事草稿列表、生成、发布、驳回
- `GET /api/v1/parent/english/learning/speaks?child_id=` 近 7 天跟读列表
- `GET /api/v1/parent/english/learning/speaks/{id}/audio?child_id=` 回放音频流

内容位于 `backend/config/english_learning.php`，内容 key 应保持稳定。本地词汇阶梯位于 `backend/config/english_vocabulary.php`。表：`english_learning_progress`、`english_learning_answers`、`english_vocabulary_profiles`、`english_ai_records`、`english_content_drafts`、`english_speak_attempts`。奖励策略为 `english.practice`。调度：`english:prune-speak-attempts` 每日清理过期录音。

词汇估计（已知表达、易错表达、大约数量）只存在这些表里，不存在某个模型厂商那里。

## AI

家长在系统设置里选择 Gemini、通义千问或 ChatGPT 并保存密钥后，英语探索会调用该引擎：

| purpose | 作用 |
|---|---|
| `vocabulary_probe` | 当天词汇小测试 |
| `session_plan` | 当日卡片排序建议 |
| `coach_tips` | 答错辅导文案 |
| `grammar_draft` / `story_draft` | 家长触发的挑战草稿 |
| `parent_summary` | 按自然周缓存的家长小结 |
| `speak_score` | 跟读转写后的鼓励分与评语 |

未选择或调用失败时，使用本地规则/阶梯/模板。`EnglishContentGate` 校验词句长度、选项与难度；不合格条目丢弃后用本地内容补齐。每次调用把厂商、模型和快照写入 `english_ai_records`。更换厂商后，下一次调用读取同一份词汇档案，不依赖上一家的对话。

语法与短故事经家长发布后进入孩子队列，不占用「每天最多 3 张新课程卡」；当日 10 题已满则顺延。不发金币。

模型不能修改奖励或决定金币。跟读请求不传姓名；音频仅本家庭可听。自动模式仍只影响作业解析，不会因此调用英语探索。

## 验证

后端测试覆盖奖励去重、复习间隔、内容把关、coach_tip、草稿发布、跟读本地打分与覆盖重录、家长回放与过期清理。前端测试覆盖提示隐藏、辅导文案、音标与 AI 状态点。
