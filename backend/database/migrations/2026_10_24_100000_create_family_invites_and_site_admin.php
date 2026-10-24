<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_site_admin')->default(false)->after('last_login_at');
        });

        Schema::create('family_invites', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('code_prefix', 4);
            $table->string('note', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('expires_at', 3)->nullable();
            $table->dateTime('used_at', 3)->nullable();
            $table->foreignId('used_by_family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->timestamps(3);

            $table->index('used_at');
            $table->index('expires_at');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_invites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_site_admin');
        });
    }
};
