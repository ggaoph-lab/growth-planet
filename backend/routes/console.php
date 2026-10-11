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
