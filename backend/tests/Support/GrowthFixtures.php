<?php

namespace Tests\Support;

use App\Modules\Food\Models\Food;
use App\Modules\Ledger\Services\RewardPolicySeeder;
use App\Modules\Movie\Enums\MovieSafetyStatus;
use App\Modules\Movie\Models\Movie;
use App\Modules\Movie\Models\MovieAgeReview;
use App\Modules\Movie\Models\MovieApproval;
use App\Modules\English\Models\EnglishShow;
use App\Modules\Habit\Models\Habit;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\User;
use App\Modules\Item\Models\Item;
use App\Modules\Item\Services\ItemLocationSeeder;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Task\Enums\ScheduleFrequency;
use App\Modules\Task\Enums\TaskKind;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskSchedule;

trait GrowthFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedTask(Family $family, ?User $parent = null, array $overrides = []): Task
    {
        $task = Task::query()->create(array_merge([
            'family_id' => $family->id,
            'child_id' => null,
            'title' => '每日任务',
            'kind' => TaskKind::Standard,
            'start_date' => '2026-10-01',
            'xp_reward' => 10,
            'coin_reward' => 5,
            'requires_approval' => true,
            'requires_self_check' => false,
            'is_active' => true,
            'version' => 1,
            'created_by' => $parent?->id,
        ], $overrides));

        TaskSchedule::query()->create([
            'task_id' => $task->id,
            'frequency' => ScheduleFrequency::Daily,
        ]);

        return $task;
    }

    protected function seedHabit(Family $family, ChildProfile $child, array $overrides = []): Habit
    {
        return Habit::query()->create(array_merge([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'title' => '好习惯',
            'xp_reward' => 3,
            'coin_reward' => 1,
            'frequency' => 'daily',
            'target_per_period' => 1,
            'is_active' => true,
        ], $overrides));
    }

    protected function seedItem(Family $family, ChildProfile $child, array $overrides = []): Item
    {
        $homeId = app(ItemLocationSeeder::class)->defaultHomeLocationId($family->id);

        return Item::query()->create(array_merge([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'name' => '水杯',
            'location_id' => $homeId,
            'status' => 'normal',
            'carry_daily' => true,
        ], $overrides));
    }

    protected function seedFood(Family $family, array $overrides = []): Food
    {
        return Food::query()->create(array_merge([
            'family_id' => $family->id,
            'name' => '西兰花',
        ], $overrides));
    }

    protected function seedReward(Family $family, array $overrides = []): Reward
    {
        return Reward::query()->create(array_merge([
            'family_id' => $family->id,
            'name' => '奖励',
            'kind' => RewardKind::Physical,
            'coin_price' => 20,
            'requires_approval' => true,
            'status' => RewardStatus::OnShelf,
            'version' => 1,
        ], $overrides));
    }

    protected function ensureMediaRewardPolicies(Family $family): void
    {
        app(RewardPolicySeeder::class)->seedForFamily($family->id);
    }

    protected function seedVisibleMovie(Family $family, ChildProfile $child, User $parent, array $overrides = []): Movie
    {
        $movie = Movie::query()->create(array_merge([
            'family_id' => $family->id,
            'source' => 'manual',
            'title_zh' => '测试电影',
            'runtime_minutes' => 90,
            'data_completeness' => 'insufficient',
        ], $overrides));

        $review = MovieAgeReview::query()->create([
            'movie_id' => $movie->id,
            'safety_status' => MovieSafetyStatus::ApprovedSuitable,
            'min_age' => 6,
            'reviewer_id' => $parent->id,
            'created_at' => now(),
        ]);
        $movie->current_review_id = $review->id;
        $movie->data_completeness = 'complete';
        $movie->save();

        MovieApproval::query()->create([
            'movie_id' => $movie->id,
            'child_id' => $child->id,
            'visible' => true,
            'approved_by' => $parent->id,
            'approved_at' => now(),
        ]);

        return $movie->fresh();
    }

    protected function seedEnglishShow(Family $family, array $overrides = []): EnglishShow
    {
        return EnglishShow::query()->create(array_merge([
            'family_id' => $family->id,
            'title' => 'Peppa Pig',
            'emoji' => '🐷',
            'active' => true,
        ], $overrides));
    }
}
