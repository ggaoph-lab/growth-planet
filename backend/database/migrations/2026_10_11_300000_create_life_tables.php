<?php

use App\Modules\Item\Services\ItemLocationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('name', 64);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps(3);

            $table->index(['family_id', 'sort']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('name', 128);
            $table->string('category', 64)->nullable();
            $table->unsignedBigInteger('image_attachment_id')->nullable();
            $table->string('color', 32)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('location_id')->constrained('item_locations')->restrictOnDelete();
            $table->dateTime('location_confirmed_at', 3)->nullable();
            $table->string('status', 32)->default('normal');
            $table->boolean('carry_daily')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['family_id', 'child_id']);
        });

        Schema::create('item_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 32);
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->unsignedBigInteger('checklist_line_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at', 3);
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['item_id', 'occurred_at']);
        });

        Schema::create('item_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('kind', 32);
            $table->date('target_date');
            $table->string('status', 16)->default('open');
            $table->timestamps(3);

            $table->unique(['child_id', 'kind', 'target_date']);
        });

        Schema::create('item_checklist_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('result', 32)->default('pending');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['item_checklist_id', 'item_id']);
        });

        Schema::create('exploration_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('title', 128);
            $table->string('category', 32);
            $table->unsignedTinyInteger('current_stage')->default(1);
            $table->string('status', 16)->default('active');
            $table->timestamps(3);

            $table->index(['family_id', 'child_id', 'status']);
        });

        Schema::create('exploration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('exploration_projects')->cascadeOnDelete();
            $table->date('event_date');
            $table->unsignedTinyInteger('stage');
            $table->string('feeling', 32)->nullable();
            $table->string('willing_next', 16)->nullable();
            $table->text('child_words')->nullable();
            $table->text('parent_observation')->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(3);

            $table->index(['project_id', 'event_date']);
        });

        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('name', 128);
            $table->unsignedBigInteger('image_attachment_id')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('taste', 64)->nullable();
            $table->string('texture', 64)->nullable();
            $table->string('smell', 64)->nullable();
            $table->string('cooking_method', 64)->nullable();
            $table->timestamps(3);

            $table->index('family_id');
        });

        Schema::create('food_exploration_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('food_id')->constrained('foods')->restrictOnDelete();
            $table->date('record_date');
            $table->boolean('looked')->default(false);
            $table->boolean('smelled')->default(false);
            $table->boolean('touched')->default(false);
            $table->boolean('tasted_voluntarily')->default(false);
            $table->boolean('ate')->default(false);
            $table->unsignedTinyInteger('liking')->nullable();
            $table->boolean('said_tasty')->nullable();
            $table->boolean('willing_again')->nullable();
            $table->text('child_words')->nullable();
            $table->text('parent_observation')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(3);

            $table->index(['child_id', 'record_date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER item_events_no_update BEFORE UPDATE ON item_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_events is append-only'");
            DB::unprepared("CREATE TRIGGER item_events_no_delete BEFORE DELETE ON item_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'item_events is append-only'");
        }

        $this->seedDefaultItemLocations();
    }

    private function seedDefaultItemLocations(): void
    {
        $seeder = app(ItemLocationSeeder::class);
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $seeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS item_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS item_events_no_delete');
        }

        Schema::dropIfExists('food_exploration_records');
        Schema::dropIfExists('foods');
        Schema::dropIfExists('exploration_events');
        Schema::dropIfExists('exploration_projects');
        Schema::dropIfExists('item_checklist_lines');
        Schema::dropIfExists('item_checklists');
        Schema::dropIfExists('item_events');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_locations');
    }
};
