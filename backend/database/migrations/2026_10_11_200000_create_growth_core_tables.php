<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->unique()->constrained('child_profiles')->restrictOnDelete();
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('updated_at', 3);

            $table->index('family_id');
        });

        Schema::create('coin_accounts', function (Blueprint $table) {
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

        Schema::create('xp_ledger', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('xp_accounts')->restrictOnDelete();
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

        Schema::create('coin_ledger', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('coin_accounts')->restrictOnDelete();
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

        Schema::create('coin_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('coin_accounts')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 16);
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('captured_ledger_id')->nullable();
            $table->timestamps(3);

            $table->index(['account_id', 'status']);
        });

        Schema::create('reward_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('xp_amount')->default(0);
            $table->unsignedInteger('coin_amount')->default(0);
            $table->boolean('requires_approval')->default(false);
            $table->unsignedSmallInteger('max_per_event')->nullable();
            $table->unsignedSmallInteger('max_per_day')->nullable();
            $table->unsignedSmallInteger('max_per_week')->nullable();
            $table->unsignedSmallInteger('max_per_subject')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);

            $table->unique(['family_id', 'code']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 16);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('requested_at', 3);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at', 3)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('active_key', 128)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(3);

            $table->index(['family_id', 'status']);
            $table->index(['child_id', 'status']);
        });

        Schema::create('approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['approval_request_id', 'created_at']);
        });

        Schema::create('reward_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('policy_code', 64);
            $table->string('grant_key', 128)->unique();
            $table->string('source_module', 32);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->unsignedInteger('xp_amount')->default(0);
            $table->unsignedInteger('coin_amount')->default(0);
            $table->string('status', 32);
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->unsignedBigInteger('xp_ledger_id')->nullable();
            $table->unsignedBigInteger('coin_ledger_id')->nullable();
            $table->json('cap_detail')->nullable();
            $table->timestamps(3);

            $table->index(['child_id', 'policy_code']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->nullable()->constrained('child_profiles')->nullOnDelete();
            $table->string('category', 32)->nullable();
            $table->string('title', 128);
            $table->string('icon', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('kind', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('xp_reward')->default(0);
            $table->unsignedInteger('coin_reward')->default(0);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('requires_self_check')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['family_id', 'is_active']);
        });

        Schema::create('task_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 128);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_self_check')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('task_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->json('weekdays')->nullable();
            $table->json('dates')->nullable();
            $table->time('due_time')->nullable();
        });

        Schema::create('task_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('task_version');
            $table->date('task_date');
            $table->string('generation_key', 128)->unique();
            $table->json('snapshot');
            $table->string('status', 16);
            $table->dateTime('ready_at', 3)->nullable();
            $table->dateTime('first_step_at', 3)->nullable();
            $table->dateTime('submitted_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->boolean('self_checked')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(3);

            $table->index(['child_id', 'task_date']);
            $table->index(['family_id', 'task_date']);
        });

        Schema::create('task_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('task_step_id')->nullable();
            $table->string('title', 128);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_self_check')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('checked')->default(false);
            $table->dateTime('checked_at', 3)->nullable();
        });

        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->text('note')->nullable();
            $table->string('returned_reason', 500)->nullable();
            $table->dateTime('created_at', 3);

            $table->unique(['task_instance_id', 'attempt_no']);
        });

        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('title', 128);
            $table->string('icon', 32)->nullable();
            $table->string('frequency', 16)->default('daily');
            $table->unsignedSmallInteger('target_per_period')->default(1);
            $table->unsignedInteger('xp_reward')->default(0);
            $table->unsignedInteger('coin_reward')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);

            $table->index(['family_id', 'child_id']);
        });

        Schema::create('habit_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->date('record_date');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 3);

            $table->unique(['habit_id', 'record_date']);
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('name', 128);
            $table->unsignedBigInteger('image_attachment_id')->nullable();
            $table->text('description')->nullable();
            $table->string('kind', 32);
            $table->unsignedInteger('coin_price');
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['family_id', 'status']);
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('reward_id')->constrained()->restrictOnDelete();
            $table->json('reward_snapshot');
            $table->unsignedInteger('coin_price');
            $table->string('status', 16);
            $table->unsignedBigInteger('coin_hold_id')->nullable();
            $table->unsignedBigInteger('coin_ledger_id')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamps(3);

            $table->index(['child_id', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE xp_accounts ADD CONSTRAINT xp_accounts_balance_nonneg CHECK (balance >= 0)');
            DB::statement('ALTER TABLE coin_accounts ADD CONSTRAINT coin_accounts_balance_nonneg CHECK (balance >= 0)');
            DB::statement('ALTER TABLE coin_accounts ADD CONSTRAINT coin_accounts_held_le_balance CHECK (held <= balance)');

            foreach (['xp_ledger', 'coin_ledger', 'approval_events'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'");
            }
        }

        $this->backfillAccountsAndPolicies();
    }

    private function backfillAccountsAndPolicies(): void
    {
        foreach (DB::table('child_profiles')->get(['id', 'family_id']) as $row) {
            if (! DB::table('xp_accounts')->where('child_id', $row->id)->exists()) {
                DB::table('xp_accounts')->insert([
                    'family_id' => $row->family_id,
                    'child_id' => $row->id,
                    'balance' => 0,
                    'lifetime_earned' => 0,
                    'lock_version' => 0,
                    'updated_at' => now(),
                ]);
            }
            if (! DB::table('coin_accounts')->where('child_id', $row->id)->exists()) {
                DB::table('coin_accounts')->insert([
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
            app(\App\Modules\Ledger\Services\RewardPolicySeeder::class)->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['xp_ledger', 'coin_ledger', 'approval_events'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }

        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('habit_records');
        Schema::dropIfExists('habits');
        Schema::dropIfExists('task_submissions');
        Schema::dropIfExists('task_instance_steps');
        Schema::dropIfExists('task_instances');
        Schema::dropIfExists('task_schedules');
        Schema::dropIfExists('task_steps');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('reward_grants');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('reward_policies');
        Schema::dropIfExists('coin_holds');
        Schema::dropIfExists('coin_ledger');
        Schema::dropIfExists('xp_ledger');
        Schema::dropIfExists('coin_accounts');
        Schema::dropIfExists('xp_accounts');
    }
};
