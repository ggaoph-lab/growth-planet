# 英语探索：每日学习与复习

适用基础：认识常用词、可以理解简单短句的小学低年级孩子。

## 已实现

- 每日主线改为 **AI 根据词汇档案估量**（已知 / 易错 / 学习中 / 估计量与正确率），挑选约 **10 个新词**（`daily_word_pack`，进度键 `word:{slug}`）。固定课程目录 `english_learning.php` 仅作 AI/阶梯失败时的回退，以及已有进度的复习。
- 每天学满 **10 个新词**后解锁 **今日童话**（`fairy_tale_day`）：从格林等经典种子改编的短篇双语故事；完成页居中展示，每句为「拼音在中文上、对应英文在下」，标题旁可分别听中文 / 听英文（听完英文计为已听）。英文单词可以点选，加入**今日新词**；之后点「今日新词」里的「继续学习」或某个未学会的词，就能接着练这些新加的单词（每天目标仍按 10 个计，多加的词可以继续学）。语法进阶课程仍留给下一阶段；家长发布的语法/短故事挑战逻辑不变。
- **当日顺序**：先学新词 → 听完童话 → 再复习旧词和短句。新词未学满或童话未听完时，队列不塞入复习 / 拼写 / 词汇小测试。每轮最多引入 **5 个** 新词（全天目标仍约 10 个）；听完童话后，从到期旧词中 **随机抽取最多 10 个** 作为当日复习池，再用已发布挑战 / 拼写 / 小测试填满约 10 题。做完后若还有池内复习或小测试会继续出题。按家庭时区计算日期，按孩子独立保存。
- **今日新词包必须凑满 10 个**：AI 先出题，校验过滤后不足的部分用本地词梯强制补齐（必要时放宽难度带）再落库；若中途发现待学不足，也会就地补齐，避免卡在 6/10 之类的空队列。
- 单词卡展示音标与词性；标题旁绿色小圆点表示当日 AI 已接入，灰色表示未选引擎或调用失败（已回退本地）。新内容先看英文、中文提示和插图，可以点击英语神经语音跟读（系统朗读只在语音服务不可用时兜底）；随后隐藏提示，选择中文意思。复习改为看中文选英文。
- **词汇小测试（单词）**：英选中答对 **2 次**后转为拼写；**拼写答对**才算学会并记入「大约认识」。英选中答错会清空该词累计次数。短句小测试仍用于估量，一次作答即可。不发金币。
- 答错展示正确表达与一句辅导提示，不扣分；尽可能穿插另一张卡后再出现。答错重置复习阶段；答对依次间隔 1、3、7、14 天，之后继续每 14 天复习。这是可调整的初始规则，不把选择题通过等同于口语掌握。
- 课程卡（含每日新词）每天首次答对默认 2 XP + 1 金币，进入原有账本；每天最多 10 项课程奖励，达上限后仍可继续学但不发奖。服务端判题、事务、按孩子加锁、唯一奖励键和接口幂等共同避免重复发放。词汇小测试、探索挑战与跟读打分不发奖。
- 家长关闭奖励策略时照常学习但不发奖；将该策略设置为需要审核时暂停自动发奖，当前版本不创建练习审核单。
- 当天任务完成后，完成页提供「跟读今天一句」小话筒：从当日答对的短句（优先）或单词中选一句，孩子录音上传；服务端转写并给出 1–5 星鼓励分与中文评语。录音保留 7 天供家长回放，到期自动删除。文案明确：练开口，不是正式考试或专业发音鉴定。无麦克风或无 AI 时仍可保存录音并用本地粗分。学满 10 个新词后，完成页另有「童话时间」面板。

## API 与存储

`GET /api/v1/kid/english/learning` 获取当前任务、进度、词汇估计，以及 `new_words_goal` / `new_words_completed` / `fairy_tale_unlocked` / `fairy_tale_heard_today` / `review_unlocked`。

