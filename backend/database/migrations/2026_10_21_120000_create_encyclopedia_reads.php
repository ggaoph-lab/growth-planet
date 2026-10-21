<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encyclopedia_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_id');
            $table->unsignedBigInteger('child_id');
            $table->string('topic_id', 32);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['child_id', 'topic_id']);
            $table->index(['family_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encyclopedia_reads');
    }
};
