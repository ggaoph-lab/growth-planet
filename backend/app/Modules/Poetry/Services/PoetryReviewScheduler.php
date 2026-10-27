<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Models\PoetryReviewPlan;
use Carbon\CarbonImmutable;

/**
 * Ebbinghaus-style review: after first successful recite on anchor day D,
 * due on D+1, D+2, D+4, D+7, D+15 (configurable via poetry.review_intervals_days).
 */
class PoetryReviewScheduler
{
    /** @return list<int> */
    public function intervals(): array
    {
        $raw = config('poetry.review_intervals_days', [1, 2, 4, 7, 15]);
        if (! is_array($raw) || $raw === []) {
            return [1, 2, 4, 7, 15];
        }

        return array_values(array_map('intval', $raw));
    }

    /** First successful recite: open a plan (no-op if one already exists). */
    public function ensurePlan(
        int $familyId,
        int $childId,
        int $poemId,
        CarbonImmutable $businessDate,
    ): PoetryReviewPlan {
        $existing = PoetryReviewPlan::query()
            ->where('child_id', $childId)
            ->where('poem_id', $poemId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $intervals = $this->intervals();
        $next = $businessDate->addDays($intervals[0] ?? 1);

        return PoetryReviewPlan::query()->create([
            'family_id' => $familyId,
            'child_id' => $childId,
            'poem_id' => $poemId,
            'anchor_date' => $businessDate->toDateString(),
            'step_index' => 0,
            'next_review_date' => $next->toDateString(),
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    /** Successful review challenge: advance to the next interval (or master). */
    public function advanceAfterReview(
        int $familyId,
        int $childId,
        int $poemId,
        CarbonImmutable $businessDate,
    ): PoetryReviewPlan {
        $plan = $this->ensurePlan($familyId, $childId, $poemId, $businessDate);
        if ($plan->status === 'mastered') {
            return $plan;
        }

        $intervals = $this->intervals();
        $nextIndex = ((int) $plan->step_index) + 1;
        if ($nextIndex >= count($intervals)) {
            $plan->fill([
                'step_index' => max(0, count($intervals) - 1),
                'status' => 'mastered',
                'completed_at' => now(),
                'next_review_date' => $businessDate->toDateString(),
            ])->save();

            return $plan->fresh();
        }

        $anchor = CarbonImmutable::parse($plan->anchor_date->toDateString(), $businessDate->timezone);
        $next = $anchor->addDays($intervals[$nextIndex]);
        $plan->fill([
            'step_index' => $nextIndex,
            'next_review_date' => $next->toDateString(),
            'status' => 'pending',
            'completed_at' => null,
        ])->save();

        return $plan->fresh();
    }

    /**
     * @return list<PoetryReviewPlan>
     */
    public function dueForChild(int $childId, CarbonImmutable $businessDate): array
    {
        return PoetryReviewPlan::query()
            ->with('poem')
            ->where('child_id', $childId)
            ->whereIn('status', ['pending', 'due'])
            ->whereDate('next_review_date', '<=', $businessDate->toDateString())
            ->orderBy('next_review_date')
            ->get()
            ->all();
    }

    /** Pure helper for unit tests / docs. */
    public function nextReviewDate(CarbonImmutable $anchor, int $stepIndex): ?CarbonImmutable
    {
        $intervals = $this->intervals();
        if ($stepIndex < 0 || $stepIndex >= count($intervals)) {
            return null;
        }

        return $anchor->addDays($intervals[$stepIndex]);
    }
}