`POST /api/v1/kid/english/learning/answer` 提交 `key` 和 `answer`，需要 `Idempotency-Key`。返回判题结果、实际奖励、`coach_tip`、下次复习日期和最新任务。

`GET /api/v1/kid/english/learning/records` 今日完成、今日新词状态、已读童话列表。

`GET /api/v1/kid/english/learning/fairy-tale` 今日童话（未解锁时 `available: false`）。可选 `?date=YYYY-MM-DD` 重读往日缓存童话。

`POST /api/v1/kid/english/learning/fairy-tale/heard` 标记听完（幂等）。

`POST /api/v1/kid/english/learning/fairy-tale/words` 提交 `{ word, date? }`，把童话里的一个英文单词加入今日新词。`date` 用来核对往日童话原文，单词记在今天。已在词包里则不重复添加。

`GET /api/v1/kid/english/learning?prefer=word:{slug}` 把这个未完成的今日新词排到本轮最前，便于从「今日新词」接着学。

`GET /api/v1/kid/english/learning/speak` 今日跟读目标与已有结果。

`POST /api/v1/kid/english/learning/speak` multipart `audio` + 可选 `duration_ms`，需要 `Idempotency-Key`。每天一条，重录覆盖。

家长：

- `GET /api/v1/parent/english/learning?child_id=` 进度、正确率、易错表达
- `GET /api/v1/parent/english/learning/summary?child_id=` 本周小结（有引擎则 AI，否则本地摘要）
- `GET/POST /api/v1/parent/english/learning/drafts…` 语法/短故事草稿列表、生成、发布、驳回
- `GET /api/v1/parent/english/learning/speaks?child_id=` 近 7 天跟读列表
- `GET /api/v1/parent/english/learning/speaks/{id}/audio?child_id=` 回放音频流

内容位于 `backend/config/english_learning.php`（遗留/回退目录）与 `backend/config/english_fairy_tales.php`（童话种子）。本地词汇阶梯位于 `backend/config/english_vocabulary.php`。表：`english_learning_progress`、`english_learning_answers`、`english_vocabulary_profiles`、`english_ai_records`、`english_content_drafts`、`english_speak_attempts`。奖励策略为 `english.practice`。调度：`english:prune-speak-attempts` 每日清理过期录音。

词汇估计（已知表达、易错表达、大约数量）只存在这些表里，不存在某个模型厂商那里。

## AI

家长在系统设置里选择 Gemini、通义千问或 ChatGPT 并保存密钥后，英语探索会调用该引擎：

| purpose | 作用 |
|---|---|
| `daily_word_pack` | 当日约 10 个新词 |
| `fairy_tale_day` | 学满新词后的短篇双语童话 |
| `vocabulary_probe` | 当天词汇小测试 |
| `session_plan` | 当日卡片排序建议 |
| `coach_tips` | 答错辅导文案 |
| `grammar_draft` / `story_draft` | 家长触发的挑战草稿 |
| `parent_summary` | 按自然周缓存的家长小结 |
| `speak_score` | 跟读转写后的鼓励分与评语 |

未选择或调用失败时，使用本地规则/阶梯/模板。`EnglishContentGate` 校验词句长度、选项与难度；不合格条目丢弃后用本地内容补齐。每次调用把厂商、模型和快照写入 `english_ai_records`。更换厂商后，下一次调用读取同一份词汇档案，不依赖上一家的对话。

语法与短故事经家长发布后进入孩子队列，不占用「每天约 10 个新词」名额；当前轮次名额满了会进入下一轮。不发金币。

模型不能修改奖励或决定金币。跟读请求不传姓名；音频仅本家庭可听。自动模式仍只影响作业解析，不会因此调用英语探索。

## 验证

后端测试覆盖每日词包缓存、满 10 解锁童话、奖励去重、复习间隔、内容把关、coach_tip、草稿发布、跟读本地打分与覆盖重录、家长回放与过期清理。前端测试覆盖今日新词进度、童话中英朗读顺序、提示隐藏、辅导文案、音标与 AI 状态点。
