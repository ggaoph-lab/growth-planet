<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_deduction_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('title', 128);
            $table->unsignedTinyInteger('point_amount')->default(0);
            $table->unsignedTinyInteger('coin_amount')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);

            $table->index(['family_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_deduction_presets');
    }
};
