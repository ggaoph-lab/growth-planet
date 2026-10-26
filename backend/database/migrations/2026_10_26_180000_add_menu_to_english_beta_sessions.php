<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('english_beta_sessions', function (Blueprint $table) {
            $table->string('menu_code', 32)->nullable()->after('scene');
        });
        Schema::create('english_beta_menu_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->date('menu_date');
            $table->string('menu_code', 32);
            $table->timestamps();
            $table->unique(['child_id', 'menu_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_beta_menu_choices');
        Schema::table('english_beta_sessions', function (Blueprint $table) {
            $table->dropColumn('menu_code');
        });
    }
};
