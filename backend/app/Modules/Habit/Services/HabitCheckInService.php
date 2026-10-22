<?php

namespace App\Modules\Habit\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Habit\Models\Habit;
use App\Modules\Habit\Models\HabitRecord;
use Illuminate\Database\QueryException;

class HabitCheckInService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RewardGrantService $rewards,
    ) {}

    public function checkIn(Habit $habit, ChildProfile $child, Family $family, ?string $note = null): HabitRecord
    {
        if ($habit->family_id !== $family->id || $habit->child_id !== $child->id) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }
        if (! $habit->is_active) {
            throw new ApiException('INVALID_STATE', BiMsg::of('习惯已停用', 'This habit is disabled'), 409);
        }

        $recordDate = $this->clock->today($family->timezone)->toDateString();
        $existing = HabitRecord::query()
            ->where('habit_id', $habit->id)
            ->where('record_date', $recordDate)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $record = HabitRecord::query()->create([
                'habit_id' => $habit->id,
                'child_id' => $child->id,
                'record_date' => $recordDate,
                'recorded_by' => $child->member->user_id,
                'note' => $note,
                'created_at' => $this->clock->now(),
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return HabitRecord::query()
                    ->where('habit_id', $habit->id)
                    ->where('record_date', $recordDate)
                    ->firstOrFail();
            }
            throw $e;
        }

        $this->rewards->grant(new RewardIntent(
            familyId: $family->id,
            childId: $child->id,
            policyCode: 'habit.check_in',
            grantKey: 'habit:record:'.$record->id,
            sourceModule: SourceModule::Habit,
            sourceType: 'habit_record',
            sourceId: $record->id,
            occurredAt: $this->clock->now(),
            xpAmount: (int) $habit->xp_reward,
            coinAmount: (int) $habit->coin_reward,
            pointAmount: (int) ($habit->point_reward ?? 0),
            postImmediately: true,
        ));

        return $record;
    }
}
