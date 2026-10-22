<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('pin_length')->nullable()->after('pin_hash');
        });

        // Existing PINs are overwhelmingly 4 digits in this product; parents can re-save to correct.
        DB::table('users')->whereNotNull('pin_hash')->whereNull('pin_length')->update(['pin_length' => 4]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_length');
        });
    }
};
