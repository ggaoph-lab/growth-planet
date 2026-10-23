<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnglishLearningService
{
    /** Cards offered in each queue refill; learning can continue after rewards cap. */
    public const SESSION_SIZE = 10;

    /** Course-card reward ceiling for `english.practice` (coins/XP), not a learning stop. */
    public const REWARD_DAILY_LIMIT = 10;

    /** Max old-word reviews drawn per child per day (random sample of due cards). */
    public const REVIEW_DAILY_LIMIT = 10;

    /** @deprecated Use SESSION_SIZE / REWARD_DAILY_LIMIT */
    public const DAILY_LIMIT = self::REWARD_DAILY_LIMIT;

    public function __construct(
        private Clock $clock,
        private RewardGrantService $rewards,
        private EnglishVocabularyService $vocabulary,
        private EnglishAiCoach $coach,
        private EnglishDraftService $drafts,
        private EnglishFairyTaleService $fairyTales,
    ) {}

    public function plan(ChildProfile $child, ?string $preferKey = null): array
    {
        if (! is_string($preferKey) || preg_match('/^word:[a-z0-9-]{1,60}$/', $preferKey) !== 1) {
            $preferKey = null;
        }
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $progress = DB::table('english_learning_progress')->where('child_id', $child->id)->get()->keyBy('item_key');
        $completed = $progress->where('completed_date', $today)->count();
        $remaining = self::SESSION_SIZE;
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);
        $rich = $profile['path'] === EnglishVocabularyService::PATH_RICH;
        $newWordsCompleted = $this->vocabulary->newWordsCompletedToday($child, $today);
        $wordPack = $this->vocabulary->dailyWordPack($child, $today);
        $wordGoal = $this->vocabulary->dailyWordGoal($child, $today);

        $due = $progress->filter(fn ($p) => (int) $p->stage < 4 && $p->due_date <= $today && $p->completed_date !== $today)
            ->sortBy('due_date');
        $due = $this->prioritizeMissed($due, $catalog, $profile['missed_words']);
        $dueByKey = $due->keyBy(fn ($p) => (string) $p->item_key);

        // Daily order: new words → fairy tale → reviews / short sentences.
        // Words tapped in a fairy tale stay pending after the daily goal so they can still be practiced.
        $collectedPending = [];
        $scheduledPending = [];
        foreach ($wordPack as $card) {
            $key = (string) ($card['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $row = $progress->get($key);
            if ($row !== null && $row->completed_date === $today) {
                continue;
            }
            if (! empty($card['collected'])) {
                $collectedPending[] = $card;
            } else {
                $scheduledPending[] = $card;
            }
        }
        $packPending = [...$collectedPending, ...$scheduledPending];
        $preferIndex = null;
        if ($preferKey !== null) {
            foreach ($packPending as $index => $card) {
                if (($card['key'] ?? '') === $preferKey) {
                    $preferIndex = $index;
                    break;
                }
            }
            if ($preferIndex !== null && $preferIndex > 0) {
                $picked = $packPending[$preferIndex];
                array_splice($packPending, $preferIndex, 1);
                array_unshift($packPending, $picked);
            }
        }
        $goalRoom = max(0, $wordGoal - $newWordsCompleted);
        $sessionCap = EnglishVocabularyService::NEW_WORDS_SESSION_CAP;
        // Cap brand-new introductions per round so kids are not stuck on 10 new words at once.
        // After the daily goal, only words the child added from a fairy tale (or an explicit pick) stay in the queue.
        $newWordSlots = min(
            max(
                $goalRoom,
                min(count($collectedPending), $sessionCap),
                $preferIndex !== null ? 1 : 0,
            ),
            $sessionCap,
            count($packPending),
            $remaining,
        );

        $items = [];
        $usedKeys = [];
        foreach (array_slice($packPending, 0, $newWordSlots) as $card) {
            $key = (string) $card['key'];
            $row = $progress->get($key);
            // Same-day misses on a brand-new word keep learning mode (Chinese options) so the
            // kid UI can retry other choices. Only flip to review after a prior introduction day.
            $sameDayNewMiss = $row !== null
                && (string) ($row->introduced_date ?? '') === $today
                && $row->completed_date === null
                && (int) ($row->stage ?? 0) === 0;
            $isReview = $row !== null
                && ! $sameDayNewMiss
                && (string) $row->due_date <= $today
                && $row->completed_date !== $today;
            if ($isReview) {
                $reviewCard = $this->reviewCardFor($child, $key, $catalog, $wordPack);
                $items[] = [
                    ...($reviewCard ?? $card),
                    'review' => true,
                    'stage' => (int) ($row->stage ?? 0),
                    'daily_word' => true,
                    'collected' => ! empty($card['collected']),
                ];
            } else {
                $items[] = $card;
            }
            $usedKeys[$key] = true;
        }

        $newWordsDone = $newWordsCompleted >= $wordGoal;
        $fairyUnlocked = $newWordsDone;
        $fairyHeardToday = $this->fairyTales->heardOnDate($child, $today);
        $reviewUnlocked = $fairyUnlocked && $fairyHeardToday;
        $reviewPool = $this->dailyReviewPool($child, $due, $today);

        $practiceBand = $this->vocabulary->practiceBand($child);
        $snapshot = [
            'band' => $practiceBand,
            'path' => $profile['path'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -30),
            'missed_words' => array_slice($profile['missed_words'], -15),
            'learning_words' => array_slice($profile['learning_words'], -15),
            'recent_accuracy' => $accuracy,
        ];

        // Hold reviews / probes until today's new words and fairy tale are done.
        if ($reviewUnlocked) {
            foreach ($reviewPool as $key) {
                if (count($items) >= $remaining) {
                    break;
                }
                if (isset($usedKeys[$key])) {
                    continue;
                }
                $p = $dueByKey->get($key);
                if ($p === null) {
                    continue;
                }
                $reviewCard = $this->reviewCardFor($child, $key, $catalog, $wordPack);
                if ($reviewCard !== null) {
                    $items[] = [
                        ...$reviewCard,
                        'review' => true,
                        'stage' => (int) $p->stage,
                    ];
                    $usedKeys[$key] = true;
                }
            }

            // Legacy catalog only when the AI/ladder pack could not fill today's new-word slots.
            if (count($packPending) === 0 && ! $rich) {
                $catalogNewCap = ($accuracy !== null && $accuracy < 0.6) ? 2 : 3;
                $newLimit = min(
                    max(0, $catalogNewCap - $progress->where('introduced_date', $today)->count()),
                    $remaining - count($items),
                );
                foreach ($this->pickNewCards($catalog, $progress, $newLimit) as $item) {
                    $items[] = [
                        ...$item,
                        'ipa' => (string) ($item['ipa'] ?? ''),
                        'pos' => (string) ($item['pos'] ?? ''),
                        'review' => false,
                        'stage' => 0,
                    ];
                }
            }

            $slotsLeft = $remaining - count($items);
            if ($slotsLeft > 0) {
                $challenges = $this->drafts->publishedCards($child, $slotsLeft, $today);
                $items = [...$items, ...$challenges];
                $slotsLeft = $remaining - count($items);
            }
            if ($rich && $practiceBand >= 3 && $slotsLeft > 0) {
                $richCards = $this->coach->richDailyChallenges($child, min(2, $slotsLeft), $snapshot, $today);
                $items = [...$items, ...$richCards];
                $slotsLeft = $remaining - count($items);
            }
            if ($slotsLeft > 0) {
                $spellRows = DB::table('english_learning_answers')
                    ->where('child_id', $child->id)
                    ->where('business_date', $today)
                    ->where('item_key', 'like', 'spell:%')
                    ->get(['item_key', 'correct']);
                $spelledDone = [];
                foreach ($spellRows as $row) {
                    $itemKey = (string) $row->item_key;
                    if (str_ends_with($itemKey, ':skip')) {
                        $spelledDone[] = substr($itemKey, 0, -strlen(':skip'));
                    } elseif ((bool) $row->correct) {
                        $spelledDone[] = $itemKey;
                    }
                }
                $spelledDone = array_values(array_unique($spelledDone));
                $spelling = $this->vocabulary->spellingCards(
                    $child,
                    min(EnglishVocabularyService::SPELLING_DAILY_LIMIT, $slotsLeft),
                    $spelledDone,
                    $today,
                );
                $items = [...$items, ...$spelling];
                $slotsLeft = $remaining - count($items);
            }
            if ($slotsLeft > 0) {
                $items = [...$items, ...$this->vocabulary->probes(
                    $child,
                    $slotsLeft,
                    array_map(fn ($item) => (string) ($item['text'] ?? ''), $items),
                    $today,
                )];
            }

            $items = $this->coach->reorderSession($child, $items, $snapshot, $today);
            $this->coach->ensureCoachTips($child, $items, $snapshot, $today);
        }

        $items = $this->pinCollectedWords($items, $preferKey);

        return [
            'items' => $items, 'completed' => $completed,
            'due' => count($reviewPool),
            'due_total' => $due->count(),
            'mastered' => $progress->filter(fn ($p) => $p->stage >= 4)->count(),
            'learned' => $progress->count(), 'total' => max($catalog->count(), $progress->count()),
            'next_review' => $progress->filter(fn ($p) => (int) $p->stage < 4 && $p->due_date > $today)->min('due_date'),
            'vocabulary' => $this->vocabulary->summary($child),
            'ai_status' => $this->coach->connectionStatus($child, $today),
            'ai_error' => $this->coach->failureHint($child, $today),
            'new_words_goal' => $wordGoal,
            'new_words_completed' => $newWordsCompleted,
            'fairy_tale_unlocked' => $fairyUnlocked,
            'fairy_tale_heard_today' => $fairyHeardToday,
            'review_unlocked' => $reviewUnlocked,
            'fairy_tales_heard' => $this->fairyTales->heardCount($child),
        ];
    }

    /**
     * Keep fairy-tale picks (and an explicitly chosen word) at the front of the round.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function pinCollectedWords(array $items, ?string $preferKey): array
    {
        $front = [];
        $rest = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if (($preferKey !== null && $key === $preferKey) || ! empty($item['collected'])) {
                $front[] = $item;
            } else {
                $rest[] = $item;
            }
        }
        if ($preferKey !== null && count($front) > 1) {
            usort($front, function (array $a, array $b) use ($preferKey): int {
                $aHit = (($a['key'] ?? '') === $preferKey) ? 0 : 1;
                $bHit = (($b['key'] ?? '') === $preferKey) ? 0 : 1;

                return $aHit <=> $bHit;
            });
        }

        return [...$front, ...$rest];
    }

    /**
     * Stable daily sample of up to REVIEW_DAILY_LIMIT due review keys.
     *
     * @param  Collection<int, object>  $due
     * @return list<string>
     */
    private function dailyReviewPool(ChildProfile $child, $due, string $date): array
    {
        $dueKeys = $due->map(fn ($p) => (string) $p->item_key)->filter()->values()->all();
        $dueLookup = array_fill_keys($dueKeys, true);
        $record = $this->coach->findRecord($child, 'review_day', $date);
        if ($record !== null) {
            $items = json_decode((string) $record->items, true);
            if (is_array($items)) {
                $pool = [];
                foreach ($items as $key) {
                    $key = (string) $key;
                    if ($key !== '' && isset($dueLookup[$key])) {
                        $pool[] = $key;
                    }
                }

                return array_values(array_unique($pool));
            }
        }

        shuffle($dueKeys);
        $picked = array_slice($dueKeys, 0, self::REVIEW_DAILY_LIMIT);
        $this->coach->storeRecord(
            $child,
            'review_day',
            $date,
            ['count' => count($picked), 'limit' => self::REVIEW_DAILY_LIMIT, 'due_total' => count($dueKeys)],
            $picked,
            'local',
        );

        return $picked;
    }

    /**
     * @return array<string, mixed>
     */
    public function fairyTale(ChildProfile $child): array
    {
        return $this->fairyTales->forChild($child);
    }

    /**
     * @return array<string, mixed>
     */
    public function fairyTaleHeard(ChildProfile $child): array
    {
        return $this->fairyTales->markHeard($child);
    }

    /**
     * Kid-facing activity records for stats chips.
     *
     * @return array<string, mixed>
     */
    public function records(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $progressRows = DB::table('english_learning_progress')
            ->where('child_id', $child->id)
            ->orderByDesc('stage')
            ->get(['item_key', 'stage', 'completed_date', 'introduced_date']);
        $resolved = $this->resolveProgressRecords($child, $today, $progressRows);
        $profile = $this->vocabulary->profile($child);

        return [
            'as_of' => $today,
            'completed_today' => array_values(array_filter(
                $resolved,
                fn ($item) => ($item['completed_date'] ?? null) === $today
            )),
            'new_words_today' => $this->vocabulary->newWordsTodayRecords($child, $today),
            'learned' => $resolved,
            'mastered' => array_values(array_filter(
                $resolved,
                fn ($item) => (int) ($item['stage'] ?? 0) >= 4
            )),
            'known_words' => array_map(
                fn (string $word) => [
                    'key' => 'known:'.$word,
                    'text' => $word,
                    'meaning' => '',
                    'emoji' => '📖',
                    'kind' => 'word',
                ],
                array_slice(array_reverse($profile['known_words']), 0, 80)
            ),
            'fairy_tales' => $this->fairyTales->history($child),
            'new_words_goal' => $this->vocabulary->dailyWordGoal($child, $today),
            'new_words_completed' => $this->vocabulary->newWordsCompletedToday($child, $today),
            'fairy_tales_heard' => $this->fairyTales->heardCount($child),
            'estimated_size' => $profile['estimated_size'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fairyTaleOnDate(ChildProfile $child, string $date): array
    {
        return $this->fairyTales->forDate($child, $date);
    }

    /**
     * Kid taps an English word in a fairy tale — add it to today's new-word pack.
     *
     * @return array<string, mixed>
     */
    public function collectFairyTaleWord(ChildProfile $child, string $word, ?string $date = null): array
    {
        return $this->fairyTales->collectWord($child, $word, $date);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function resolveProgressRecords(ChildProfile $child, string $date, $rows): array
    {
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $wordPack = $this->vocabulary->dailyWordPack($child, $date);
        $packByKey = [];
        foreach ($wordPack as $card) {
            $packByKey[(string) ($card['key'] ?? '')] = $card;
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->item_key;
            $card = $packByKey[$key] ?? null;
            if ($card === null && str_starts_with($key, 'word:')) {
                $card = $this->vocabulary->resolveWordCard($child, $key);
            }
            if ($card === null && isset($catalog[$key])) {
                $card = $catalog[$key];
            }
            if ($card === null) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'text' => (string) ($card['text'] ?? ''),
                'meaning' => (string) ($card['meaning'] ?? ''),
                'emoji' => (string) ($card['emoji'] ?? '✨'),
                'kind' => (string) ($card['kind'] ?? 'word'),
                'daily_word' => str_starts_with($key, 'word:') || ! empty($card['daily_word']),
                'stage' => (int) $row->stage,
                'completed_date' => $row->completed_date !== null ? (string) $row->completed_date : null,
                'introduced_date' => isset($row->introduced_date) ? (string) $row->introduced_date : null,
            ];
        }

        return $out;
    }

    public function answer(ChildProfile $child, string $key, string $answer): array
    {
        // Resolve the card (may call AI) before opening a DB transaction so we never hold
        // locks across HTTP. Concurrent GET /learning was stuck behind answer()+AI.
        $item = collect($this->plan($child)['items'])->firstWhere('key', $key);
        $isSpelling = ! empty($item['spelling']) || (($item['kind'] ?? '') === 'spelling');
        if (! $item) {
            throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
        }
        if ($isSpelling) {
            $normalized = strtolower(trim($answer));
            if ($normalized === '' || mb_strlen($normalized) > 40) {
                throw ValidationException::withMessages(['answer' => '请拼写完整的英文单词。']);
            }
        } elseif (! in_array($answer, $item['options'], true)
            && $answer !== (string) ($item['meaning'] ?? '')
            && $answer !== (string) ($item['text'] ?? '')) {
            // After a miss, a course card may flip to review options while the kid UI
            // still shows the previous Chinese choices — accept meaning or English text.
            throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
        }
        $today = $this->clock->today($child->family->timezone);
        $date = $today->toDateString();
        $isChallenge = ! empty($item['challenge']);
        $isProbe = ! empty($item['probe']);
        $isSkip = $isSpelling && strtolower(trim($answer)) === '__skip__';
        if ($isSpelling) {
            $correct = $isSkip
                ? false
                : strtolower(trim($answer)) === strtolower((string) ($item['answer'] ?? $item['text'] ?? ''));
        } elseif ($isChallenge) {
            $correct = $answer === (string) ($item['correct_answer'] ?? $item['answer'] ?? '');
        } else {
            // Either language of the pair counts so a miss can stay on-screen and retry.
            $correct = $answer === (string) ($item['meaning'] ?? '')
                || $answer === (string) ($item['text'] ?? '');
        }

        $saved = DB::transaction(function () use ($child, $key, $item, $correct, $today, $date, $isSpelling, $isChallenge, $isProbe, $isSkip) {
            // Serialize answers per child, including new progress rows and reward caps.
            ChildProfile::query()->whereKey($child->id)->lockForUpdate()->firstOrFail();

            // Cheap re-check: the outer plan may be stale if another answer finished first.
            // Spelling first — those cards may also carry probe=true but still allow retries until correct.
            if ($isSpelling) {
                $alreadyCorrect = DB::table('english_learning_answers')
                    ->where('child_id', $child->id)
                    ->where('business_date', $date)
                    ->where('item_key', $key)
                    ->where('correct', true)
                    ->exists();
                if ($alreadyCorrect) {
                    throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
                }
                // "I don't know" retires the spelling card for today (moved into vocabulary probes).
                if ($isSkip) {
                    $alreadySkip = DB::table('english_learning_answers')
                        ->where('child_id', $child->id)
                        ->where('business_date', $date)
                        ->where('item_key', $key.':skip')
                        ->exists();
                    if ($alreadySkip) {
                        throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
                    }
                }
            } elseif ($isProbe || $isChallenge) {
                $already = DB::table('english_learning_answers')
                    ->where('child_id', $child->id)
                    ->where('business_date', $date)
                    ->where('item_key', $key)
                    ->exists();
                if ($already) {
                    throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
                }
            } else {
                $done = DB::table('english_learning_progress')
                    ->where('child_id', $child->id)
                    ->where('item_key', $key)
                    ->where('completed_date', $date)
                    ->exists();
                if ($done) {
                    throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
                }
            }

            $probeProgress = null;
            if ($isSkip) {
                $this->vocabulary->recordSpellingSkip($child, $item, $date);
            } elseif ($isSpelling && $correct) {
                $this->vocabulary->completeSpelling($child, $item, $date);
            } elseif ($isProbe && ! $isSpelling && ($item['kind'] ?? '') === 'word') {
                // 词汇小测试单词：英选中答对 2 次 → 拼写；拼写答对才算认识。
                $probeProgress = $this->vocabulary->recordWordProbeAnswer($child, $item, $correct, $date);
            } else {
                $this->vocabulary->observe($child, (string) $item['text'], $correct);
            }

            if ($isProbe || $isChallenge) {
                DB::table('english_learning_answers')->insert([
                    'child_id' => $child->id, 'item_key' => $key, 'business_date' => $date,
                    'correct' => $correct, 'created_at' => $this->clock->now(),
                ]);
                if ($isSkip) {
                    // Marker so spellingCards treats this word as finished for today.
                    DB::table('english_learning_answers')->insert([
                        'child_id' => $child->id, 'item_key' => $key.':skip', 'business_date' => $date,
                        'correct' => true, 'created_at' => $this->clock->now(),
                    ]);
                }

                return [
                    'correct' => $correct,
                    'meaning' => $item['meaning'] ?? $item['question'] ?? '',
                    'xp' => 0,
                    'coins' => 0,
                    'next_review' => $date,
                    'probe' => $isProbe,
                    'challenge' => $isChallenge,
                    'spelling' => $isSpelling,
                    'skipped' => $isSkip,
                    'probe_corrects' => $probeProgress['probe_corrects'] ?? null,
                    'probe_goal' => $probeProgress['probe_goal'] ?? null,
                    'promoted_to_spelling' => $probeProgress['promoted_to_spelling'] ?? false,
                    'mastery_phase' => $probeProgress['mastery_phase'] ?? null,
                ];
            }

            $old = DB::table('english_learning_progress')->where('child_id', $child->id)->where('item_key', $key)->first();
            $stage = $correct ? min(4, ($old->stage ?? 0) + 1) : 0;
            $days = $correct ? [1 => 1, 2 => 3, 3 => 7, 4 => 14][$stage] : 0;
            DB::table('english_learning_progress')->updateOrInsert(['child_id' => $child->id, 'item_key' => $key], [
                'stage' => $stage, 'introduced_date' => $old->introduced_date ?? $date,
                'due_date' => $today->addDays($days)->toDateString(),
                'completed_date' => $correct ? $date : null,
            ]);
            $attemptId = DB::table('english_learning_answers')->insertGetId([
                'child_id' => $child->id, 'item_key' => $key, 'business_date' => $date,
                'correct' => $correct, 'created_at' => $this->clock->now(),
            ]);
            $xp = 0;
            $coins = 0;
            if ($correct) {
                $policy = RewardPolicy::query()->firstOrCreate(['family_id' => $child->family_id, 'code' => 'english.practice'], [
                    'xp_amount' => 2, 'coin_amount' => 1, 'requires_approval' => false, 'max_per_day' => self::REWARD_DAILY_LIMIT, 'is_active' => true,
                ]);
                if ($policy->is_active && ! $policy->requires_approval) {
                    $grant = $this->rewards->grant(new RewardIntent(
                        familyId: $child->family_id, childId: $child->id, policyCode: 'english.practice',
                        grantKey: "english:practice:{$child->id}:{$key}:{$date}", sourceModule: SourceModule::English,
                        sourceType: 'english_learning_answer', sourceId: $attemptId, occurredAt: $this->clock->now(),
                    ));
                    $xp = $grant->xp_amount;
                    $coins = $grant->coin_amount;
                }
            }

            return [
                'correct' => $correct,
                'meaning' => $item['meaning'],
                'xp' => $xp,
                'coins' => $coins,
                'next_review' => $today->addDays($days)->toDateString(),
            ];
        });

        // Coach tips may call the LLM — keep them outside the answer transaction.
        $coachTip = $saved['correct']
            ? $this->coach->encouragement()
            : $this->coach->tipFor(
                $child,
                $key,
                $date,
                (string) $item['text'],
                (string) ($item['meaning'] ?? $item['question'] ?? ''),
            );
        if (! $saved['correct'] && $isChallenge && ! empty($item['coach_tip'])) {
            $coachTip = (string) $item['coach_tip'];
        }
        if (! empty($saved['promoted_to_spelling'])) {
            $coachTip = '太棒了！这个词英选中已答对 2 次，接下来拼写它吧。';
        } elseif ($saved['correct'] && ($saved['mastery_phase'] ?? null) === 'mc' && isset($saved['probe_corrects'])) {
            $left = max(0, (int) ($saved['probe_goal'] ?? EnglishVocabularyService::PROBE_MC_GOAL) - (int) $saved['probe_corrects']);
            $coachTip = $left > 0
                ? "很好！再答对 {$left} 次英选中，就可以拼写了。"
                : $coachTip;
        } elseif ($isSpelling && $saved['correct'] && ! $isSkip) {
            $coachTip = '拼写正确，这个词算学会啦！';
        }

        return [
            ...$saved,
            'plan' => $this->plan($child),
            'coach_tip' => $coachTip,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parentProgress(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $plan = $this->plan($child);
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);

        return [
            'completed_today' => $plan['completed'],
            'remaining_today' => count($plan['items']),
            'due' => $plan['due'],
            'learned' => $plan['learned'],
            'mastered' => $plan['mastered'],
            'total' => $plan['total'],
            'next_review' => $plan['next_review'],
            'vocabulary' => [
                'estimated_size' => $profile['estimated_size'],
                'band' => $profile['band'],
                'path' => $profile['path'],
                'effective_band' => $profile['effective_band'],
            ],
            'path' => $profile['path'],
            'accuracy_7d' => $accuracy,
            'missed_words' => array_slice($profile['missed_words'], -10),
            'as_of' => $today,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setPath(ChildProfile $child, string $path): array
    {
        $this->vocabulary->setPath($child, $path);

        return $this->parentProgress($child);
    }

    /**
     * @return array{word: string, learning_words: list<string>}
     */
    public function addLearningWord(ChildProfile $child, string $word): array
    {
        return $this->vocabulary->addLearningWord($child, $word);
    }

    /**
     * @return array<string, mixed>
     */
    public function parentSummary(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone);
        $weekStart = $today->startOfWeek()->toDateString();
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);
        $themes = $this->recentMissThemes($child);
        $stats = [
            'band' => $profile['effective_band'],
            'path' => $profile['path'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -20),
            'missed_words' => array_slice($profile['missed_words'], -10),
            'accuracy_7d' => $accuracy,
            'missed_top' => array_slice($profile['missed_words'], -5),
            'themes' => $themes,
        ];
        $summary = $this->coach->parentWeeklySummary($child, $stats, $weekStart);

        return [
            'week_start' => $weekStart,
            'summary' => $summary['summary'],
            'focuses' => $summary['focuses'],
            'status' => $summary['status'],
            'accuracy_7d' => $accuracy,
            'missed_words' => array_slice($profile['missed_words'], -10),
        ];
    }

    private function recentAccuracy(ChildProfile $child, int $days): ?float
    {
        $from = $this->clock->today($child->family->timezone)->subDays($days - 1)->toDateString();
        $rows = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '>=', $from)
            ->get(['correct']);
        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->where('correct', true)->count() / $rows->count(), 3);
    }

    /**
     * @return list<string>
     */
    private function recentMissThemes(ChildProfile $child): array
    {
        $from = $this->clock->today($child->family->timezone)->subDays(6)->toDateString();
        $keys = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '>=', $from)
            ->where('correct', false)
            ->pluck('item_key')
            ->all();
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $themes = [];
        foreach ($keys as $key) {
            if (isset($catalog[$key])) {
                $themes[] = (string) $catalog[$key]['theme'];
            }
        }

        return array_values(array_unique($themes));
    }

    /**
     * Prefer theme groups (words + sentence) so daily new cards are not word-only.
     *
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @param  Collection<string, object>  $progress
     * @return list<array<string, mixed>>
     */
    private function pickNewCards(Collection $catalog, Collection $progress, int $newLimit): array
    {
        if ($newLimit < 1) {
            return [];
        }

        $unlearned = $catalog->reject(fn ($item) => $progress->has($item['key']));
        $picked = [];
        foreach ($unlearned->groupBy('theme') as $themeItems) {
            if (count($picked) >= $newLimit) {
                break;
            }
            /** @var Collection<int, array<string, mixed>> $themeItems */
            $words = $themeItems->where('kind', 'word')->values();
            $sentences = $themeItems->where('kind', 'sentence')->values();
            $slots = $newLimit - count($picked);

            if ($slots >= 3) {
                foreach ($words->take(2) as $word) {
                    $picked[] = $word;
                }
                if ($sentences->isNotEmpty()) {
                    $picked[] = $sentences->first();
                } elseif ($words->count() > 2) {
                    $picked[] = $words->get(2);
                }

                continue;
            }

            if ($slots === 2) {
                // Keep a short sentence in the pair whenever one remains in the theme.
                if ($words->isNotEmpty()) {
                    $picked[] = $words->first();
                }
                if ($sentences->isNotEmpty()) {
                    $picked[] = $sentences->first();
                } elseif ($words->count() > 1) {
                    $picked[] = $words->get(1);
                }

                continue;
            }

            if ($sentences->isNotEmpty() && $words->isEmpty()) {
                $picked[] = $sentences->first();
            } elseif ($words->isNotEmpty()) {
                $picked[] = $words->first();
            } elseif ($sentences->isNotEmpty()) {
                $picked[] = $sentences->first();
            }
        }

        return array_slice($picked, 0, $newLimit);
    }

    /**
     * Build a review card for catalog keys or word:{slug} daily pack keys.
     *
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @param  list<array<string, mixed>>  $wordPack
     * @return array<string, mixed>|null
     */
    private function reviewCardFor(ChildProfile $child, string $key, Collection $catalog, array $wordPack): ?array
    {
        if (isset($catalog[$key])) {
            $card = $catalog[$key];
            $options = $catalog->filter(fn ($i) => $i['kind'] === $card['kind'] && $i['key'] !== $card['key'])->pluck('text')->take(2)->all();

            return [
                ...$card,
                'ipa' => (string) ($card['ipa'] ?? ''),
                'pos' => (string) ($card['pos'] ?? ''),
                'options' => [...$options, $card['text']],
            ];
        }

        if (! str_starts_with($key, 'word:')) {
            return null;
        }

        $card = null;
        foreach ($wordPack as $packCard) {
            if (($packCard['key'] ?? '') === $key) {
                $card = $packCard;
                break;
            }
        }
        $card ??= $this->vocabulary->resolveWordCard($child, $key);
        if ($card === null) {
            return null;
        }

        $distractors = [];
        foreach ($wordPack as $other) {
            $otherKey = (string) ($other['key'] ?? '');
            $text = (string) ($other['text'] ?? '');
            if ($otherKey === $key || $text === '' || $text === (string) $card['text']) {
                continue;
            }
            $distractors[] = $text;
            if (count($distractors) >= 2) {
                break;
            }
        }
        foreach (['cat', 'dog', 'book', 'tree', 'water'] as $filler) {
            if (count($distractors) >= 2) {
                break;
            }
            if ($filler !== (string) $card['text'] && ! in_array($filler, $distractors, true)) {
                $distractors[] = $filler;
            }
        }

        return [
            ...$card,
            'options' => [...array_slice($distractors, 0, 2), (string) $card['text']],
            'daily_word' => true,
        ];
    }

    /**
     * @param  Collection<string, object>  $due
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @param  list<string>  $missed
     * @return Collection<string, object>
     */
    private function prioritizeMissed($due, $catalog, array $missed)
    {
        if ($missed === []) {
            return $due;
        }
        $missedLookup = [];
        foreach ($missed as $word) {
            $missedLookup[strtolower($word)] = true;
        }

        return $due->sortBy(function ($p) use ($catalog, $missedLookup) {
            $key = (string) $p->item_key;
            if (isset($catalog[$key])) {
                $text = strtolower((string) $catalog[$key]['text']);
            } elseif (str_starts_with($key, 'word:')) {
                $text = strtolower(str_replace('-', ' ', substr($key, strlen('word:'))));
            } else {
                return '1-'.$p->due_date;
            }
            $hit = isset($missedLookup[$text]);
            foreach (array_keys($missedLookup) as $word) {
                if ($word !== '' && str_contains($text, $word)) {
                    $hit = true;
                    break;
                }
            }

            return ($hit ? '0-' : '1-').$p->due_date;
        })->values();
    }
}
