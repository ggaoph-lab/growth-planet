<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('english_vocabulary_profiles', function (Blueprint $table) {
            $table->json('learning_words')->nullable()->after('missed_words');
        });
    }

    public function down(): void
    {
        Schema::table('english_vocabulary_profiles', function (Blueprint $table) {
            $table->dropColumn('learning_words');
        });
    }
};
