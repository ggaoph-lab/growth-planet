<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('title', 128)->default('本学期课表');
            $table->unsignedTinyInteger('period_count')->default(6);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['child_id', 'effective_from']);
        });

        Schema::create('school_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO 1=Mon … 7=Sun
            $table->unsignedTinyInteger('period_index');
            $table->string('subject', 64);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('room', 64)->nullable();
            $table->timestamps(3);

            $table->unique(['school_schedule_id', 'weekday', 'period_index'], 'school_periods_slot_unique');
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->nullable()->constrained('child_profiles')->nullOnDelete();
            $table->date('exception_date');
            $table->string('type', 32); // no_school | makeup_day | custom
            $table->unsignedTinyInteger('follow_weekday')->nullable();
            $table->json('custom_periods')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['family_id', 'exception_date']);
            $table->index(['child_id', 'exception_date']);
        });

        Schema::create('subject_item_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('subject', 64);
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps(3);

            $table->unique(['child_id', 'subject', 'item_id'], 'subject_item_req_unique');
        });

        Schema::create('bag_extra_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->date('target_date');
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 128)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('note', 255)->nullable();
            $table->timestamps(3);

            $table->index(['child_id', 'target_date']);
        });

        Schema::table('item_checklists', function (Blueprint $table) {
            $table->json('generated_from')->nullable()->after('status');
        });

        Schema::table('item_checklist_lines', function (Blueprint $table) {
            $table->json('sources')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('item_checklist_lines', function (Blueprint $table) {
            $table->dropColumn('sources');
        });

        Schema::table('item_checklists', function (Blueprint $table) {
            $table->dropColumn('generated_from');
        });

        Schema::dropIfExists('bag_extra_requirements');
        Schema::dropIfExists('subject_item_requirements');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('school_periods');
        Schema::dropIfExists('school_schedules');
    }
};
