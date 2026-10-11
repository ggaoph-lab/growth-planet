<?php

namespace App\Modules\Report\Services;

use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\Habit\Models\HabitRecord;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Movie\Models\ChildMovieLibrary;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;

class CollectionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function stampsForChild(ChildProfile $child): array
    {
        $tasks = TaskInstance::query()
            ->where('child_id', $child->id)
            ->where('status', TaskInstanceStatus::Completed)
            ->count();

        $habits = HabitRecord::query()->where('child_id', $child->id)->count();
        $movies = ChildMovieLibrary::query()->where('child_id', $child->id)->where('ever_completed', true)->count();
        $english = EnglishWatchLog::query()->where('child_id', $child->id)->where('status', 'APPROVED')->count();

        $stamps = [];

        if ($tasks >= 1) {
            $stamps[] = ['key' => 'first_task', 'title' => '第一次完成任务', 'earned' => true];
        }
        if ($tasks >= 10) {
            $stamps[] = ['key' => 'tasks_10', 'title' => '完成 10 个任务', 'earned' => true];
        }
        if ($habits >= 7) {
            $stamps[] = ['key' => 'habits_7', 'title' => '习惯打卡 7 次', 'earned' => true];
        }
        if ($movies >= 1) {
            $stamps[] = ['key' => 'first_movie', 'title' => '看完第一部电影', 'earned' => true];
        }
        if ($english >= 5) {
            $stamps[] = ['key' => 'english_5', 'title' => '英语登记确认 5 次', 'earned' => true];
        }

        if ($stamps === []) {
            $stamps[] = ['key' => 'welcome', 'title' => '欢迎来到成长收藏馆', 'earned' => false];
        }

        return $stamps;
    }
}
