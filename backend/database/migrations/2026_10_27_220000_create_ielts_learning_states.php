<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ielts_learning_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->json('settings')->nullable();
            $table->json('progress')->nullable();
            $table->timestamps();
            $table->unique('child_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ielts_learning_states');
    }
};
