<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('family_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->string('title', 100);
            $table->json('weekdays');
            $table->json('times');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['family_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_reminders');
    }
};
