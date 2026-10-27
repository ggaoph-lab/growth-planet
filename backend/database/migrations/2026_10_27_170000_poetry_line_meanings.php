<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('poetry_poem_enrichments', function (Blueprint $table) {
            $table->json('line_meanings')->nullable()->after('story_zh');
        });
    }

    public function down(): void
    {
        Schema::table('poetry_poem_enrichments', function (Blueprint $table) {
            $table->dropColumn('line_meanings');
        });
    }
};
