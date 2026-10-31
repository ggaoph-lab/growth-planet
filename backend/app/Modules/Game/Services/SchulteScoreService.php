<?php

namespace App\Modules\Game\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Game\Models\SchulteDailyScore;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\RewardGrantStatus;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SchulteScoreService
{
    public const MAX_MS = 1_800_000;

    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
        private readonly GamePlayService $plays,
    ) {}

    /**
     * @return array{limit_ms: int, coins: int, min_ms: int}
     */
    public static function rule(int $size): array
    {
        return match ($size) {
            3 => ['limit_ms' => 9_000, 'coins' => 1, 'min_ms' => 1_200],
            4 => ['limit_ms' => 16_000, 'coins' => 2, 'min_ms' => 2_000],
            5 => ['limit_ms' => 25_000, 'coins' => 3, 'min_ms' => 3_500],
            default => throw new ApiException('VALIDATION_FAILED', BiMsg::of('方格大小不对', 'That grid size is not available')),
        };
    }

    /**
     * @return array{date: string, sizes: list<array<string, mixed>>}
     */
    public function board(ChildProfile $child, Family $family): array
    {
        $date = $this->clock->today($family->timezone)->toDateString();
        $scores = SchulteDailyScore::query()
            ->with('child')
            ->where('family_id', $family->id)
            ->where('played_on', $date)
            ->get();

        $sizes = [];
        foreach ([3, 4, 5] as $size) {
            $rule = self::rule($size);
            $rows = $scores
                ->where('size', $size)
                ->filter(fn (SchulteDailyScore $score) => $score->child !== null && $score->child->status !== ChildStatus::Archived)
                ->sortBy('best_ms')
                ->values();
            $mine = $rows->firstWhere('child_id', $child->id);
            $sizes[] = [
                'size' => $size,
                'limit_ms' => $rule['limit_ms'],
                'coins' => $rule['coins'],
                'best_ms' => $mine?->best_ms,
                'awarded' => ($mine?->coins_awarded ?? 0) > 0,
                'remaining' => $this->plays->remaining($child, $family, 'schulte', (string) $size),
                'ranks' => $rows->map(fn (SchulteDailyScore $score) => [
                    'child_id' => $score->child_id,
                    'nickname' => $score->child?->nickname ?? '',
                    'best_ms' => $score->best_ms,
                    'coins' => $score->coins_awarded,
                ])->all(),
            ];
        }

        return ['date' => $date, 'sizes' => $sizes];
    }

    /**
     * @return array{elapsed_ms: int, improved: bool, awarded_coins: int, board: array{date: string, sizes: list<array<string, mixed>>}}
     */
    public function record(ChildProfile $child, Family $family, int $size, int $elapsedMs, ?string $playId = null): array
    {
        $rule = self::rule($size);
        if ($elapsedMs < $rule['min_ms'] || $elapsedMs > self::MAX_MS) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('这次用时记不下来', 'That time cannot be saved'));
        }

        $date = $this->clock->today($family->timezone)->toDateString();
        $awarded = 0;
        $improved = false;

        DB::transaction(function () use ($child, $family, $size, $elapsedMs, $playId, $rule, $date, &$awarded, &$improved): void {
            if ($playId !== null) $this->plays->finish($child, $family, 'schulte', (string) $size, $playId);
            [$score, $improved] = $this->lockScore($child, $family, $size, $date, $elapsedMs);
            if ($score->reward_grant_id === null && $elapsedMs <= $rule['limit_ms']) {
                $grant = $this->rewards->grant(new RewardIntent(
                    familyId: $family->id,
                    childId: $child->id,
                    policyCode: 'game.schulte',
                    grantKey: 'schulte:'.$child->id.':'.$date.':'.$size,
                    sourceModule: SourceModule::Game,
                    sourceType: 'schulte_daily_score',
                    sourceId: $score->id,
                    occurredAt: $this->clock->now(),
                    xpAmount: 0,
                    coinAmount: 0,
                    pointAmount: $rule['coins'],
                    postImmediately: true,
                ));
                $score->reward_grant_id = $grant->id;
                $awarded = $grant->status === RewardGrantStatus::Granted ? $grant->point_amount : 0;
                $score->coins_awarded = $awarded;
            }
            if ($score->isDirty()) {
                $score->save();
            }
        });

        return [
            'elapsed_ms' => $elapsedMs,
            'improved' => $improved,
            'awarded_coins' => $awarded,
            'board' => $this->board($child, $family),
        ];
    }

    /**
     * @return array{0: SchulteDailyScore, 1: bool}
     */
    private function lockScore(ChildProfile $child, Family $family, int $size, string $date, int $elapsedMs): array
    {
        $score = SchulteDailyScore::query()
            ->where('child_id', $child->id)
            ->where('played_on', $date)
            ->where('size', $size)
            ->lockForUpdate()
            ->first();

        if ($score === null) {
            try {
                $score = SchulteDailyScore::query()->create([
                    'family_id' => $family->id,
                    'child_id' => $child->id,
                    'played_on' => $date,
                    'size' => $size,
                    'best_ms' => $elapsedMs,
                    'coins_awarded' => 0,
                ]);

                return [$score, true];
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'Duplicate') && $e->getCode() !== '23000') {
                    throw $e;
                }
                $score = SchulteDailyScore::query()
                    ->where('child_id', $child->id)
                    ->where('played_on', $date)
                    ->where('size', $size)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $improved = false;
        if ($elapsedMs < $score->best_ms) {
            $score->best_ms = $elapsedMs;
            $improved = true;
        }

        return [$score, $improved];
    }
}
