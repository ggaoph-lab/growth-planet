<?php

namespace App\Modules\EnglishBeta;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WeeklyPlan
{
    public function forChild(int $childId, string $timezone, int $targetDays, int $targetMinutes, SceneMission $mission): array
    {
        $start = CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $start->addWeek();
        $sessions = DB::table('english_beta_sessions')->where('child_id', $childId)
            ->where('started_at', '>=', $start->utc())->where('started_at', '<', $end->utc())->get();
        $sessionIds = $sessions->pluck('id')->all();
        $activeIds = DB::table('english_beta_turns')->whereIn('session_id', $sessionIds)->where('speaker', 'child')->pluck('session_id')
            ->merge(DB::table('english_beta_word_evidence')->whereIn('session_id', $sessionIds)->pluck('session_id'))->unique()->all();
        $activityTimes = DB::table('english_beta_turns')->join('english_beta_sessions', 'english_beta_sessions.id', '=', 'english_beta_turns.session_id')
            ->where('english_beta_sessions.child_id', $childId)->where('english_beta_turns.speaker', 'child')
            ->where('english_beta_turns.created_at', '>=', $start->utc())->where('english_beta_turns.created_at', '<', $end->utc())
            ->pluck('english_beta_turns.created_at')
            ->merge(DB::table('english_beta_word_evidence')->where('child_id', $childId)
                ->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())->pluck('created_at'));
        $days = $activityTimes->map(fn ($at) => CarbonImmutable::parse($at, 'UTC')->setTimezone($timezone)->toDateString())->unique()->count();
        $sceneCounts = $sessions->whereIn('id', $activeIds)->countBy('scene');
        $scene = ($sceneCounts['restaurant'] ?? 0) < ($sceneCounts['supermarket'] ?? 0) ? 'restaurant' : 'supermarket';
        $vocabulary = DB::table('english_beta_vocabulary')->where('child_id', $childId)->get()->keyBy('word');
        $mastery = [
            'understood' => $vocabulary->filter(fn ($row) => (bool) $row->understood)->count(),
            'spoken' => $vocabulary->filter(fn ($row) => (bool) $row->spoken)->count(),
            'used_in_sentence' => $vocabulary->filter(fn ($row) => (bool) $row->used_in_sentence)->count(),
            'total' => $vocabulary->count(),
        ];
        $words = $mission->words($scene);
        usort($words, function ($a, $b) use ($vocabulary) {
            $score = fn ($word) => (int) ($vocabulary[$word]->understood ?? false)
                + (int) ($vocabulary[$word]->spoken ?? false)
                + (int) ($vocabulary[$word]->used_in_sentence ?? false);
            return $score($a) <=> $score($b);
        });
        return [
            'week_start' => $start->toDateString(),
            'practiced_days' => $days,
            'target_days' => $targetDays,
            'remaining_days' => max(0, $targetDays - $days),
            'target_minutes' => $targetMinutes,
            'sessions' => $sessions->count(),
            'completed_sessions' => $sessions->whereNotNull('ended_at')->count(),
            'suggested_scene' => $scene,
            'suggested_word' => $words[0],
            'mastery' => $mastery,
        ];
    }
}
