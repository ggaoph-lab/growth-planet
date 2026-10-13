<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('english_vocabulary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->unsignedInteger('estimated_size')->default(0);
            $table->unsignedTinyInteger('band')->default(1);
            $table->json('known_words');
            $table->json('missed_words');
            $table->timestamp('updated_at')->nullable();
            $table->unique('child_id');
        });

        Schema::create('english_ai_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('purpose', 32);
            $table->date('business_date');
            $table->json('profile_snapshot');
            $table->json('items');
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->unique(['child_id', 'business_date', 'purpose']);
        });

        DB::table('reward_policies')
            ->where('code', 'english.practice')
            ->where('max_per_day', 8)
            ->update(['max_per_day' => 10]);
    }

    public function down(): void
    {
        Schema::dropIfExists('english_ai_records');
        Schema::dropIfExists('english_vocabulary_profiles');
    }
};
