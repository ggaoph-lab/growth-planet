<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Modules\Poetry\Models\PoetryAssignment;
use App\Modules\Poetry\Models\PoetryWeeklyRecommendation;
use App\Modules\Poetry\Services\PoemImageService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('poetry:prepare-assets {--limit=500}', function () {
    $limit = max(1, min(5000, (int) $this->option('limit')));
    $pairs = PoetryAssignment::query()->where('status', 'active')
        ->select('poem_id', 'family_id')
        ->union(PoetryWeeklyRecommendation::query()->select('poem_id', 'family_id'))
        ->limit($limit)->get();
    $images = app(PoemImageService::class);
    foreach ($pairs as $pair) {
        $images->schedulePreparation((int) $pair->poem_id, (int) $pair->family_id);
    }
    $this->info('Scheduled '.$pairs->count().' assigned or recommended poem assets.');
})->purpose('Queue image and line-meaning preparation for existing poetry assignments');

// Queue housekeeping. Task instances are generated lazily on kid/parent access;
// the scheduler process stays up for this prune job and future scheduled work.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:15');
Schedule::command('english:prune-speak-attempts')->dailyAt('03:30');
// Family-local 05:00, with catch-up after restarts and at most three bounded attempts per day.
Schedule::command('english:prepare-day')->everyTenMinutes()->withoutOverlapping(60)->runInBackground();
// Family-local daily points; runs often with grant-key idempotency so each child gets at most one grant per business day.
Schedule::command('points:grant-daily-allowance')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();
