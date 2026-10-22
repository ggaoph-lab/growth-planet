<?php

namespace App\Modules\Ledger\Console;

use App\Modules\Ledger\Services\DailyPointsService;
use Illuminate\Console\Command;

class GrantDailyPointsCommand extends Command
{
    protected $signature = 'points:grant-daily-allowance';

    protected $description = 'Grant the configured daily points allowance to each active child (idempotent per family business day)';

    public function handle(DailyPointsService $service): int
    {
        $result = $service->grantAll();
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
