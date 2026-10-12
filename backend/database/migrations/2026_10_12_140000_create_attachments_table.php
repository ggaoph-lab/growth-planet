<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('owner_type', 64)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->string('thumb_path', 255)->nullable();
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3);
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['family_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
