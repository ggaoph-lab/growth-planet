<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps(3);

            $table->unique(['family_id', 'name']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('family_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->after('category')->constrained('task_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::dropIfExists('task_categories');
    }
};
