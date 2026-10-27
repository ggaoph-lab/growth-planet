<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Force re-enrichment: previous placeholder copy was not real kid explanations.
        foreach (DB::table('poetry_poem_enrichments')->select('id', 'line_meanings')->cursor() as $row) {
            $raw = $row->line_meanings;
            if ($raw === null || $raw === '') {
                continue;
            }
            $meanings = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($meanings)) {
                continue;
            }
            $weak = false;
            foreach ($meanings as $m) {
                $t = (string) $m;
                if (
                    $t === ''
                    || str_contains($t, '慢慢想画面')
                    || str_contains($t, '体会诗人')
                    || str_contains($t, '体会意思')
                ) {
                    $weak = true;
                    break;
                }
            }
            if ($weak) {
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
