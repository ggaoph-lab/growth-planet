<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\EnglishBeta\VoiceTranscriber;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Poetry\Models\PoetryAssignment;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Models\PoetryRecitationRecord;
use App\Modules\Poetry\Models\PoetryReviewPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PoetryPracticeService
{
    public function __construct(
        private readonly PoetryCatalog $catalog,
        private readonly PoetryAiCoach $ai,
        private readonly PoemImageService $images,
        private readonly PoetryReviewScheduler $reviews,
        private readonly PoetryRewardService $rewards,
        private readonly PoetryRecommendationService $recommendations,
        private readonly VoiceTranscriber $transcriber,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {}

    /**
     * Hub: three entry modes for the kid planet.
     *
     * @return array<string, mixed>
     */
    public function home(ChildProfile $child): array
    {
        $this->catalog->ensureBuiltinGrade1();
        $today = $this->businessDate($child);

        $requiredCount = PoetryAssignment::query()
            ->where('child_id', $child->id)
            ->where('status', 'active')
            ->count();

        $weekly = $this->recommendations->currentFor($child, $today);

        $recitedPass = PoetryRecitationRecord::query()
            ->where('child_id', $child->id)
            ->whereIn('kind', ['recite', 'challenge'])
            ->where('passed', true)
            ->distinct()
            ->count('poem_id');

        $challengeCount = PoetryRecitationRecord::query()
            ->where('child_id', $child->id)
            ->where('kind', 'recite')
            ->where('passed', true)
            ->distinct()
            ->count('poem_id');

        return [
            'date' => $today->toDateString(),
            'stats' => [
                'recited_pass' => $recitedPass,
                'required' => $requiredCount,
                'challenge_ready' => $challengeCount,
            ],
            'modes' => [
                [
                    'key' => 'required',
                    'count' => $requiredCount,
                    'hint' => $requiredCount > 0 ? 'school' : 'empty',
                ],
                [
                    'key' => 'recommend',
                    'count' => $weekly ? 1 : 0,
                    'week_key' => $weekly?->week_key,
                    'status' => $weekly?->status,
                    'poem' => $weekly?->poem ? $this->poemCard($weekly->poem, [
                        'reason' => $weekly->reason,
                        'week_key' => $weekly->week_key,
                        'rec_status' => $weekly->status,
                    ]) : null,
                ],
                [
                    'key' => 'challenge',
                    'count' => $challengeCount,
                    'hint' => $challengeCount > 0 ? 'ready' : 'locked',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requiredList(ChildProfile $child): array
    {
        $rows = PoetryAssignment::query()
            ->with('poem')
            ->where('child_id', $child->id)
            ->where('status', 'active')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PoetryAssignment $a) => $this->poemCard($a->poem, [
                'assignment_id' => $a->id,
                'due_date' => $a->due_date?->toDateString(),
                'kind' => 'required',
            ]))
            ->all();

        return [
            'mode' => 'required',
            'poems' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recommendList(ChildProfile $child): array
    {
        $today = $this->businessDate($child);
        $weekly = $this->recommendations->currentFor($child, $today);
        $history = array_map(
            fn ($row) => $this->poemCard($row->poem, [
                'week_key' => $row->week_key,
                'reason' => $row->reason,
                'rec_status' => $row->status,
                'kind' => 'recommend',
            ]),
            $this->recommendations->historyFor($child),
        );

        return [
            'mode' => 'recommend',
            'week_key' => $weekly?->week_key,
            'current' => $weekly?->poem ? $this->poemCard($weekly->poem, [
                'reason' => $weekly->reason,
                'week_key' => $weekly->week_key,
                'rec_status' => $weekly->status,
                'kind' => 'recommend',
            ]) : null,
            'history' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function challengeList(ChildProfile $child): array
    {
        $ids = $this->masteredPoemIds($child->id)->all();
        if ($ids === []) {
            return ['mode' => 'challenge', 'poems' => [], 'suggested' => null];
        }

        $poems = PoetryPoem::query()->whereIn('id', $ids)->orderBy('title')->get();
        $cards = $poems->map(fn (PoetryPoem $p) => $this->poemCard($p, ['kind' => 'challenge']))->all();

        // Deterministic daily suggestion from mastered set.
        $today = $this->businessDate($child)->toDateString();
        $pick = $poems->values()->get(crc32($child->id.'|'.$today) % $poems->count());

        return [
            'mode' => 'challenge',
            'poems' => $cards,
            'suggested' => $pick ? $this->poemCard($pick, ['kind' => 'challenge', 'suggested' => true]) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showPoem(ChildProfile $child, int $poemId): array
    {
        $poem = $this->catalog->findOrFail($poemId);
        // storyFor seeds enrichment (local-first); line meanings then hit the same row.
        $story = $this->ai->storyFor($poem, $child->family_id);
        $authorIntro = $this->ai->authorIntroFor($poem);
        $lineMeanings = $this->ai->lineMeaningsFor($poem, $child->family_id);
        $image = $this->images->ensureImage($poem, $child->family_id);

        $records = PoetryRecitationRecord::query()
            ->where('child_id', $child->id)
            ->where('poem_id', $poem->id)
            ->orderByDesc('id')
            ->get(['kind', 'passed', 'accuracy_percent']);

        $last = $records->first();
        $readAloudDone = $records->contains(
            static fn (PoetryRecitationRecord $r) => $r->kind === 'read_aloud' && $r->passed,
        );
        $mastered = $records->contains(
            static fn (PoetryRecitationRecord $r) => $r->kind === 'recite' && $r->passed,
        );

        $plan = PoetryReviewPlan::query()
            ->where('child_id', $child->id)
            ->where('poem_id', $poem->id)
            ->first();

        $asrKey = trim((string) $this->settings->get('ai.qwen_api_key', $child->family_id));

        return [
            'poem' => [
                'id' => $poem->id,
                'title' => $poem->title,
                'author' => $poem->author,
                'dynasty' => $poem->dynasty,
                'lines' => $poem->lines(),
                'content' => $poem->content,
                'grade_tag' => $poem->grade_tag,
            ],
            'story_zh' => $story,
            'author_zh' => $authorIntro,
            'author_learned_poems' => $this->learnedPoemsBySameAuthor($child, $poem),
            'line_meanings' => $lineMeanings,
            'image' => $image,
            'voice_available' => $asrKey !== '',
            'follow_pause_ms' => (int) $this->settings->get('poetry.follow_pause_ms', $child->family_id),
            'progress' => [
                'read_aloud_done' => $readAloudDone,
                'last_passed' => (bool) ($last?->passed),
                'last_accuracy' => $last?->accuracy_percent,
                'review_status' => $plan?->status,
                'next_review_date' => $plan?->next_review_date?->toDateString(),
                'mastered' => $mastered,
            ],
        ];
    }

    /**
     * Speech-to-text for kid poetry practice (Chinese).
     *
     * @return array{text: string}
     */
    public function transcribe(ChildProfile $child, int $poemId, string $audio, string $mime): array
    {
        $this->catalog->findOrFail($poemId);
        $key = trim((string) $this->settings->get('ai.qwen_api_key', $child->family_id));
        if ($key === '') {
            throw new ApiException(
                'FORBIDDEN',
                BiMsg::of('还没有配置语音识别，请家长在设置里添加通义千问 Key', 'Speech recognition is not configured'),
                403,
            );
        }

        $asrModel = trim((string) $this->settings->get('english_beta.asr_model', $child->family_id));
        $text = $this->transcriber->transcribe(
            $audio,
            $mime === 'video/webm' ? 'audio/webm' : $mime,
            $key,
            $asrModel !== '' ? $asrModel : 'qwen3-asr-flash',
            'zh',
            500,
        );
        if ($text === null || $text === '') {
            throw new ApiException(
                'VALIDATION',
                BiMsg::of('没听清，请再试一次', 'Could not recognize speech, please try again'),
                422,
            );
        }

        return ['text' => $text];
    }

    /**
     * @return array<string, mixed>
     */
    public function markReadAloud(ChildProfile $child, int $poemId): array
    {
        $poem = $this->catalog->findOrFail($poemId);

        return DB::transaction(function () use ($child, $poem) {
            $existing = PoetryRecitationRecord::query()
                ->where('child_id', $child->id)
                ->where('poem_id', $poem->id)
                ->where('kind', 'read_aloud')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'record_id' => $existing->id,
                    'reward' => null,
                    'already' => true,
                ];
            }

            $record = PoetryRecitationRecord::query()->create([
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'poem_id' => $poem->id,
                'kind' => 'read_aloud',
                'transcript' => null,
                'passed' => true,
                'accuracy_percent' => 100,
                'diff' => null,
                'feedback' => '跟读完成，真棒！',
                'points_awarded' => 0,
            ]);

            $reward = $this->rewards->grant(
                $child,
                'poetry.read_aloud',
                "poetry:read_aloud:{$child->id}:{$poem->id}",
                'poetry_recitation',
                $record->id,
            );
            if ($reward !== null) {
                $record->points_awarded = $reward['points'];
                $record->save();
            }

            return [
                'record_id' => $record->id,
                'reward' => $reward,
                'already' => false,
            ];
        });
    }

    /**
     * @param  'recite'|'review'|'challenge'|'read_aloud'  $mode
     * @return array<string, mixed>
     */
    public function recite(ChildProfile $child, int $poemId, string $transcript, string $mode = 'recite'): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            throw new ApiException(
                'VALIDATION',
                BiMsg::of('请先说出或输入你背的诗句', 'Please recite the poem first'),
                422,
            );
        }

        if (! in_array($mode, ['recite', 'review', 'challenge', 'read_aloud'], true)) {
            $mode = 'recite';
        }

        if ($mode === 'challenge' && ! $this->masteredPoemIds($child->id)->contains($poemId)) {
            throw new ApiException(
                'FORBIDDEN',
                BiMsg::of('先学会这首诗，才能来挑战哦', 'Memorize this poem before challenging'),
                403,
            );
        }

        $poem = $this->catalog->findOrFail($poemId);
        $check = $this->ai->checkRecitation($poem, $transcript, $child->family_id);
        $today = $this->businessDate($child);

        return DB::transaction(function () use ($child, $poem, $transcript, $check, $mode, $today) {
            $record = PoetryRecitationRecord::query()->create([
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'poem_id' => $poem->id,
                'kind' => $mode,
                'transcript' => $transcript,
                'passed' => $check['passed'],
                'accuracy_percent' => $check['accuracy_percent'],
                'diff' => $check['diff'],
                'feedback' => $check['feedback'],
                'points_awarded' => 0,
            ]);

            $reward = null;
            $plan = null;
            if ($check['passed']) {
                if ($mode === 'read_aloud') {
                    $already = PoetryRecitationRecord::query()
                        ->where('child_id', $child->id)
                        ->where('poem_id', $poem->id)
                        ->where('kind', 'read_aloud')
                        ->where('passed', true)
                        ->where('id', '!=', $record->id)
                        ->exists();
                    if (! $already) {
                        $reward = $this->rewards->grant(
                            $child,
                            'poetry.read_aloud',
                            "poetry:read_aloud:{$child->id}:{$poem->id}",
                            'poetry_recitation',
                            $record->id,
                        );
                    }
                } else {
                    [$policy, $grantKey] = match ($mode) {
                        'challenge' => [
                            'poetry.challenge',
                            "poetry:challenge:{$child->id}:{$poem->id}:{$today->toDateString()}",
                        ],
                        'review' => [
                            'poetry.review',
                            "poetry:review:{$child->id}:{$poem->id}:{$today->toDateString()}",
                        ],
                        default => [
                            'poetry.recite',
                            "poetry:recite:{$child->id}:{$poem->id}",
                        ],
                    };

                    $reward = $this->rewards->grant(
                        $child,
                        $policy,
                        $grantKey,
                        'poetry_recitation',
                        $record->id,
                    );

                    if ($mode === 'review') {
                        $plan = $this->reviews->advanceAfterReview(
                            $child->family_id,
                            $child->id,
                            $poem->id,
                            $today,
                        );
                    } elseif ($mode !== 'challenge') {
                        $plan = $this->reviews->ensurePlan(
                            $child->family_id,
                            $child->id,
                            $poem->id,
                            $today,
                        );
                    }

                    PoetryAssignment::query()
                        ->where('child_id', $child->id)
                        ->where('poem_id', $poem->id)
                        ->where('status', 'active')
                        ->update(['status' => 'done']);

                    $this->recommendations->markDone($child, $poem->id);
                }

                if ($reward !== null) {
                    $record->points_awarded = $reward['points'];
                    $record->save();
                }
            }

            return [
                'record_id' => $record->id,
                'passed' => $check['passed'],
                'accuracy_percent' => $check['accuracy_percent'],
                'feedback' => $check['feedback'],
                'diff' => $check['diff'],
                'provider' => $check['provider'],
                'reward' => $reward,
                'review' => $plan ? [
                    'status' => $plan->status,
                    'step_index' => $plan->step_index,
                    'next_review_date' => $plan->next_review_date?->toDateString(),
                ] : null,
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function masteredPoemIds(int $childId)
    {
        return PoetryRecitationRecord::query()
            ->where('child_id', $childId)
            ->where('kind', 'recite')
            ->where('passed', true)
            ->pluck('poem_id')
            ->unique()
            ->values();
    }

    /**
     * Other poems by the same author that this child has already recited successfully.
     *
     * @return list<array{id: int, title: string}>
     */
    private function learnedPoemsBySameAuthor(ChildProfile $child, PoetryPoem $poem): array
    {
        $author = trim((string) ($poem->author ?? ''));
        if ($author === '') {
            return [];
        }

        $masteredIds = $this->masteredPoemIds($child->id)
            ->reject(static fn ($id) => (int) $id === (int) $poem->id)
            ->values();
        if ($masteredIds->isEmpty()) {
            return [];
        }

        return PoetryPoem::query()
            ->where('author', $author)
            ->whereIn('id', $masteredIds->all())
            ->orderBy('id')
            ->get(['id', 'title'])
            ->map(static fn (PoetryPoem $row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
            ])
            ->values()
            ->all();
    }

    private function businessDate(ChildProfile $child): CarbonImmutable
    {
        $child->loadMissing('family');
        $tz = $child->family?->timezone ?: config('app.timezone', 'Asia/Shanghai');

        return $this->clock->now()->timezone($tz)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function poemCard($poem, array $extra = []): array
    {
        return array_merge([
            'id' => $poem->id,
            'title' => $poem->title,
            'author' => $poem->author,
            'dynasty' => $poem->dynasty,
            'preview' => $poem->lines()[0] ?? '',
        ], $extra);
    }
}
