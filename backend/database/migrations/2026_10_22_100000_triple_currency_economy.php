<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xp_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(1)->after('child_id');
        });

        Schema::create('point_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->unique()->constrained('child_profiles')->restrictOnDelete();
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('held')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('updated_at', 3);

            $table->index('family_id');
        });

        Schema::create('point_ledger', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->integer('amount');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->string('entry_type', 32);
            $table->string('source_module', 32);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->date('business_date');
            $table->dateTime('created_at', 3);

            $table->index(['child_id', 'business_date']);
            $table->index(['account_id', 'created_at']);
        });

        Schema::create('point_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 16);
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('captured_ledger_id')->nullable();
            $table->timestamps(3);

            $table->index(['account_id', 'status']);
        });

        Schema::table('reward_policies', function (Blueprint $table) {
            $table->unsignedInteger('point_amount')->default(0)->after('coin_amount');
        });

        Schema::table('reward_grants', function (Blueprint $table) {
            $table->unsignedInteger('point_amount')->default(0)->after('coin_amount');
            $table->unsignedBigInteger('point_ledger_id')->nullable()->after('coin_ledger_id');
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->unsignedInteger('point_price')->nullable()->after('coin_price');
        });

        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->unsignedInteger('point_price')->nullable()->after('coin_price');
            $table->unsignedBigInteger('point_hold_id')->nullable()->after('coin_hold_id');
            $table->unsignedBigInteger('point_ledger_id')->nullable()->after('coin_ledger_id');
        });

        foreach (DB::table('child_profiles')->select('id', 'family_id')->cursor() as $row) {
            if (! DB::table('point_accounts')->where('child_id', $row->id)->exists()) {
                DB::table('point_accounts')->insert([
                    'family_id' => $row->family_id,
                    'child_id' => $row->id,
                    'balance' => 0,
                    'held' => 0,
                    'lifetime_earned' => 0,
                    'lock_version' => 0,
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('families')->pluck('id') as $familyId) {
            if (! DB::table('reward_policies')->where('family_id', $familyId)->where('code', 'points.daily_allowance')->exists()) {
                DB::table('reward_policies')->insert([
                    'family_id' => $familyId,
                    'code' => 'points.daily_allowance',
                    'xp_amount' => 0,
                    'coin_amount' => 0,
                    'point_amount' => 10,
                    'requires_approval' => false,
                    'max_per_event' => 100,
                    'max_per_day' => 100,
                    'max_per_week' => null,
                    'max_per_subject' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE point_accounts ADD CONSTRAINT point_accounts_balance_nonneg CHECK (balance >= 0)');
            DB::statement('ALTER TABLE point_accounts ADD CONSTRAINT point_accounts_held_le_balance CHECK (held <= balance)');
            DB::unprepared("CREATE TRIGGER point_ledger_no_update BEFORE UPDATE ON point_ledger
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'point_ledger is append-only'");
            DB::unprepared("CREATE TRIGGER point_ledger_no_delete BEFORE DELETE ON point_ledger
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'point_ledger is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS point_ledger_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS point_ledger_no_delete');
            try {
                DB::statement('ALTER TABLE point_accounts DROP CHECK point_accounts_balance_nonneg');
            } catch (\Throwable) {
            }
            try {
                DB::statement('ALTER TABLE point_accounts DROP CHECK point_accounts_held_le_balance');
            } catch (\Throwable) {
            }
        }

        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropColumn(['point_price', 'point_hold_id', 'point_ledger_id']);
        });
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn('point_price');
        });
        Schema::table('reward_grants', function (Blueprint $table) {
            $table->dropColumn(['point_amount', 'point_ledger_id']);
        });
        Schema::table('reward_policies', function (Blueprint $table) {
            $table->dropColumn('point_amount');
        });

        Schema::dropIfExists('point_holds');
        Schema::dropIfExists('point_ledger');
        Schema::dropIfExists('point_accounts');

        Schema::table('xp_accounts', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
