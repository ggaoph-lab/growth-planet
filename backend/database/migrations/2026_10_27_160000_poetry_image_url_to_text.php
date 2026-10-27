<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('poetry_image_cache', function (Blueprint $table) {
            // Offline SVG data URIs exceed varchar(1024).
            $table->text('image_url')->nullable()->change();
            $table->string('model', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('poetry_image_cache', function (Blueprint $table) {
            $table->string('image_url', 1024)->nullable()->change();
            $table->string('model', 64)->nullable()->change();
        });
    }
};
