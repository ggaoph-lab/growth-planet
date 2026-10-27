<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Drop short / placeholder meanings so the next poem open loads richer kid copy.
        foreach (DB::table('poetry_poem_enrichments')->select('id', 'line_meanings')->cursor() as $row) {
            $raw = $row->line_meanings;
            if ($raw === null || $raw === '') {
                continue;
            }
            $meanings = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($meanings) || $meanings === []) {
                continue;
            }
            $needsRefresh = false;
            foreach ($meanings as $m) {
                $t = trim((string) $m);
                if (
                    $t === ''
                    || mb_strlen($t) < 28
                    || str_contains($t, '慢慢想画面')
                    || str_contains($t, '体会诗人')
                    || str_contains($t, '体会意思')
                    || str_contains($t, '慢慢体会')
                ) {
                    $needsRefresh = true;
                    break;
                }
            }
            if ($needsRefresh) {
                DB::table('poetry_poem_enrichments')->where('id', $row->id)->update([
                    'line_meanings' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
