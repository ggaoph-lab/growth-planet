<?php

namespace App\Modules\English\Services;

class EnglishSkillPilot
{
    /** @param list<string> $knownWords */
    public function focus(array $knownWords): array
    {
        $known = array_fill_keys(array_map('strtolower', $knownWords), true);
        $stages = config('english_skill_pilot.stages');
        if (! is_array($stages)) {
            $stages = (require base_path('config/english_skill_pilot.php'))['stages'];
        }
        foreach ($stages as $stage) {
            $anchors = $stage['anchor_words'] ?? [];
            $recognized = count(array_filter($anchors, fn ($word) => isset($known[strtolower($word)])));
            if ($recognized < min(3, count($anchors))) {
                return $stage;
            }
        }

        return end($stages) ?: [];
    }
}
