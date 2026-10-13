<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('english_learning_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->unsignedTinyInteger('stage')->default(0);
            $table->date('due_date');
            $table->date('introduced_date');
            $table->date('completed_date')->nullable();
            $table->unique(['child_id', 'item_key']);
        });
        Schema::create('english_learning_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->date('business_date');
            $table->boolean('correct');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_learning_answers');
        Schema::dropIfExists('english_learning_progress');
    }
};
