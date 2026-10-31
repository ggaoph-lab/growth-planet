<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_redemptions', fn (Blueprint $table) => $table->dateTime('fulfilled_at', 3)->nullable());
        // Historical approvals and fulfillment happened in the same transaction.
        DB::table('reward_redemptions as r')->join('approval_requests as a', 'a.id', '=', 'r.approval_request_id')
            ->where('r.status', 'fulfilled')->where('a.status', 'APPROVED')->whereNotNull('a.decided_at')
            ->update(['r.fulfilled_at' => DB::raw('a.decided_at')]);
    }

    public function down(): void
    {
        Schema::table('reward_redemptions', fn (Blueprint $table) => $table->dropColumn('fulfilled_at'));
    }
};
