<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Queue housekeeping. Task instances are generated lazily on kid/parent access;
// the scheduler process stays up for this prune job and future scheduled work.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:15');
Schedule::command('english:prune-speak-attempts')->dailyAt('03:30');
// Family-local 05:00, with catch-up after restarts and at most three bounded attempts per day.
Schedule::command('english:prepare-day')->everyTenMinutes()->withoutOverlapping(60)->runInBackground();
