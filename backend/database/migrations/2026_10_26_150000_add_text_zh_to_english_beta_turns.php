<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('english_beta_turns', function (Blueprint $table) {
            $table->text('text_zh')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('english_beta_turns', function (Blueprint $table) {
            $table->dropColumn('text_zh');
        });
    }
};
