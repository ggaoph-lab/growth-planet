<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->string('locale', 16)->default('zh-CN');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->timestamps(3);
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            // One family per user for now; relax to unique(family_id, user_id) to support multiple families.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('role', 16);
            $table->string('display_name', 32);
            $table->string('status', 16)->default('active');
            $table->timestamps(3);

            $table->index(['family_id', 'role']);
        });

        Schema::create('child_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->unique()->constrained('family_members')->restrictOnDelete();
            $table->string('nickname', 32);
            $table->string('avatar', 32);
            // Encrypted with APP_KEY (Laravel "encrypted" cast); age is always computed, never stored.
            $table->text('birth_date');
            $table->unsignedTinyInteger('grade');
            $table->char('theme_color', 7);
            $table->string('status', 16)->default('active');
            $table->json('preferences');
            $table->timestamps(3);

            $table->index(['family_id', 'status']);
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('name', 64);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('last_used_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['family_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('child_profiles');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('families');
    }
};
