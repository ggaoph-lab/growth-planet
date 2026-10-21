<?php

namespace App\Modules\English\Console;

use App\Modules\English\Services\EnglishDailyPreparation;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareEnglishDayCommand extends Command
{
    protected $signature = 'english:prepare-day {--now : Prepare before 05:00 as well}';

    protected $description = 'Prepare daily English words, fairy tales and speech after 05:00 in each family timezone';

    public function handle(EnglishDailyPreparation $preparation): int
    {
        $failed = false;
        foreach (ChildProfile::with('family')->where('status', ChildStatus::Active->value)->lazyById(100) as $child) {
            try {
                $result = $preparation->prepare($child, (bool) $this->option('now'));
                if ($result !== null) {
                    $this->info(json_encode(['child_id' => $child->id, ...$result], JSON_THROW_ON_ERROR));
                }
            } catch (Throwable $e) {
                $failed = true;
                Log::warning('english.daily_preparation_failed', ['child_id' => $child->id, 'exception_type' => $e::class]);
                $this->warn("Preparation failed for child {$child->id}; existing content is retained.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
