<?php

namespace Tests\Feature\Growth;

use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskCategory;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Services\TaskInstanceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_tasks_are_generated_for_child_only_on_scheduled_weekdays(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '周日阅读',
            'kind' => 'standard',
            'start_date' => '2026-10-11',
            'child_id' => $child->id,
            'schedules' => [['frequency' => 'weekly', 'weekdays' => [7]]],
        ])->assertCreated();

        $generator = app(TaskInstanceGenerator::class);
        $sunday = $generator->ensureForChildOnDate($family, $child, '2026-10-11');
        $this->assertCount(1, $sunday);
        $this->assertSame('周日阅读', $sunday->first()->snapshot['title']);
        $this->assertCount(0, $generator->ensureForChildOnDate($family, $child, '2026-10-12'));
    }

    public function test_parent_can_create_task_via_api(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '阅读',
            'kind' => 'standard',
            'start_date' => '2026-10-01',
            'schedules' => [['frequency' => 'daily']],
        ])->assertCreated()
            ->assertJsonPath('data.title', '阅读')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.with_parent', false);
    }

    public function test_parent_can_mark_task_with_parent(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '一起整理书包',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'with_parent' => true,
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()
            ->assertJsonPath('data.with_parent', true)
            ->json('data');

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk();

        $instance = TaskInstance::query()->where('task_id', $created['id'])->first();
        $this->assertNotNull($instance);
        $this->assertTrue((bool) ($instance->snapshot['with_parent'] ?? false));
    }

    public function test_parent_can_create_weekly_task_with_time_and_end_date(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $response = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '钢琴练习',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-31',
            'xp_reward' => 8,
            'coin_reward' => 4,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1],
                'due_time' => '13:20',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.title', '钢琴练习')
            ->assertJsonPath('data.schedules.0.frequency', 'weekly')
            ->assertJsonPath('data.schedules.0.weekdays.0', 1);

        $endDate = (string) $response->json('data.end_date');
        $this->assertTrue(str_starts_with($endDate, '2026-12-31'), 'end_date should be 2026-12-31, got '.$endDate);
        $dueTime = (string) $response->json('data.schedules.0.due_time');
        $this->assertTrue(str_starts_with($dueTime, '13:20'), 'due_time should start with 13:20, got '.$dueTime);
    }

    public function test_parent_can_update_and_delete_task_including_steps(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '旧标题',
            'kind' => 'stepped',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'steps' => [
                ['title' => '步骤一'],
                ['title' => '步骤二'],
            ],
            'schedules' => [['frequency' => 'once', 'due_time' => '09:00']],
        ])->assertCreated()->json('data');

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk();

        $updated = $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '新标题',
            'xp_reward' => 20,
            'steps' => [
                ['title' => '新步骤A'],
                ['title' => '新步骤B'],
                ['title' => '新步骤C'],
            ],
            'schedules' => [['frequency' => 'once', 'due_time' => '15:30']],
        ])->assertOk()
            ->assertJsonPath('data.title', '新标题')
            ->assertJsonPath('data.xp_reward', 20)
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.0.title', '新步骤A')
            ->json('data');

        $dueTime = (string) data_get($updated, 'schedules.0.due_time');
        $this->assertTrue(str_starts_with($dueTime, '15:30'), 'due_time should start with 15:30, got '.$dueTime);

        $instance = TaskInstance::query()->where('task_id', $created['id'])->first();
        $this->assertNotNull($instance);
        $this->assertSame(TaskInstanceStatus::Pending, $instance->status);
        $this->assertSame('新标题', $instance->snapshot['title']);
        $this->assertSame(20, $instance->snapshot['xp_reward']);
        $this->assertCount(3, $instance->snapshot['steps']);
        $this->assertSame($updated['version'], $instance->task_version);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk()
            ->assertJsonCount(3, 'data.0.instance_steps')
            ->assertJsonPath('data.0.instance_steps.0.title', '新步骤A')
            ->assertJsonPath('data.0.snapshot.steps.0.title', '新步骤A');

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/tasks/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('tasks', ['id' => $created['id']]);
    }

    public function test_subtasks_fold_under_one_title_and_keep_their_own_times(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $categoryId = $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '学习',
        ])->assertCreated()->json('data.id');

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '下午安排',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'category_id' => $categoryId,
            'xp_reward' => 0,
            'coin_reward' => 0,
            'subtasks' => [
                [
                    'title' => '练琴',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-10-12',
                    'xp_reward' => 6,
                    'coin_reward' => 2,
                    'with_parent' => true,
                    'schedules' => [['frequency' => 'once', 'due_time' => '14:00']],
                ],
                [
                    'title' => '阅读',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-12-31',
                    'xp_reward' => 8,
                    'coin_reward' => 3,
                    'with_parent' => false,
                    'schedules' => [[
                        'frequency' => 'weekly',
                        'weekdays' => [1],
                        'due_time' => '15:30',
                    ]],
                ],
            ],
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()
            ->assertJsonPath('data.title', '下午安排')
            ->assertJsonPath('data.category_id', $categoryId)
            ->assertJsonPath('data.task_category.name', '学习')
            ->assertJsonCount(2, 'data.children')
            ->json('data');

        $piano = collect($created['children'])->firstWhere('title', '练琴');
        $reading = collect($created['children'])->firstWhere('title', '阅读');
        $this->assertNotNull($piano);
        $this->assertNotNull($reading);
        $this->assertSame(6, $piano['xp_reward']);
        $this->assertSame(2, $piano['coin_reward']);
        $this->assertTrue((bool) $piano['with_parent']);
        $this->assertSame(8, $reading['xp_reward']);
        $this->assertSame('weekly', $reading['schedules'][0]['frequency'] ?? null);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '下午安排')
            ->assertJsonPath('data.0.children.0.title', '练琴');

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $instances = TaskInstance::query()->orderBy('id')->get();
        $this->assertCount(2, $instances);
        $this->assertFalse($instances->contains('task_id', $created['id']));
        $titles = $instances->map(fn ($instance) => $instance->snapshot['title'])->sort()->values()->all();
        $this->assertSame(['练琴', '阅读'], $titles);
        $this->assertTrue($instances->every(fn ($instance) => ($instance->snapshot['group_title'] ?? null) === '下午安排'));
        $this->assertTrue($instances->every(fn ($instance) => ($instance->snapshot['group_task_id'] ?? null) === $created['id']));

        $pianoInstance = $instances->firstWhere(fn ($instance) => $instance->snapshot['title'] === '练琴');
        $this->assertNotNull($pianoInstance);
        $this->assertTrue((bool) ($pianoInstance->snapshot['with_parent'] ?? false));
        $this->assertSame(6, $pianoInstance->snapshot['xp_reward'] ?? null);

        $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '放学后',
            'category_id' => $categoryId,
            'subtasks' => [
                [
                    'id' => $reading['id'],
                    'title' => '阅读',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-12-31',
                    'xp_reward' => 8,
                    'coin_reward' => 3,
                    'with_parent' => false,
                    'schedules' => [['frequency' => 'once', 'due_time' => '16:00']],
                ],
                [
                    'title' => '运动',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-10-12',
                    'xp_reward' => 5,
                    'coin_reward' => 1,
                    'schedules' => [['frequency' => 'once', 'due_time' => '17:00']],
                ],
            ],
            'schedules' => [['frequency' => 'once']],
        ])->assertOk()
            ->assertJsonPath('data.title', '放学后')
            ->assertJsonCount(2, 'data.children');

        $this->assertSoftDeleted('tasks', ['id' => $piano['id']]);

        $readingInstance = TaskInstance::query()->where('task_id', $reading['id'])->first();
        $this->assertNotNull($readingInstance);
        $this->assertSame(TaskInstanceStatus::Pending, $readingInstance->status);
        $this->assertSame('阅读', $readingInstance->snapshot['title']);
        $this->assertSame('放学后', $readingInstance->snapshot['group_title']);
        $this->assertTrue(str_starts_with((string) $readingInstance->snapshot['due_time'], '16:00'));

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/tasks/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $created['id']]);
        $this->assertSoftDeleted('tasks', ['id' => $reading['id']]);
    }

    public function test_task_categories_can_be_renamed_and_deleted(): void
    {
        ['parent' => $parent] = $this->makeFamily('mama_cat');

        $id = $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '家务',
        ])->assertCreated()
            ->assertJsonPath('data.name', '家务')
            ->json('data.id');

        $this->actingAsParent($parent)->putJson('/api/v1/parent/task-categories/'.$id, [
            'name' => '劳动',
        ])->assertOk()->assertJsonPath('data.name', '劳动');

        $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '劳动',
        ])->assertStatus(422);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '扫地',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'category_id' => $id,
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()->assertJsonPath('data.task_category.name', '劳动');

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/task-categories/'.$id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.category_id', null)
            ->assertJsonPath('data.0.task_category', null);

        $this->assertDatabaseMissing('task_categories', ['id' => $id]);
    }

    public function test_another_family_cannot_use_a_task_category(): void
    {
        ['family' => $familyA] = $this->makeFamily('mama_cat_a');
        ['parent' => $parentB] = $this->makeFamily('mama_cat_b');

        $category = TaskCategory::query()->create([
            'family_id' => $familyA->id,
            'name' => '家务',
            'sort' => 1,
        ]);

        $this->actingAsParent($parentB)->getJson('/api/v1/parent/task-categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsParent($parentB)->putJson('/api/v1/parent/task-categories/'.$category->id, [
            'name' => '偷看',
        ])->assertNotFound();

        $this->actingAsParent($parentB)->postJson('/api/v1/parent/tasks', [
            'title' => '越权',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'category_id' => $category->id,
            'schedules' => [['frequency' => 'once']],
        ])->assertStatus(422);
    }

    public function test_weekly_schedule_requires_weekdays(): void
    {
        ['parent' => $parent] = $this->makeFamily('mama_weekly_req');

        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '缺星期',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-31',
            'schedules' => [['frequency' => 'weekly', 'weekdays' => []]],
        ])->assertStatus(422);
    }

    public function test_multi_weekday_weekly_task_generates_on_each_selected_day(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily('mama_multi_wd');
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '周一三练琴',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-31',
            'child_id' => $child->id,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1, 3],
                'due_time' => '16:00',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.schedules.0.weekdays', [1, 3]);

        $generator = app(TaskInstanceGenerator::class);
        // 2026-10-12 Mon, 2026-10-13 Tue, 2026-10-14 Wed
        $this->assertCount(1, $generator->ensureForChildOnDate($family, $child, '2026-10-12'));
        $this->assertCount(0, $generator->ensureForChildOnDate($family, $child, '2026-10-13'));
        $this->assertCount(1, $generator->ensureForChildOnDate($family, $child, '2026-10-14'));
    }

    public function test_edit_scope_this_day_patches_only_that_pending_instance(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily('mama_this_day');
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '阅读',
            'kind' => 'standard',
            'start_date' => '2026-10-06',
            'end_date' => '2026-12-31',
            'child_id' => $child->id,
            'xp_reward' => 5,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1],
                'due_time' => '15:00',
            ]],
        ])->assertCreated()->json('data');

        $generator = app(TaskInstanceGenerator::class);
        $generator->ensureForChildOnDate($family, $child, '2026-10-12'); // Mon
        $generator->ensureForChildOnDate($family, $child, '2026-10-19'); // next Mon

        $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '加长阅读',
            'xp_reward' => 20,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1],
                'due_time' => '17:30',
            ]],
            'edit_scope' => 'this_day',
            'scope_date' => '2026-10-12',
        ])->assertOk()
            ->assertJsonPath('data.title', '阅读');

        $first = TaskInstance::query()
            ->where('task_id', $created['id'])
            ->whereDate('task_date', '2026-10-12')
            ->first();
        $second = TaskInstance::query()
            ->where('task_id', $created['id'])
            ->whereDate('task_date', '2026-10-19')
            ->first();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('加长阅读', $first->snapshot['title']);
        $this->assertSame(20, $first->snapshot['xp_reward']);
        $this->assertTrue(str_starts_with((string) $first->snapshot['due_time'], '17:30'));
        $this->assertSame('阅读', $second->snapshot['title']);
        $this->assertSame(5, $second->snapshot['xp_reward']);
    }

    public function test_edit_scope_weekday_forward_updates_template_and_same_weekday_pending(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily('mama_wd_fwd');
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '作业',
            'kind' => 'standard',
            'start_date' => '2026-10-05',
            'end_date' => '2026-12-31',
            'child_id' => $child->id,
            'xp_reward' => 4,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1, 3],
                'due_time' => '14:00',
            ]],
        ])->assertCreated()->json('data');

        $generator = app(TaskInstanceGenerator::class);
        // Mon 10-12, Wed 10-14, Mon 10-19
        $generator->ensureForChildOnDate($family, $child, '2026-10-12');
        $generator->ensureForChildOnDate($family, $child, '2026-10-14');
        $generator->ensureForChildOnDate($family, $child, '2026-10-19');

        $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '新作业',
            'xp_reward' => 9,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1, 3],
                'due_time' => '18:00',
            ]],
            'edit_scope' => 'weekday_forward',
            'scope_date' => '2026-10-12', // Monday
        ])->assertOk()
            ->assertJsonPath('data.title', '新作业')
            ->assertJsonPath('data.xp_reward', 9);

        $monFirst = TaskInstance::query()->where('task_id', $created['id'])->whereDate('task_date', '2026-10-12')->first();
        $wed = TaskInstance::query()->where('task_id', $created['id'])->whereDate('task_date', '2026-10-14')->first();
        $monLater = TaskInstance::query()->where('task_id', $created['id'])->whereDate('task_date', '2026-10-19')->first();

        $this->assertNotNull($monFirst);
        $this->assertNotNull($wed);
        $this->assertNotNull($monLater);
        $this->assertSame('新作业', $monFirst->snapshot['title']);
        $this->assertSame(9, $monFirst->snapshot['xp_reward']);
        $this->assertSame('新作业', $monLater->snapshot['title']);
        $this->assertSame('作业', $wed->snapshot['title']);
        $this->assertSame(4, $wed->snapshot['xp_reward']);
    }

    public function test_weekday_forward_removes_pending_for_dropped_weekdays(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily('mama_drop_wd');
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '训练',
            'kind' => 'standard',
            'start_date' => '2026-10-05',
            'end_date' => '2026-12-31',
            'child_id' => $child->id,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1, 3],
            ]],
        ])->assertCreated()->json('data');

        $generator = app(TaskInstanceGenerator::class);
        $generator->ensureForChildOnDate($family, $child, '2026-10-12'); // Mon
        $generator->ensureForChildOnDate($family, $child, '2026-10-14'); // Wed

        $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '训练',
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1],
            ]],
            'edit_scope' => 'weekday_forward',
            'scope_date' => '2026-10-12',
        ])->assertOk()
            ->assertJsonPath('data.schedules.0.weekdays', [1]);

        $this->assertNotNull(
            TaskInstance::query()->where('task_id', $created['id'])->whereDate('task_date', '2026-10-12')->first()
        );
        $this->assertNull(
            TaskInstance::query()->where('task_id', $created['id'])->whereDate('task_date', '2026-10-14')->first()
        );
    }
}
