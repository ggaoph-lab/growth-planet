<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->unsignedInteger('coin_price')->nullable();
            $table->string('status', 16);
            $table->foreignId('reward_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps(3);

            $table->index(['family_id', 'child_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
    }
};
