<?php

namespace App\Modules\English\Console;

use App\Modules\English\Services\EnglishSpeakService;
use Illuminate\Console\Command;

class PruneEnglishSpeakAttemptsCommand extends Command
{
    protected $signature = 'english:prune-speak-attempts';

    protected $description = '删除过期的英语跟读录音（默认保留 7 天）';

    public function handle(EnglishSpeakService $speak): int
    {
        $count = $speak->pruneExpired();
        $this->info("Pruned {$count} english speak attempt(s).");

        return self::SUCCESS;
    }
}
