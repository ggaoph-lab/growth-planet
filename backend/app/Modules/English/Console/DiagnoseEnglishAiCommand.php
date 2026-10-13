<?php

namespace App\Modules\English\Console;

use App\Modules\Core\Support\Clock;
use App\Modules\English\Services\EnglishAiCoach;
use App\Modules\English\Services\EnglishLearningService;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\FamilyAiConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Production helper: explain why English Lab AI status is green or gray.
 */
class DiagnoseEnglishAiCommand extends Command
{
    protected $signature = 'english:diagnose-ai
                            {child_id? : Child profile id (defaults to first child)}
                            {--retry : Drop today\'s local fallbacks and rebuild the plan once}';

    protected $description = 'Diagnose English Lab AI green/gray status for a child';

    public function handle(FamilyAiConfig $ai, EnglishAiCoach $coach, EnglishLearningService $learning, Clock $clock): int
    {
        $childId = $this->argument('child_id');
        $child = $childId
            ? ChildProfile::query()->with('family')->find($childId)
            : ChildProfile::query()->with('family')->orderBy('id')->first();

        if ($child === null) {
            $this->error('No child profile found.');

            return self::FAILURE;
        }

        $today = $clock->today($child->family->timezone)->toDateString();
        $cfg = $ai->present((int) $child->family_id);
        $selected = $coach->selectedProvider($child);
        $status = $coach->connectionStatus($child, $today);
        $records = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $today)
            ->orderBy('id')
            ->get(['purpose', 'status', 'provider', 'model']);

        $this->line("child_id={$child->id} nickname={$child->nickname} family_id={$child->family_id}");
        $this->line("date={$today}");
        $this->line("settings.provider={$cfg['provider']} selected=".($selected ?? 'null'));
        $this->line('gemini='.$cfg['gemini']['source'].' qwen='.$cfg['qwen']['source'].' openai='.$cfg['openai']['source']);
        $this->line("ai_status={$status}");
        $this->line('records_today='.$records->count());
        foreach ($records as $row) {
            $this->line('  - '.json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        if ($selected === null) {
            $this->warn('Gray/off: set Default AI engine to Gemini, Qwen, or ChatGPT (not Auto).');
        } elseif ($records->isEmpty()) {
            $this->warn('No AI records yet today. Open English Lab once, or pass --retry.');
        } elseif (! $records->contains(fn ($row) => in_array((string) $row->status, ['ai', 'mixed'], true))) {
            $this->warn('Gray/failed: today only has local fallbacks. Chat call failed (quota/network/model).');
            $this->warn('Fix: switch to a working engine (e.g. Qwen), then re-open English Lab or pass --retry.');
        } else {
            $this->info('Green/ok: at least one ai/mixed record exists for today.');
        }

        if ($this->option('retry')) {
            if ($selected === null) {
                $this->error('Cannot retry without an explicit engine.');

                return self::FAILURE;
            }
            foreach (['session_plan', 'coach_tips', 'vocabulary_probe'] as $purpose) {
                $coach->discardLocalRecord($child, $purpose, $today);
            }
            // Force rebuild even when local rows were stamped with the current provider.
            DB::table('english_ai_records')
                ->where('child_id', $child->id)
                ->where('business_date', $today)
                ->where('status', 'local')
                ->delete();

            $plan = $learning->plan($child);
            $this->line('after_retry ai_status='.$plan['ai_status']);
            $this->line('after_retry items='.count($plan['items']));
        }

        return self::SUCCESS;
    }
}
