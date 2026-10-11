<?php

use App\Modules\Ledger\Services\RewardPolicySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('child_movie_library')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('language', 8)->default('zh');
            $table->unsignedTinyInteger('position_percent')->default(0);
            $table->string('status', 32)->default('NOT_STARTED');
            $table->dateTime('completed_at', 3)->nullable();
            $table->boolean('rewarded')->default(false);
            $table->unsignedBigInteger('reward_grant_id')->nullable();
            $table->timestamps(3);

            $table->unique(['library_id', 'sequence']);
        });

        $rows = DB::table('movie_progress')
            ->join('child_movie_library', 'child_movie_library.id', '=', 'movie_progress.library_id')
            ->join('movies', 'movies.id', '=', 'child_movie_library.movie_id')
            ->select([
                'movie_progress.library_id',
                'movie_progress.position_seconds',
                'movie_progress.status',
                'movie_progress.completed_at',
                'movies.runtime_minutes',
            ])
            ->get();

        $now = now();
        foreach ($rows as $row) {
            $completed = $row->status === 'COMPLETED';
            $percent = 0;
            if ($completed) {
                $percent = 100;
            } elseif ($row->runtime_minutes) {
                $percent = (int) min(100, (int) round($row->position_seconds / ($row->runtime_minutes * 60) * 100));
            }

            DB::table('movie_viewings')->insert([
                'library_id' => $row->library_id,
                'sequence' => 1,
                'language' => 'zh',
                'position_percent' => $percent,
                'status' => $completed ? 'COMPLETED' : ($percent > 0 ? 'WATCHING' : 'NOT_STARTED'),
                'completed_at' => $completed ? $row->completed_at : null,
                'rewarded' => $completed,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $seeder = app(RewardPolicySeeder::class);
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $seeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_viewings');
    }
};
