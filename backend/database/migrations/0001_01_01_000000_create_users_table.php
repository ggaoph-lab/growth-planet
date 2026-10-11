<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Parents log in with username/email + password; children have neither and use device + PIN.
            $table->string('username', 32)->nullable()->unique();
            $table->string('email', 191)->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until', 3)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
            $table->rememberToken();
            $table->timestamps(3);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
